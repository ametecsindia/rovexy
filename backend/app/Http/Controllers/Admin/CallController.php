<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Calling\RecordingState;
use App\Domain\Customers\PhoneNormaliser;
use App\Http\Controllers\Controller;
use App\Jobs\FetchRecording;
use App\Models\Call;
use App\Models\DispositionCode;
use App\Models\FollowUp;
use App\Models\Recording;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

final class CallController extends Controller
{
    private function scoped(Request $r)
    {
        $teams = $r->user()->supervisedTeamIds();
        return Call::query()->when($teams !== null, fn ($q) => $q->whereIn('user_id', DB::table('team_user')->whereIn('team_id', $teams)->pluck('user_id')));
    }

    public function index(Request $r)
    {
        // Pull results/recordings for a few still-open click-to-call calls (no scheduler needed on a dev PC).
        $this->scoped($r)->whereIn('provider', \App\Telephony\PolledTelephony::PROVIDERS)->where('initiated_at', '>', now()->subHours(3))
            ->where(fn ($q) => $q->whereNotIn('state', \App\Domain\Calling\CallLifecycle::TERMINAL)->orWhere('recording_status', 'pending'))
            ->latest('initiated_at')->limit(5)->get()->each(fn ($c) => app(\App\Services\CallService::class)->syncProvider($c));
        return response()->json($this->scoped($r)->where('provider', '!=', 'manual')
            ->with(['user:id,name,employee_code', 'customer:id,customer_ref,name', 'disposition:id,call_id,code,follow_up_at', 'recording:id,call_id,format,duration_seconds,last_error'])
            ->when($r->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($r->query('customer_id'), fn ($q, $v) => $q->where('customer_id', $v))
            ->when($r->query('disposition'), fn ($q, $v) => $q->whereHas('disposition', fn ($d) => $d->where('code', $v)))
            ->when($r->query('state'), fn ($q, $v) => $q->where('state', $v))
            ->when($r->query('recording_status'), fn ($q, $v) => $q->where('recording_status', $v))
            ->when($r->query('from'), fn ($q, $v) => $q->where('initiated_at', '>=', $v))
            ->when($r->query('to'), fn ($q, $v) => $q->where('initiated_at', '<', $v))
            ->orderByDesc('initiated_at')->paginate(100)
            ->through(fn (Call $c) => $c->toArray() + ['destination_masked' => PhoneNormaliser::mask($c->destination)]));
    }

    /** Deletes a call with its recording file, events and disposition. Recordings on legal hold are kept. */
    public function destroy(Request $r, int $id)
    {
        $c = $this->scoped($r)->with('recording')->findOrFail($id);
        if ($c->recording?->legal_hold) {
            return response()->json(['message' => 'This recording is on legal hold and cannot be deleted.', 'code' => 'legal_hold'], 409);
        }
        if ($c->recording?->object_key) {
            Storage::disk(config('ammp.recordings.disk'))->delete($c->recording->object_key);
        }
        Audit::record('call.deleted', $c, ['call_id' => $c->id, 'customer_id' => $c->customer_id, 'user_id' => $c->user_id, 'had_recording' => (bool) $c->recording]);
        $c->delete();   // events, recording row and disposition cascade in the database
        return response()->json(['deleted' => true]);
    }

    public function show(Request $r, int $id)
    {
        $pending = $this->scoped($r)->whereKey($id)->whereIn('provider', \App\Telephony\PolledTelephony::PROVIDERS)->first();
        if ($pending) {
            app(\App\Services\CallService::class)->syncProvider($pending);
        }
        $c = $this->scoped($r)->with(['events' => fn ($q) => $q->orderBy('occurred_at'), 'recording', 'disposition', 'customer:id,customer_ref,name', 'user:id,name'])->findOrFail($id);
        $out = $c->toArray();
        $out['destination'] = $r->user()->hasPermission('customers.view') ? $c->destination : PhoneNormaliser::mask($c->destination);
        unset($out['recording']['source_path'], $out['recording']['object_key']);
        return response()->json($out);
    }

    /** Short-lived playback URL; every issue is audited. */
    public function playback(Request $r, int $id)
    {
        $rec = $this->availableRecording($r, $id);
        Audit::record('recording.played', $rec, ['call_id' => $rec->call_id]);
        return response()->json(['url' => $this->temporaryUrl($rec, false), 'mime' => $rec->mime, 'expires_in' => config('ammp.recordings.playback_url_ttl')]);
    }

    public function download(Request $r, int $id)
    {
        $rec = $this->availableRecording($r, $id);
        Audit::record('recording.downloaded', $rec, ['call_id' => $rec->call_id, 'sha256' => $rec->sha256]);
        return response()->json(['url' => $this->temporaryUrl($rec, true), 'sha256' => $rec->sha256, 'filename' => "call-{$rec->call_id}.{$rec->format}"]);
    }

    public function retry(Request $r, int $id)
    {
        $c = $this->scoped($r)->findOrFail($id);
        $rec = Recording::query()->where('call_id', $c->id)->where('status', RecordingState::Failed->value)->firstOrFail();
        $rec->forceFill(['status' => RecordingState::Processing->value, 'attempts' => 0])->save();
        $c->forceFill(['recording_status' => RecordingState::Processing->value])->save();
        FetchRecording::dispatch($c->company_id, $rec->id);
        Audit::record('recording.retry', $rec);
        return response()->json(['ok' => true]);
    }

    /** Signed stream for local-disk storage (S3 uses provider pre-signed URLs). */
    public function stream(Request $r, int $id)
    {
        $rec = Recording::query()->whereKey($id)->where('status', RecordingState::Available->value)->firstOrFail();
        $disk = Storage::disk(config('ammp.recordings.disk'));
        $headers = ['Content-Type' => $rec->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        return $r->boolean('dl')
            ? $disk->download($rec->object_key, "call-{$rec->call_id}.{$rec->format}", $headers)
            : $disk->response($rec->object_key, null, $headers);
    }

    public function followUps(Request $r)
    {
        $teams = $r->user()->supervisedTeamIds();
        return response()->json(FollowUp::query()->where('status', 'open')->with(['customer:id,customer_ref,name,status', 'user:id,name'])
            ->when($teams !== null, fn ($q) => $q->whereIn('user_id', DB::table('team_user')->whereIn('team_id', $teams)->pluck('user_id')))
            ->orderBy('due_at')->paginate(100));
    }

    public function codes()
    {
        return response()->json(DispositionCode::query()->orderBy('label')->get());
    }

    public function saveCodes(Request $r)
    {
        $data = $r->validate(['codes' => 'required|array|max:100', 'codes.*.code' => 'required|string|max:32|regex:/^[A-Z0-9_]+$/',
            'codes.*.label' => 'required|string|max:100', 'codes.*.requires_follow_up' => 'boolean', 'codes.*.active' => 'boolean',
            'codes.*.captures' => 'nullable|in:none,promise,payment']);
        foreach ($data['codes'] as $c) {
            DispositionCode::query()->updateOrCreate(['code' => $c['code']], ['label' => $c['label'], 'requires_follow_up' => $c['requires_follow_up'] ?? false, 'active' => $c['active'] ?? true, 'captures' => $c['captures'] ?? 'none']);
        }
        Audit::record('dispositions.updated', null, ['count' => count($data['codes'])]);
        return response()->json(['ok' => true]);
    }

    private function availableRecording(Request $r, int $callId): Recording
    {
        $c = $this->scoped($r)->findOrFail($callId);
        return Recording::query()->where('call_id', $c->id)->where('status', RecordingState::Available->value)->firstOrFail();
    }

    private function temporaryUrl(Recording $rec, bool $download): string
    {
        $ttl = now()->addSeconds((int) config('ammp.recordings.playback_url_ttl'));
        $disk = Storage::disk(config('ammp.recordings.disk'));
        if (config('filesystems.disks.' . config('ammp.recordings.disk') . '.driver') === 's3') {
            return $disk->temporaryUrl($rec->object_key, $ttl, $download ? ['ResponseContentDisposition' => "attachment; filename=call-{$rec->call_id}.{$rec->format}"] : []);
        }
        // The browser's <audio> loads this URL without the X-Workspace header: name the workspace in the (signed) URL, or
        // /w/<slug> consoles got "company_unresolved" and the player showed "no supported source".
        return URL::temporarySignedRoute('recordings.stream', $ttl, ['id' => $rec->id, 'dl' => $download ? 1 : 0,
            'workspace' => app(\App\Support\CurrentCompany::class)->get()->slug], absolute: false);
    }
}
