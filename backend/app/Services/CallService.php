<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Calling\CallAuthoriser;
use App\Domain\Calling\CallLifecycle;
use App\Domain\Calling\RecordingState;
use App\Domain\DomainException;
use App\Events\AdminFeed;
use App\Events\DeviceChannelEvent;
use App\Jobs\FetchRecording;
use App\Jobs\RecordingWatchdog;
use App\Models\Call;
use App\Models\CallDisposition;
use App\Models\CallEvent;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DispositionCode;
use App\Models\FollowUp;
use App\Models\ManagedDevice;
use App\Models\Recording;
use App\Models\User;
use App\Support\Audit;
use App\Support\CurrentCompany;
use App\Telephony\TelephonyManager;
use App\Telephony\TelephonyUnavailable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CallService
{
    public function __construct(
        private readonly TelephonyManager $telephony,
        private readonly PolicyService $policies,
        private readonly CurrentCompany $current,
    ) {
    }

    /**
     * Validates everything server-side, then asks the telephony system to connect. The client may only pick a route the
     * server offers (CALLING_ROUTES: 'sim' = the phone's SIM, 'server' = Click2Call / telephony driver); null = default.
     */
    public function start(User $user, ManagedDevice $device, ?int $customerId, string $which, ?string $requestedNumber, ?string $route = null, ?string $vmn = null): Call
    {
        $routes = self::routes($user);
        if ($routes === []) {
            throw new DomainException('no_calling_option', 'No calling option is enabled for you. Ask your administrator (Calling configuration → Access & permissions).');
        }
        $route ??= $routes[0];
        if (!in_array($route, $routes, true)) {
            throw new DomainException('route_not_allowed', 'This calling option is not enabled for your company.');
        }
        $lock = Cache::lock("call-start:{$user->id}", 10);
        if (!$lock->get()) {
            throw new DomainException('call_in_progress', 'A call is already being started.');
        }
        try {
            $policy = $this->policies->currentSnapshot($device)->values;
            $customer = $customerId === null ? null : Customer::query()->whereKey($customerId)->whereNull('withdrawn_at')->first();
            if ($customerId !== null && $customer === null) {
                throw new DomainException('customer_not_assigned', 'Customer is not available.');
            }
            if ($route === 'sim') {
                // A SIM call whose result never arrived (app killed mid-call) must not block calling for hours.
                Call::query()->where('user_id', $user->id)->where('provider', 'sim')->whereNotIn('state', CallLifecycle::TERMINAL)
                    ->update(['state' => 'cancelled', 'failure_reason' => 'result_not_reported', 'ended_at' => now()]);
            }
            // A provider call (Click2Call / Exotel) still open: read its result first, so a finished call never blocks the next one.
            Call::query()->where('user_id', $user->id)->whereIn('provider', \App\Telephony\PolledTelephony::PROVIDERS)
                ->whereNotIn('state', CallLifecycle::TERMINAL)->get()->each(fn (Call $c) => $this->syncProvider($c));
            // ponytail: a Click2Call call that never got past "initiated" in 3 minutes means the employee's phone never answered;
            // it no longer blocks calling (its late result, if any, is ignored). Raise the 3 minutes if a provider is slower.
            Call::query()->where('user_id', $user->id)->where('provider', 'click2call')->where('state', 'initiated')
                ->where('initiated_at', '<', now()->subMinutes(3))
                ->update(['state' => 'failed', 'failure_reason' => 'agent_not_answered', 'ended_at' => now()]);
            $active = Call::query()->where('user_id', $user->id)->whereNotIn('state', CallLifecycle::TERMINAL)
                ->where('initiated_at', '>', now()->subHours(2))->exists();
            $decision = CallAuthoriser::authorise($policy, $customer ? [
                'id' => $customer->id, 'phone' => $customer->phone, 'alt_phone' => $customer->alt_phone, 'status' => $customer->status,
                'assigned_user_id' => $customer->assigned_user_id, 'assigned_team_id' => $customer->assigned_team_id,
            ] : null, $requestedNumber, $which, [
                'user_id' => $user->id, 'team_ids' => $user->teams()->pluck('teams.id')->map(fn ($i) => (int) $i)->all(),
                'compliant' => (bool) $device->compliant, 'active_call' => $active, 'timezone' => $this->current->get()->timezone,
            ], new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

            if ($route === 'sim') {
                return $this->startSim($user, $device, $customer, $which, $decision);
            }
            $adapter = $this->telephony->adapter();
            // Click-to-call providers always ring the employee's work number first.
            $agentLeg = $adapter instanceof \App\Telephony\PolledTelephony ? 'pstn_callback' : $policy['calling.agent_leg'];
            $c2c = $adapter instanceof \App\Telephony\Click2CallAdapter;
            $agentNumber = $c2c ? \App\Telephony\Click2CallSettings::agentNumber($user) : $user->phone;
            if ($agentLeg === 'pstn_callback' && !$agentNumber) {
                throw new DomainException('agent_phone_missing', 'Your work number is not configured. Contact your administrator.');
            }
            // Click2Call (kit rules): the company number for this call (Smart31 / picked / default), then count the
            // attempt for this customer number today — both refuse with a clear message before anything is dialled.
            $callerNumber = $c2c ? \App\Telephony\Click2CallSettings::callerNumberFor($user, $vmn) : null;
            if ($c2c) {
                \App\Telephony\Click2CallSettings::reserveAttempt($user, $decision['destination']);
            }
            $keep = $decision['record'] || $c2c;   // Click2Call: the provider records every call, keep it even when not required
            $call = DB::transaction(function () use ($user, $device, $customer, $which, $decision, $agentLeg, $adapter, $keep) {
                $call = Call::query()->create([
                    'uuid' => (string) Str::uuid(), 'provider' => $adapter->name(), 'user_id' => $user->id, 'device_id' => $device->id,
                    'customer_id' => $customer?->id, 'destination' => $decision['destination'], 'which_number' => $which,
                    'agent_leg' => $agentLeg, 'state' => 'initiated', 'initiated_at' => now(),
                    'recording_required' => $decision['record'],
                    'recording_status' => $keep ? RecordingState::Pending->value : RecordingState::NotRequired->value,
                ]);
                if ($keep) {
                    Recording::query()->create(['call_id' => $call->id, 'status' => RecordingState::Pending->value]);
                }
                return $call;
            });
            $recordPath = $decision['record']
                ? sprintf('ametecs/%d/%s/%s.wav', $call->company_id, now()->utc()->format('Y/m'), $call->uuid) : null;
            if ($recordPath) {
                Recording::query()->where('call_id', $call->id)->update(['source_path' => $recordPath]);
            }
            try {
                $adapter->originate($call, [
                    'call_id' => $call->uuid, 'company_id' => $call->company_id, 'agent_leg' => $agentLeg,
                    'agent_target' => $agentLeg === 'pstn_callback' ? (string) $agentNumber : 'dev_' . $device->id,
                    'customer_e164' => $decision['destination'], 'record_path' => $recordPath,
                    'caller_number' => $callerNumber,   // Click2Call only: Smart31 / picked / default company number
                ]);
            } catch (TelephonyUnavailable $e) {
                if ($c2c) {
                    \App\Telephony\Click2CallSettings::releaseAttempt($decision['destination']);   // given back, as LeadGER
                }
                Audit::record('call.not_placed', $call, ['provider' => $adapter->name(), 'detail' => mb_substr($e->getMessage(), 0, 200)]);
                $call->forceFill(['state' => 'failed', 'failure_reason' => 'telephony_unavailable', 'ended_at' => now(),
                    'recording_status' => $decision['record'] ? RecordingState::Failed->value : RecordingState::NotRequired->value])->save();
                Recording::query()->where('call_id', $call->id)->update(['status' => RecordingState::Failed->value, 'last_error' => 'call not placed']);
                throw new DomainException('telephony_unavailable', 'Calling service is unavailable. Try again shortly.');
            }
            $device->forceFill(['work_state' => 'on_call'])->saveQuietly();
            Audit::record('call.started', $call, ['customer_id' => $customer?->id, 'which' => $which]);
            $customer?->touch();
            return $call;
        } finally {
            $lock->release();
        }
    }

    /** Calling routes offered (default first): company Click2Call setting, else CALLING_ROUTES / CALLING_ROUTE. */
    public static function routes(?User $user = null): array
    {
        return \App\Telephony\Click2CallSettings::routes($user);
    }

    /** SIM route: the server authorises and records the attempt; the phone dials the returned number itself. */
    private function startSim(User $user, ManagedDevice $device, ?Customer $customer, string $which, array $decision): Call
    {
        $call = DB::transaction(function () use ($user, $device, $customer, $which, $decision) {
            $call = Call::query()->create([
                'uuid' => (string) Str::uuid(), 'provider' => 'sim', 'user_id' => $user->id, 'device_id' => $device->id,
                'customer_id' => $customer?->id, 'destination' => $decision['destination'], 'which_number' => $which,
                'calling_mode' => 'sim', 'agent_leg' => 'sim', 'state' => 'initiated', 'initiated_at' => now(),
                'recording_required' => $decision['record'],
                'recording_status' => $decision['record'] ? RecordingState::Pending->value : RecordingState::NotRequired->value,
            ]);
            if ($decision['record']) {
                Recording::query()->create(['call_id' => $call->id, 'status' => RecordingState::Pending->value]);
            }
            return $call;
        });
        $device->forceFill(['work_state' => 'on_call'])->saveQuietly();
        Audit::record('call.started', $call, ['customer_id' => $customer?->id, 'which' => $which, 'route' => 'sim']);
        $customer?->touch();
        AdminFeed::dispatchFor($call->company_id, 'call.updated', ['call_id' => $call->id, 'state' => $call->state]);
        return $call;
    }

    /**
     * SIM route: the phone reports what its call log says. Idempotent — a result for an already-finished call changes nothing,
     * except a call auto-cancelled as 'result_not_reported', which the late result may still complete.
     */
    public function simResult(Call $call, User $user, int $talk, ?int $ring, ?string $startedAt, ?bool $recordingFound, array $diag = []): Call
    {
        if ($call->provider !== 'sim' || $call->user_id !== $user->id) {
            throw new DomainException('forbidden');
        }
        return DB::transaction(function () use ($call, $talk, $ring, $startedAt, $recordingFound, $diag) {
            $call = Call::query()->whereKey($call->id)->lockForUpdate()->firstOrFail();
            if ($call->isTerminal() && $call->failure_reason !== 'result_not_reported') {
                return $call;
            }
            $start = $startedAt ? \Illuminate\Support\Carbon::parse($startedAt) : $call->initiated_at;
            if ($start->lt($call->initiated_at->copy()->subMinutes(5)) || $start->gt(now())) {
                $start = $call->initiated_at->copy(); // phone clock is not trusted beyond a small skew
            }
            $ended = $start->copy()->addSeconds(($ring ?? 0) + $talk);
            if ($ended->gt(now())) {
                $ended = now();
            }
            $answered = $talk > 0;
            $call->forceFill([
                'state' => $answered ? 'completed' : 'no_answer', 'failure_reason' => null, 'hangup_cause' => 'SIM_' . ($answered ? 'COMPLETED' : 'NO_ANSWER'),
                'answered_at' => $answered ? $ended->copy()->subSeconds($talk) : null, 'ended_at' => $ended,
                'ring_seconds' => $ring, 'talk_seconds' => $talk, 'total_seconds' => max(0, $start->diffInSeconds($ended, true)),
            ])->save();
            CallEvent::query()->firstOrCreate(['call_id' => $call->id, 'provider_event_id' => 'sim-result'],
                ['type' => $answered ? 'hangup' : 'failed', 'occurred_at' => $ended, 'payload' => ['talk' => $talk, 'ring' => $ring, 'source' => 'phone_call_log', 'diag' => $diag]]);

            $rec = Recording::query()->where('call_id', $call->id)->lockForUpdate()->first();
            if ($rec && $rec->status === RecordingState::Pending->value) {
                if (!$answered && $recordingFound !== true) {
                    // Not answered and nothing recorded (an unanswered call may still carry its ringing; then it is uploaded).
                    $rec->forceFill(['status' => RecordingState::NotRequired->value])->save();
                    $call->forceFill(['recording_status' => RecordingState::NotRequired->value])->save();
                } elseif ($recordingFound === false) {
                    $where = isset($diag['audio_folders_since_call']) ? ' Audio saved since the call: ' . $diag['audio_folders_since_call'] . '.' : '';
                    $rec->forceFill(['status' => RecordingState::Failed->value, 'last_error' => mb_substr('No call recording found on the phone. Turn on automatic call recording in the phone dialer.' . $where, 0, 250)])->save();
                    $call->forceFill(['recording_status' => RecordingState::Failed->value])->save();
                    AdminFeed::dispatchFor($call->company_id, 'recording.failed', ['call_id' => $call->id]);
                }
            }
            ManagedDevice::query()->whereKey($call->device_id)->where('work_state', 'on_call')->update(['work_state' => 'updating_customer']);
            Audit::record('call.sim_result', $call, ['talk' => $talk, 'ring' => $ring]);
            AdminFeed::dispatchFor($call->company_id, 'call.updated', ['call_id' => $call->id, 'state' => $call->state]);
            return $call;
        });
    }

    /**
     * Click-to-call providers (Exotel, Click2Call): read the call's state from the provider and apply it once. Throttled per call,
     * idempotent, and safe to call from the phone's status poll, the admin view, the callback trigger and the scheduler.
     */
    public function syncProvider(Call $call, ?array $finalPayload = null): Call
    {
        $adapter = in_array($call->provider, \App\Telephony\PolledTelephony::PROVIDERS, true) ? $this->telephony->adapter() : null;
        // A late provider callback may still bring the link for a recording that had given up waiting (LeadGER: fills a missing URL).
        $late = $finalPayload !== null && $call->recording_status === RecordingState::Failed->value;
        if (!$adapter instanceof \App\Telephony\PolledTelephony || $adapter->name() !== $call->provider || ($call->isTerminal() && $call->recording_status !== RecordingState::Pending->value && !$late)) {
            return $call;
        }
        if ($finalPayload === null && !Cache::add("provider-sync:{$call->id}", 1, 5)) {
            return $call;
        }
        $d = $finalPayload !== null && $adapter instanceof \App\Telephony\Click2CallAdapter ? $adapter->details($call, $finalPayload) : $adapter->details($call);
        if ($d === null) {
            return $call;
        }
        return DB::transaction(function () use ($call, $d) {
            $call = Call::query()->whereKey($call->id)->lockForUpdate()->firstOrFail();
            if (!$call->isTerminal()) {
                if (!$d['terminal']) {
                    if ($call->state !== $d['state']) {
                        $call->forceFill(['state' => $d['state'], 'answered_at' => $d['state'] === 'answered' ? ($call->answered_at ?? now()) : $call->answered_at])->save();
                        ManagedDevice::query()->whereKey($call->device_id)->update(['work_state' => 'on_call']);
                    }
                    return $call;
                }
                $ended = $d['ended'] ? \Illuminate\Support\Carbon::parse($d['ended']) : now();
                $started = $d['started'] ? \Illuminate\Support\Carbon::parse($d['started']) : $call->initiated_at;
                $call->forceFill([
                    'state' => $d['state'], 'hangup_cause' => mb_substr($d['cause'], 0, 48),
                    'answered_at' => $d['talk'] > 0 ? $ended->copy()->subSeconds($d['talk']) : null, 'ended_at' => $ended,
                    'ring_seconds' => $d['ring'], 'talk_seconds' => $d['talk'], 'total_seconds' => max(0, $started->diffInSeconds($ended, true)),
                ])->save();
                CallEvent::query()->firstOrCreate(['call_id' => $call->id, 'provider_event_id' => $call->provider . '-final'],
                    ['type' => $d['talk'] > 0 ? 'hangup' : 'failed', 'occurred_at' => $ended, 'payload' => ['cause' => $d['cause'], 'provider_call_id' => $call->provider_call_id]]);
                ManagedDevice::query()->whereKey($call->device_id)->where('work_state', 'on_call')->update(['work_state' => 'updating_customer']);
                Audit::record('call.provider_result', $call, ['state' => $d['state'], 'talk' => $d['talk']]);
            }
            $rec = Recording::query()->where('call_id', $call->id)->lockForUpdate()->first();
            if ($rec && $rec->status === RecordingState::Failed->value && !str_starts_with((string) $rec->source_path, 'https://') && $d['recording_url'] && $call->talk_seconds > 0) {
                $rec->forceFill(['status' => RecordingState::Pending->value])->save();   // late link: take the normal path below
            }
            if ($rec && $rec->status === RecordingState::Pending->value) {
                if ($call->talk_seconds <= 0 && !$d['recording_url']) {
                    $rec->forceFill(['status' => RecordingState::NotRequired->value])->save();
                    $call->forceFill(['recording_status' => RecordingState::NotRequired->value])->save();
                } elseif ($d['recording_url']) {
                    $rec->forceFill(['status' => RecordingState::Processing->value, 'source_path' => $d['recording_url']])->save();
                    $call->forceFill(['recording_status' => RecordingState::Processing->value])->save();
                    DB::afterCommit(fn () => FetchRecording::dispatch($call->company_id, $rec->id));
                } elseif ($call->ended_at && $call->ended_at->lt(now()->subMinutes($call->provider === 'click2call' ? (int) config('ammp.telephony.click2call.recording_wait_minutes', 60) : 15))) {
                    $rec->forceFill(['status' => RecordingState::Failed->value, 'last_error' => 'The calling provider returned no recording for this call (is recording enabled on the account?).'])->save();
                    $call->forceFill(['recording_status' => RecordingState::Failed->value])->save();
                }
            }
            AdminFeed::dispatchFor($call->company_id, 'call.updated', ['call_id' => $call->id, 'state' => $call->state]);
            return $call;
        });
    }

    /** SIM route: the phone uploads the file its built-in call recorder wrote. Stored privately with size + SHA-256. */
    public function storeSimRecording(Call $call, User $user, \Illuminate\Http\UploadedFile $file, ?int $duration): Call
    {
        if ($call->provider !== 'sim' || $call->user_id !== $user->id) {
            throw new DomainException('forbidden');
        }
        if (!$call->isTerminal()) {
            throw new DomainException('call_not_ended', 'Upload the recording after the call has ended.');
        }
        $rec = Recording::query()->firstOrCreate(['call_id' => $call->id], ['status' => RecordingState::Pending->value]);
        $bytes = (string) file_get_contents($file->getRealPath());
        $sha = hash('sha256', $bytes);
        if ($rec->status === RecordingState::Available->value) {
            return $call; // idempotent re-upload
        }
        $ext = self::SIM_AUDIO[strtolower($file->getClientOriginalExtension())] ?? null;
        $sniffed = (string) $file->getMimeType();
        if ($ext === null || !(str_starts_with($sniffed, 'audio/') || in_array($sniffed, ['video/mp4', 'video/3gpp', 'application/octet-stream'], true))) {
            throw new DomainException('unsupported_recording', 'This file type is not a supported call recording.');
        }
        [$format, $mime] = [strtolower($file->getClientOriginalExtension()), $ext];
        $disk = \Illuminate\Support\Facades\Storage::disk(config('ammp.recordings.disk'));
        $key = sprintf('companies/%d/recordings/%s/%s.%s', $call->company_id, $call->initiated_at->utc()->format('Y/m'), $call->uuid, $format);
        $disk->put($key, $bytes, ['visibility' => 'private']);
        if ($disk->size($key) !== strlen($bytes)) {
            throw new \RuntimeException('Stored size mismatch.');
        }
        $device = ManagedDevice::query()->find($call->device_id);
        $days = $device ? $this->policies->currentSnapshot($device)->values['retention.recording_days'] : \App\Domain\Policy\PolicyResolver::effective([])['values']['retention.recording_days'];
        $rec->forceFill([
            'status' => RecordingState::Processing->value, 'source_path' => 'phone:' . mb_substr($file->getClientOriginalName(), 0, 200),
            'object_key' => $key, 'format' => $format, 'mime' => $mime, 'size_bytes' => strlen($bytes), 'sha256' => $sha,
            'duration_seconds' => $duration, 'last_error' => null, 'retention_expires_at' => now()->addDays($days),
        ])->save();
        \App\Jobs\FetchRecording::markAvailable($rec, $call);
        Audit::record('recording.uploaded_from_phone', $rec, ['call_id' => $call->id, 'sha256' => $sha, 'bytes' => strlen($bytes)]);
        return $call->refresh();
    }

    /** Phone call-recorder formats → playback MIME. AMR/3GP are stored but most browsers cannot play them (download instead). */
    private const SIM_AUDIO = ['m4a' => 'audio/mp4', 'mp4' => 'audio/mp4', 'aac' => 'audio/aac', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
        'ogg' => 'audio/ogg', 'opus' => 'audio/ogg', 'amr' => 'audio/amr', 'awb' => 'audio/amr-wb', '3gp' => 'audio/3gpp', '3gpp' => 'audio/3gpp'];

    /**
     * Idempotent ingestion of a normalised telephony event. Returns 'applied' | 'duplicate' | 'ignored'.
     * Duplicate provider events hit the unique (call_id, provider_event_id) index and change nothing.
     */
    public function ingest(array $e): string
    {
        /** @var Call|null $call */
        $call = $this->current->withoutScope(fn () => Call::query()->where('uuid', $e['call_id'])->first());
        if ($call === null || ($e['company_id'] ?? $call->company_id) !== $call->company_id) {
            return 'ignored';
        }
        $company = Company::query()->findOrFail($call->company_id);
        return $this->current->run($company, function () use ($call, $e) {
            return DB::transaction(function () use ($call, $e) {
                try {
                    CallEvent::query()->create([
                        'call_id' => $call->id, 'provider_event_id' => $e['provider_event_id'], 'type' => $e['type'],
                        'occurred_at' => new \DateTimeImmutable($e['at']), 'payload' => array_intersect_key($e, array_flip(['cause', 'provider_call_id', 'record_path'])),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    return 'duplicate';
                }
                $call = Call::query()->whereKey($call->id)->lockForUpdate()->firstOrFail();
                $wasTerminal = $call->isTerminal();
                if ($call->provider_call_id === null && !empty($e['provider_call_id'])) {
                    $call->provider_call_id = $e['provider_call_id'];
                }
                if (in_array($e['type'], ['recording_started', 'recording_stopped'], true)) {
                    $this->onRecordingEvent($call, $e['type']);
                    $call->save();
                    return 'applied';
                }
                if ($e['type'] === 'customer_hangup') {
                    $call->save();
                    return 'applied'; // informational; the agent (A) leg hangup ends the call
                }
                $next = CallLifecycle::apply($call->lifecycleSnapshot(), $e);
                $call->forceFill([
                    'state' => $next['state'], 'hangup_cause' => $next['hangup_cause'],
                    'agent_answered_at' => $next['agent_answered_at'], 'ringing_at' => $next['ringing_at'],
                    'answered_at' => $next['answered_at'], 'ended_at' => $next['ended_at'],
                ] + CallLifecycle::durations($next))->save();

                if ($e['type'] === 'customer_answered' && $call->recording_required) {
                    RecordingWatchdog::dispatch($call->company_id, $call->id)->delay(now()->addSeconds(10));
                }
                if (!$wasTerminal && $call->isTerminal()) {
                    $this->onCallEnded($call);
                }
                DeviceChannelEvent::dispatchFor(ManagedDevice::query()->findOrFail($call->device_id), 'call.updated', ['call_id' => $call->uuid, 'state' => $call->state]);
                AdminFeed::dispatchFor($call->company_id, 'call.updated', ['call_id' => $call->id, 'state' => $call->state]);
                return 'applied';
            });
        });
    }

    private function onRecordingEvent(Call $call, string $type): void
    {
        $rec = Recording::query()->where('call_id', $call->id)->lockForUpdate()->first();
        if ($rec === null) {
            return;
        }
        $state = RecordingState::from($rec->status);
        $target = $type === 'recording_started' ? RecordingState::Recording : RecordingState::Processing;
        if ($state->canMoveTo($target)) {
            $rec->forceFill(['status' => $target->value])->save();
            $call->recording_status = $target->value;
            if ($target === RecordingState::Processing) {
                FetchRecording::dispatch($call->company_id, $rec->id)->delay(now()->addSeconds(5));
            }
        }
    }

    private function onCallEnded(Call $call): void
    {
        ManagedDevice::query()->whereKey($call->device_id)->where('work_state', 'on_call')->update(['work_state' => 'updating_customer']);
        $rec = Recording::query()->where('call_id', $call->id)->first();
        if ($rec === null) {
            return;
        }
        if ($call->answered_at === null && $rec->status === RecordingState::Pending->value) {
            // Nothing was ever connected, so nothing could be recorded.
            $rec->forceFill(['status' => RecordingState::NotRequired->value])->save();
            $call->forceFill(['recording_status' => RecordingState::NotRequired->value])->save();
        } elseif ($call->answered_at !== null && in_array($rec->status, [RecordingState::Pending->value, RecordingState::Recording->value], true)) {
            // Answered but no RECORD_STOP seen: try to fetch anyway; reconciliation will mark Failed if the file never appears.
            $rec->forceFill(['status' => RecordingState::Processing->value])->save();
            $call->forceFill(['recording_status' => RecordingState::Processing->value])->save();
            FetchRecording::dispatch($call->company_id, $rec->id)->delay(now()->addSeconds(15));
        }
    }

    /** Called by RecordingWatchdog: mandatory recording that has not started after answer. */
    public function handleRecordingNotStarted(Call $call): void
    {
        $rec = Recording::query()->where('call_id', $call->id)->first();
        if ($rec === null || $rec->status !== RecordingState::Pending->value || $call->isTerminal()) {
            return;
        }
        $device = ManagedDevice::query()->findOrFail($call->device_id);
        $policy = $this->policies->currentSnapshot($device)->values;
        $action = RecordingState::failureAction($policy['recording.mode'], $policy['recording.on_failure']);
        $rec->forceFill(['status' => RecordingState::Failed->value, 'last_error' => 'recording did not start'])->save();
        $call->forceFill(['recording_status' => RecordingState::Failed->value])->save();
        Audit::record('recording.start_failed', $call, ['action' => $action]);
        AdminFeed::dispatchFor($call->company_id, 'recording.failed', ['call_id' => $call->id, 'action' => $action]);
        DeviceChannelEvent::dispatchFor($device, 'recording.failed', ['call_id' => $call->uuid, 'action' => $action]);
        if ($action === 'terminate') {
            try {
                $this->telephony->adapter()->hangup($call, 'MANAGER_REQUEST');
            } catch (TelephonyUnavailable) {
                // Reported via the failed status above; reconciliation surfaces the call too.
            }
        }
    }

    public function hangup(Call $call, User $user): void
    {
        if ($call->user_id !== $user->id) {
            throw new DomainException('forbidden');
        }
        if (!$call->isTerminal() && $call->provider !== 'sim') {   // SIM calls are hung up on the phone itself
            $this->telephony->adapter()->hangup($call, 'NORMAL_CLEARING');
        }
        // Click2Call has no hang-up API: if the employee's phone never answered, "Hang up" simply closes the call here
        // (otherwise the app waits up to 15 minutes for a result that will not come). A call in progress ends on the phone.
        if ($call->provider === 'click2call' && $call->state === 'initiated') {
            $call->forceFill(['state' => 'cancelled', 'failure_reason' => 'hung_up_before_answer', 'ended_at' => now()])->save();
            ManagedDevice::query()->whereKey($call->device_id)->where('work_state', 'on_call')->update(['work_state' => 'idle']);
            Audit::record('call.cancelled_by_employee', $call);
        }
    }

    /** Policy `revocation.active_call`: terminate (default) or allow the in-progress call to finish. */
    public function handleRevocation(ManagedDevice $device): void
    {
        $values = $this->policies->currentSnapshot($device)->values;
        if ($values['revocation.active_call'] !== 'terminate') {
            return;
        }
        Call::query()->where('device_id', $device->id)->whereNotIn('state', CallLifecycle::TERMINAL)->each(function (Call $c) {
            try {
                $this->telephony->adapter()->hangup($c, 'MANAGER_REQUEST');
                Audit::record('call.terminated_on_revocation', $c);
            } catch (\Throwable) {
                Audit::record('call.terminate_on_revocation_failed', $c);
            }
        });
    }

    /**
     * @param array{amount?:mixed,payment_mode?:?string,payment_ref?:?string,paid_on?:?string} $pay  promise / payment details
     */
    public function dispose(Call $call, User $user, string $code, ?string $notes, ?string $followUpAt, array $pay = [], ?\Illuminate\Http\UploadedFile $slip = null): CallDisposition
    {
        if ($call->user_id !== $user->id) {
            throw new DomainException('forbidden');
        }
        if (!$call->isTerminal()) {
            throw new DomainException('call_not_ended', 'Submit the outcome after the call ends.');
        }
        $dc = DispositionCode::query()->where('code', $code)->where('active', true)->first() ?? throw new DomainException('invalid_disposition');
        $follow = $followUpAt ? new \DateTimeImmutable($followUpAt) : null;
        if ($dc->requires_follow_up && $follow === null) {
            throw new DomainException('follow_up_required', 'This outcome needs a follow-up date.');
        }
        $extra = $this->paymentFields($dc->captures ?? 'none', $pay, $slip !== null);
        return DB::transaction(function () use ($call, $user, $dc, $notes, $follow, $extra, $slip) {
            $existing = CallDisposition::query()->where('call_id', $call->id)->first();
            if ($existing) {
                return $existing; // idempotent (offline replay)
            }
            if (isset($extra['payment_mode']) && $call->customer_id) {
                // Same customer, amount, date and reference already recorded (and not rejected) = a double entry.
                $dupe = CallDisposition::query()->join('calls', 'calls.id', '=', 'call_dispositions.call_id')
                    ->where('calls.customer_id', $call->customer_id)->where('call_dispositions.amount', $extra['amount'])
                    ->where('call_dispositions.paid_on', $extra['paid_on'])->where('call_dispositions.review_status', '!=', 'rejected')
                    ->when($extra['payment_ref'] !== null, fn ($q) => $q->where('call_dispositions.payment_ref', $extra['payment_ref']))
                    ->exists();
                if ($dupe) {
                    throw new DomainException('duplicate_payment', 'This payment (same amount, date and reference) is already recorded for this customer.');
                }
            }
            if ($slip) {
                $ext = strtolower($slip->getClientOriginalExtension() ?: $slip->extension() ?: 'jpg');
                $key = "companies/{$call->company_id}/payment-slips/{$call->uuid}." . preg_replace('/[^a-z0-9]/', '', $ext);
                Storage::disk('company_files')->put($key, (string) file_get_contents($slip->getRealPath()));
                $extra += ['slip_key' => $key, 'slip_mime' => $slip->getMimeType() ?: 'application/octet-stream', 'slip_name' => mb_substr($slip->getClientOriginalName(), 0, 150)];
            }
            $d = CallDisposition::query()->create(['call_id' => $call->id, 'user_id' => $user->id, 'code' => $dc->code,
                'notes' => $notes ? mb_substr($notes, 0, 2000) : null, 'follow_up_at' => $follow, 'submitted_at' => now()] + $extra);
            $call->forceFill(['disposition_status' => 'submitted'])->save();
            if ($call->customer_id) {
                $c = Customer::query()->find($call->customer_id);
                // ponytail: one open callback per case — new feedback settles the earlier ones.
                FollowUp::query()->where('customer_id', $call->customer_id)->where('status', 'open')->update(['status' => 'done', 'updated_at' => now()]);
                if ($follow) {
                    FollowUp::query()->create(['customer_id' => $call->customer_id, 'user_id' => $user->id, 'call_id' => $call->id, 'due_at' => $follow, 'notes' => $notes]);
                    $c?->forceFill(['status' => 'follow_up', 'follow_up_on' => $follow->format('Y-m-d')])->save();
                } elseif ($c && $c->status === 'new') {
                    $c->forceFill(['status' => 'in_progress'])->save();
                } else {
                    $c?->touch();   // phones re-sync the case (last call, promise, payment)
                }
            }
            ManagedDevice::query()->whereKey($call->device_id)->where('work_state', 'updating_customer')->update(['work_state' => 'idle']);
            return $d;
        });
    }

    /** Validates promise / payment details for the chosen outcome; returns the columns to store. */
    private function paymentFields(string $captures, array $pay, bool $hasSlip): array
    {
        if ($captures === 'none') {
            return [];
        }
        $amount = isset($pay['amount']) && $pay['amount'] !== '' ? round((float) $pay['amount'], 2) : null;
        if ($amount === null || $amount <= 0 || $amount > 99999999) {
            throw new DomainException('amount_required', $captures === 'promise' ? 'Enter the promised amount.' : 'Enter the amount received.');
        }
        if ($captures === 'promise') {
            return ['amount' => $amount];
        }
        $mode = strtoupper((string) ($pay['payment_mode'] ?? ''));
        if (!array_key_exists($mode, \App\Support\Outcomes::PAYMENT_MODES)) {
            throw new DomainException('payment_mode_required', 'Choose how the customer paid.');
        }
        $paidOn = !empty($pay['paid_on']) ? new \DateTimeImmutable((string) $pay['paid_on']) : new \DateTimeImmutable('today');
        if ($paidOn > new \DateTimeImmutable('tomorrow')) {
            throw new DomainException('paid_on_future', 'Payment date cannot be in the future.');
        }
        return ['amount' => $amount, 'payment_mode' => $mode, 'payment_ref' => isset($pay['payment_ref']) ? mb_substr(trim((string) $pay['payment_ref']), 0, 64) ?: null : null,
            'paid_on' => $paidOn->format('Y-m-d'), 'review_status' => 'pending'];
    }
}
