<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Call;
use App\Models\Company;
use App\Models\IntegrationConnection;
use App\Models\RmiAlert;
use App\Models\RmiAnalysis;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\Workspaces;
use App\Support\Audit;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SmartRMI (recovery monitoring & intelligence). The analysis runs in the SmartRMI engine, as for SmartDCM: the engine sends
 * rmi_status + rmi_results per call (SmartDCM s.py store_rmi_analysis — flat or nested under "rmi") and an angry-call alert
 * (SmartDCM /api/angry-call-alert → mail to the agent's managers). ROVEXY keeps the results as sent, pulls out the common
 * fields for lists and reports, and mails each alert to the agent's team supervisors.
 */
final class SmartRmi
{
    public const SYSTEM = 'smartrmi';

    public static function connection(): ?IntegrationConnection
    {
        return IntegrationConnection::query()->where('system', self::SYSTEM)->first();
    }

    /** Store (or replace) the engine's analysis of a call. */
    public function ingest(Call $call, array $p): RmiAnalysis
    {
        $status = $p['rmi_status'] ?? ($p['rmi']['status'] ?? ($p['status'] ?? 'done'));
        $res = $p['rmi_results'] ?? ($p['rmi']['results'] ?? ($p['results'] ?? []));
        if (is_string($res)) {
            $res = json_decode($res, true) ?: ['text' => $res];
        }
        $res = is_array($res) ? $res : [];
        $score = self::pick($res, ['score', 'overall_score', 'total_score', 'quality_score', 'call_score', 'rmi_score']);
        $aggression = self::text(self::pick($res, ['aggression', 'aggression_level', 'aggressiveness']));
        $angry = self::pick($res, ['angry', 'is_angry', 'angry_call', 'red_alert']);
        $angry = $angry !== null ? filter_var($angry, FILTER_VALIDATE_BOOLEAN) : in_array(strtolower((string) $aggression), ['high', 'severe', 'very high'], true);
        $a = RmiAnalysis::query()->updateOrCreate(['call_id' => $call->id], [
            'user_id' => $call->user_id,
            'status' => mb_substr((string) (is_scalar($status) ? $status : 'done'), 0, 24),
            'score' => self::score($score),
            'tone' => self::short(self::pick($res, ['tone', 'agent_tone', 'call_tone'])),
            'sentiment' => self::short(self::pick($res, ['sentiment', 'customer_sentiment', 'overall_sentiment'])),
            'aggression' => self::short($aggression),
            'compliance_risk' => self::short(self::pick($res, ['compliance_risk', 'compliance', 'risk', 'risk_level', 'compliance_level'])),
            'angry' => $angry,
            'summary' => self::text(self::pick($res, ['summary', 'call_summary', 'overview'])),
            'transcript' => self::transcript(self::pick($res, ['transcript', 'transcription', 'conversation', 'segments'])),
            'coaching' => self::lines(self::pick($res, ['coaching', 'coaching_points', 'coaching_insights', 'suggestions', 'recommendations', 'improvements'])),
            'results' => $res,
            'analysed_at' => now(),
        ]);
        if ($angry) {
            $this->alert($call, ['kind' => 'angry_call', 'message' => $a->summary ? Str::limit($a->summary, 480) : 'SmartRMI marked this call as angry.', 'source' => 'analysis']);
        }
        return $a;
    }

    /** An angry-call / risk alert: kept for the console and mailed to the agent's team supervisors (once per call and kind). */
    public function alert(?Call $call, array $p): RmiAlert
    {
        $kind = in_array($p['kind'] ?? null, ['angry_call', 'compliance_risk', 'other'], true) ? $p['kind'] : 'angry_call';
        if ($call && ($old = RmiAlert::query()->where('call_id', $call->id)->where('kind', $kind)->first())) {
            return $old;   // the engine may send both an analysis marked angry and an alert for the same call
        }
        $details = array_intersect_key($p, array_flip(['a_start_time', 'b_party_no', 'duration', 'lead_id', 'user_id', 'reason', 'score', 'source']));
        if (isset($details['b_party_no'])) {
            $details['b_party_no'] = \App\Domain\Customers\PhoneNormaliser::mask((string) $details['b_party_no']);
        }
        $alert = RmiAlert::query()->create([
            'call_id' => $call?->id, 'user_id' => $call?->user_id,
            'kind' => $kind, 'severity' => in_array($p['severity'] ?? null, ['low', 'medium', 'high'], true) ? $p['severity'] : 'high',
            'message' => isset($p['message']) ? mb_substr((string) $p['message'], 0, 500) : null, 'details' => $details,
        ]);
        $alert->forceFill(['emailed_to' => $this->mail($alert)])->save();
        Audit::record('rmi.alert', $alert, ['call_id' => $call?->id, 'kind' => $kind, 'emailed_to' => $alert->emailed_to]);
        return $alert;
    }

    /** People who get an alert by e-mail: supervisors of the agent's teams (as SmartDCM's reporting managers) + extra addresses. */
    public function recipients(RmiAlert $alert): array
    {
        $cfg = (array) (app(CurrentCompany::class)->get()?->setting('smartrmi') ?? []);
        $to = [];
        if (($cfg['notify_supervisors'] ?? true) && $alert->user_id) {
            $teams = DB::table('team_user')->where('user_id', $alert->user_id)->pluck('team_id');
            $ids = DB::table('team_user')->whereIn('team_id', $teams)->where('membership', 'supervisor')->where('user_id', '!=', $alert->user_id)->pluck('user_id');
            $to = User::query()->whereIn('id', $ids)->where('status', 'active')->whereNotNull('email')->pluck('email')->all();
        }
        foreach ((array) ($cfg['alert_emails'] ?? []) as $e) {
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $to[] = $e;
            }
        }
        return array_values(array_unique(array_map('strtolower', $to)));
    }

    private function mail(RmiAlert $alert): int
    {
        $to = $this->recipients($alert);
        if (!$to || !PlatformSettings::mailReady()) {
            return 0;
        }
        $company = app(CurrentCompany::class)->get();
        $call = $alert->call()->with(['user:id,name,employee_code', 'customer:id,customer_ref,name', 'recording'])->first();
        $e = fn ($v) => e((string) $v);
        $rows = [
            'Agent' => $call?->user ? $call->user->name . ($call->user->employee_code ? ' (' . $call->user->employee_code . ')' : '') : '—',
            'Customer' => $call?->customer ? trim(($call->customer->name ?? '') . ' · ' . ($call->customer->customer_ref ?? ''), ' ·') : '—',
            'Number' => $call ? \App\Domain\Customers\PhoneNormaliser::mask($call->destination) : ($alert->details['b_party_no'] ?? '—'),
            'Call time' => $call?->initiated_at?->tz(CurrentCompany::tz())->format('d M Y, h:i A') ?? ($alert->details['a_start_time'] ?? '—'),
            'Talk time' => $call ? gmdate('i:s', (int) $call->talk_seconds) : ($alert->details['duration'] ?? '—'),
        ];
        $link = $company && $call ? Workspaces::consoleUrl($company) . '/calls?call=' . $call->id : null;
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1f2937"><h2 style="color:#b91c1c;margin:0 0 8px">'
            . ($alert->kind === 'angry_call' ? 'Angry call detected' : 'Call needs attention') . '</h2>'
            . ($alert->message ? '<p>' . $e($alert->message) . '</p>' : '') . '<table cellpadding="6" style="border-collapse:collapse">'
            . implode('', array_map(fn ($k, $v) => '<tr><td style="color:#6b7280">' . $e($k) . '</td><td><b>' . $e($v) . '</b></td></tr>', array_keys($rows), $rows))
            . '</table>' . ($link ? '<p><a href="' . $e($link) . '">Open the call in ROVEXY</a></p>' : '') . '<p style="color:#6b7280;font-size:12px">Sent by SmartRMI in ROVEXY.</p></div>';
        $rec = $call?->recording;
        try {
            Mail::html($html, function ($m) use ($to, $alert, $call, $rec) {
                $m->to($to)->subject('RED ALERT: ' . ($alert->kind === 'angry_call' ? 'Angry call detected' : 'Call needs attention') . ($call?->user ? ' — ' . $call->user->name : ''));
                $disk = Storage::disk(config('ammp.recordings.disk'));
                if ($rec && $rec->status === 'available' && $rec->object_key && ($rec->size_bytes ?? 0) <= 10 * 1024 * 1024 && $disk->exists($rec->object_key)) {
                    $m->attachData($disk->get($rec->object_key), 'call-recording.' . ($rec->format ?: 'mp3'), ['mime' => $rec->mime ?: 'audio/mpeg']);
                }
            });
        } catch (\Throwable $ex) {
            report($ex);
            return 0;
        }
        return count($to);
    }

    /** 0–100; an engine that scores 0–1 (e.g. 0.82) is scaled up. */
    private static function score(mixed $v): ?float
    {
        if (!is_numeric($v)) {
            return null;
        }
        $f = (float) $v;
        return round(max(0.0, min(100.0, $f > 0 && $f < 1 ? $f * 100 : $f)), 1);
    }

    /** First value found for any of the keys, at the top level or one level down (engines nest results differently). */
    private static function pick(array $r, array $keys): mixed
    {
        $lower = array_change_key_case($r);
        foreach ($keys as $k) {
            if (array_key_exists($k, $lower) && $lower[$k] !== null && $lower[$k] !== '') {
                return $lower[$k];
            }
        }
        foreach ($lower as $v) {
            if (is_array($v) && !array_is_list($v)) {
                $found = self::pick($v, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private static function text(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_array($v)) {
            $v = $v['label'] ?? $v['level'] ?? $v['value'] ?? $v['text'] ?? json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        return is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v;
    }

    private static function short(mixed $v): ?string
    {
        $t = self::text($v);
        return $t === null ? null : mb_substr($t, 0, 40);
    }

    private static function transcript(mixed $v): ?string
    {
        if (is_array($v)) {
            return implode("\n", array_map(fn ($s) => is_array($s)
                ? trim(($s['speaker'] ?? $s['role'] ?? '') . ': ' . ($s['text'] ?? $s['content'] ?? ''), ': ')
                : (string) $s, $v));
        }
        return $v === null ? null : (string) $v;
    }

    private static function lines(mixed $v): ?array
    {
        if ($v === null) {
            return null;
        }
        $list = is_array($v) ? (array_is_list($v) ? $v : array_values($v)) : preg_split('/\r?\n|;\s*/', (string) $v);
        return array_values(array_filter(array_map(fn ($x) => mb_substr(trim(self::text($x) ?? ''), 0, 300), $list)));
    }

    /** The company for an engine request: the connection's uuid is in the URL, its secret in X-Api-Key. */
    public static function resolve(string $uuid, string $key): array
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/', $uuid) === 1, 404);
        $conn = app(CurrentCompany::class)->withoutScope(fn () => IntegrationConnection::query()->where('uuid', $uuid)->where('system', self::SYSTEM)->where('enabled', true)->first());
        abort_if($conn === null || !$conn->inbound_secret, 404);
        abort_unless(hash_equals((string) $conn->inbound_secret, $key), 401, 'Bad key.');
        return [$conn, Company::query()->whereKey($conn->company_id)->where('status', 'active')->firstOrFail()];
    }
}
