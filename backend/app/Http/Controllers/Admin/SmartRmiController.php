<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Customers\PhoneNormaliser;
use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Models\RmiAlert;
use App\Models\RmiAnalysis;
use App\Services\SmartRmi;
use App\Support\Audit;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Call monitoring → SmartRMI: overview, analysed calls, angry-call alerts, and the engine connection. */
final class SmartRmiController extends Controller
{
    private function analyses(Request $r)
    {
        $users = $r->user()->usersInView();
        $tz = CurrentCompany::tz();
        return RmiAnalysis::query()->when($users !== null, fn ($q) => $q->whereIn('user_id', $users))
            ->when($r->query('from'), fn ($q, $v) => $q->where('analysed_at', '>=', Carbon::parse($v, $tz)->startOfDay()->utc()))
            ->when($r->query('to'), fn ($q, $v) => $q->where('analysed_at', '<', Carbon::parse($v, $tz)->addDay()->startOfDay()->utc()))
            ->when($r->query('user_id'), fn ($q, $v) => $q->where('user_id', $v));
    }

    public function overview(Request $r)
    {
        $base = $this->analyses($r);
        $tot = (clone $base)->selectRaw('COUNT(*) n, AVG(score) avg_score, SUM(CASE WHEN angry THEN 1 ELSE 0 END) angry')->first();
        $risk = (clone $base)->whereIn(DB::raw('LOWER(compliance_risk)'), ['high', 'severe', 'critical'])->count();
        $agents = (clone $base)->whereNotNull('user_id')->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) n, AVG(score) avg_score, SUM(CASE WHEN angry THEN 1 ELSE 0 END) angry')->orderByDesc('n')->limit(100)->get();
        $names = DB::table('users')->whereIn('id', $agents->pluck('user_id'))->pluck('name', 'id');
        $users = $r->user()->usersInView();
        $open = RmiAlert::query()->whereNull('acknowledged_at')->when($users !== null, fn ($q) => $q->whereIn('user_id', $users))->count();
        return response()->json([
            'analysed' => (int) $tot->n, 'avg_score' => $tot->avg_score !== null ? round((float) $tot->avg_score, 1) : null,
            'angry' => (int) $tot->angry, 'high_risk' => $risk, 'open_alerts' => $open,
            'tones' => (clone $base)->whereNotNull('tone')->groupBy('tone')->selectRaw('tone, COUNT(*) n')->orderByDesc('n')->limit(8)->pluck('n', 'tone'),
            'agents' => $agents->map(fn ($a) => ['user_id' => $a->user_id, 'name' => $names[$a->user_id] ?? '—', 'analysed' => (int) $a->n,
                'avg_score' => $a->avg_score !== null ? round((float) $a->avg_score, 1) : null, 'angry' => (int) $a->angry]),
        ]);
    }

    public function calls(Request $r)
    {
        return response()->json($this->analyses($r)
            ->when($r->boolean('angry'), fn ($q) => $q->where('angry', true))
            ->when($r->query('risk'), fn ($q, $v) => $q->where(DB::raw('LOWER(compliance_risk)'), strtolower((string) $v)))
            ->when($r->query('max_score') !== null && $r->query('max_score') !== '', fn ($q) => $q->where('score', '<=', (float) $r->query('max_score')))
            ->with(['user:id,name,employee_code', 'call:id,customer_id,destination,initiated_at,talk_seconds,provider,recording_status', 'call.customer:id,customer_ref,name'])
            ->latest('analysed_at')->paginate(50)
            ->through(fn (RmiAnalysis $a) => collect($a->toArray())->except(['transcript', 'results'])->all()
                + ['destination_masked' => PhoneNormaliser::mask((string) $a->call?->destination)]));
    }

    /** The analysis of one call (also shown as the SmartRMI card on the call). */
    public function show(Request $r, int $callId)
    {
        $users = $r->user()->usersInView();
        $a = RmiAnalysis::query()->where('call_id', $callId)->when($users !== null, fn ($q) => $q->whereIn('user_id', $users))
            ->with(['user:id,name', 'call:id,customer_id,initiated_at,talk_seconds', 'call.customer:id,customer_ref,name'])->firstOrFail();
        return response()->json($a);
    }

    public function alerts(Request $r)
    {
        $users = $r->user()->usersInView();
        return response()->json(RmiAlert::query()->when($users !== null, fn ($q) => $q->whereIn('user_id', $users))
            ->when($r->query('status', 'open') === 'open', fn ($q) => $q->whereNull('acknowledged_at'))
            ->with(['user:id,name,employee_code', 'acknowledger:id,name', 'call:id,customer_id,initiated_at,talk_seconds', 'call.customer:id,customer_ref,name'])
            ->latest()->paginate(50));
    }

    public function acknowledge(Request $r, int $id)
    {
        $users = $r->user()->usersInView();
        $a = RmiAlert::query()->when($users !== null, fn ($q) => $q->whereIn('user_id', $users))->findOrFail($id);
        if (!$a->acknowledged_at) {
            $a->forceFill(['acknowledged_by' => $r->user()->id, 'acknowledged_at' => now()])->save();
            Audit::record('rmi.alert_acknowledged', $a, ['call_id' => $a->call_id]);
        }
        return response()->json($a->fresh(['acknowledger:id,name']));
    }

    /** Engine connection + alert options (SmartRMI → Settings). */
    public function settings(Request $r)
    {
        $c = SmartRmi::connection();
        $cfg = (array) (app(CurrentCompany::class)->get()->setting('smartrmi') ?? []);
        $base = $c?->uuid ? url('/api/integrations/smartrmi/' . $c->uuid) : null;
        return response()->json([
            'licensed' => app(\App\Services\Platform\Licensing::class)->has('smartrmi'),
            'connected' => (bool) $c?->enabled, 'has_secret' => (bool) $c?->inbound_secret, 'last_ok_at' => $c?->last_ok_at, 'last_status' => $c?->last_status,
            'endpoints' => $base ? ['calls' => $base . '/calls', 'recording' => $base . '/recordings/{call_uuid}', 'results' => $base . '/results', 'alerts' => $base . '/alerts'] : null,
            'notify_supervisors' => (bool) ($cfg['notify_supervisors'] ?? true), 'alert_emails' => array_values((array) ($cfg['alert_emails'] ?? [])),
        ]);
    }

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['enabled' => 'boolean', 'notify_supervisors' => 'boolean', 'alert_emails' => 'array|max:20', 'alert_emails.*' => 'email:rfc|max:190']);
        $company = app(CurrentCompany::class)->get();
        $s = $company->settings ?? [];
        $s['smartrmi'] = ['notify_supervisors' => $d['notify_supervisors'] ?? true, 'alert_emails' => array_values(array_unique($d['alert_emails'] ?? []))];
        $company->forceFill(['settings' => $s])->save();
        if (array_key_exists('enabled', $d) && ($c = SmartRmi::connection())) {
            $c->forceFill(['enabled' => $d['enabled']])->save();
        }
        Audit::record('rmi.settings', $company, ['notify_supervisors' => $s['smartrmi']['notify_supervisors'], 'alert_emails' => count($s['smartrmi']['alert_emails'])]);
        return $this->settings($r);
    }

    /** Create (or replace) the engine's secret key. Shown once. */
    public function secret(Request $r)
    {
        $secret = Str::random(48);
        $c = SmartRmi::connection() ?? new IntegrationConnection(['system' => SmartRmi::SYSTEM, 'base_url' => '', 'api_key' => '', 'uuid' => (string) Str::uuid()]);
        $c->forceFill(['inbound_secret' => $secret, 'enabled' => true, 'uuid' => $c->uuid ?: (string) Str::uuid()])->save();
        Audit::record('rmi.secret', $c, []);
        return response()->json(['secret' => $secret] + $this->settings($r)->getData(true));
    }
}
