<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Domain\Calling\RecordingState;
use App\Http\Controllers\Controller;
use App\Jobs\SyncSmartDcm;
use App\Models\Call;
use App\Models\Company;
use App\Models\IntegrationConnection;
use App\Models\Recording;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * SmartDCM → ROVEXY (no user session; the connection's uuid is in the URL, its inbound secret proves the caller):
 *   POST /api/integrations/smartdcm/{uuid}/webhook        "something changed" — signed; ROVEXY then reads it from SmartDCM.
 *        Headers X-Rovexy-Timestamp (unix seconds) and X-Rovexy-Signature = hex HMAC-SHA256(timestamp + "." + raw body, secret).
 *        The body is never trusted as data: it only says which lists to read again.
 *   GET  /api/integrations/smartdcm/{uuid}/recordings/{call}   a call's recording (header X-Api-Key: the secret).
 */
final class SmartDcmHookController extends Controller
{
    public function webhook(Request $r, string $uuid)
    {
        [$conn, $company] = $this->resolve($uuid);
        $ts = (int) $r->header('X-Rovexy-Timestamp', '0');
        $sig = (string) $r->header('X-Rovexy-Signature', '');
        $want = hash_hmac('sha256', $ts . '.' . $r->getContent(), (string) $conn->inbound_secret);
        abort_unless(abs(time() - $ts) <= 300 && hash_equals($want, strtolower($sig)), 401, 'Bad signature.');
        // ponytail: one sync per company per 20 s however many events SmartDCM sends (a portfolio upload sends many).
        if (Cache::add("smartdcm-hook:{$company->id}", 1, 20)) {
            app(CurrentCompany::class)->run($company, fn () => \App\Support\Audit::record('smartdcm.webhook', $conn, ['event' => mb_substr((string) $r->input('event'), 0, 60)]));
            SyncSmartDcm::dispatch($company->id);   // on the queue, never inside this web request
        }
        return response()->json(['ok' => true], 202);
    }

    public function recording(Request $r, string $uuid, string $call)
    {
        [$conn, $company] = $this->resolve($uuid);
        abort_unless(hash_equals((string) $conn->inbound_secret, (string) $r->header('X-Api-Key', '')), 401, 'Bad key.');
        return app(CurrentCompany::class)->run($company, function () use ($call) {
            $c = Call::query()->where('uuid', $call)->firstOrFail();
            $rec = Recording::query()->where('call_id', $c->id)->first();
            if (!$rec || $rec->status !== RecordingState::Available->value) {
                return response()->json(['status' => $rec?->status ?? 'none', 'message' => 'Recording not available yet.'], $rec && $rec->status !== RecordingState::Failed->value ? 404 : 410);
            }
            \App\Support\Audit::record('recording.downloaded', $rec, ['call_id' => $c->id, 'by' => 'smartdcm']);
            $disk = Storage::disk(config('ammp.recordings.disk'));
            return $disk->download($rec->object_key, $c->uuid . '.' . ($rec->format ?: 'mp3'), ['Content-Type' => $rec->mime, 'Cache-Control' => 'private, no-store']);
        });
    }

    /** @return array{0: IntegrationConnection, 1: Company} */
    private function resolve(string $uuid): array
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/', $uuid) === 1, 404);
        $conn = app(CurrentCompany::class)->withoutScope(fn () => IntegrationConnection::query()->where('uuid', $uuid)->where('system', 'smartdcm')->where('enabled', true)->first());
        abort_if($conn === null || !$conn->inbound_secret, 404);
        $company = Company::query()->whereKey($conn->company_id)->where('status', 'active')->firstOrFail();
        return [$conn, $company];
    }
}
