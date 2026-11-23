<?php

declare(strict_types=1);

namespace App\Services\SmartDcm;

use App\Models\Call;
use App\Models\CallDisposition;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\CustomerEvent;
use App\Models\DispositionCode;
use App\Models\ExternalRef;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ROVEXY → SmartDCM: work done in ROVEXY on SmartDCM cases goes back to SmartDCM through the integration outbox
 * (delivered and retried by FieldForceIntegration::flushCompany, every minute and right after the request):
 *   disposition  every call / field entry outcome, with PTP, payment and the call (recording read back from ROVEXY)
 *   visit        GPS check-in at the customer
 *   event        notes, status changes, re-allocation done in ROVEXY, payment verified / rejected
 * Only for cases that came from SmartDCM. Never while a sync runs (SmartDCM's own data is not echoed back).
 * A failure here is logged and never breaks the user's action.
 */
final class SmartDcmOutbox
{
    public static function register(): void
    {
        CallDisposition::created(fn (CallDisposition $d) => self::safely(fn () => self::disposition($d)));
        CallDisposition::updated(fn (CallDisposition $d) => $d->wasChanged('review_status') && self::safely(fn () => self::paymentReview($d)));
        CustomerEvent::created(fn (CustomerEvent $e) => self::safely(fn () => self::customerEvent($e)));
        CustomerAssignment::created(fn (CustomerAssignment $a) => $a->actor_user_id !== null && self::safely(fn () => self::allocation($a)));
    }

    public static function connection(): ?IntegrationConnection
    {
        $ctx = app(CurrentCompany::class);
        if (!$ctx->bound() || SmartDcmSync::$syncing) {
            return null;
        }
        $c = IntegrationConnection::query()->where('system', SmartDcmSync::SYSTEM)->where('enabled', true)->first();
        return $c && $c->option('send_results', true) ? $c : null;
    }

    private static function disposition(CallDisposition $d): void
    {
        $call = Call::query()->find($d->call_id);
        if (!$call || !($case = self::caseId($call->customer_id)) || !self::connection()) {
            return;
        }
        $label = DispositionCode::query()->where('code', $d->code)->value('label');
        $tz = self::tz();
        $ptp = $d->amount !== null && $d->payment_mode === null;
        $channel = match ($call->provider) {
            'sim' => 'sim', 'click2call', 'exotel' => 'c2c', 'smartdcm_webrtc' => 'webrtc', 'manual' => 'field_visit', default => $call->provider,
        };
        $conn = self::connection();
        self::enqueue('disposition', array_filter([
            'external_id' => 'rovexy-disp-' . $d->id, 'case_id' => $case, 'account_no' => Customer::query()->whereKey($call->customer_id)->value('customer_ref'),
            'user_id' => ExternalRef::remoteFor(SmartDcmSync::SYSTEM, 'user', (int) $d->user_id), 'employee_code' => User::query()->whereKey($d->user_id)->value('employee_code'),
            'channel' => $channel, 'code' => $d->code, 'label' => $label, 'notes' => $d->notes,
            'at' => ($d->submitted_at ?? now())->setTimezone($tz)->toAtomString(),
            'follow_up_at' => $d->follow_up_at?->setTimezone($tz)->toAtomString(),
            'ptp' => $ptp ? ['amount' => (float) $d->amount, 'date' => $d->follow_up_at?->setTimezone($tz)->toDateString()] : null,
            'payment' => $d->payment_mode !== null ? array_filter(['amount' => $d->amount !== null ? (float) $d->amount : null, 'mode' => $d->payment_mode,
                'reference' => $d->payment_ref, 'paid_on' => $d->paid_on?->toDateString(), 'review_status' => $d->review_status ?? 'pending'], fn ($v) => $v !== null) : null,
            'call' => $channel === 'field_visit' ? null : array_filter(['id' => $call->uuid, 'number' => $call->which_number, 'caller_number' => $call->caller_number,
                'talk_seconds' => (int) $call->talk_seconds, 'started_at' => $call->initiated_at?->setTimezone($tz)->toAtomString(), 'state' => $call->state,
                'recording_url' => $call->recording_status !== 'not_required' && $conn?->uuid ? route('smartdcm.recording', ['uuid' => $conn->uuid, 'call' => $call->uuid]) : null],
                fn ($v) => $v !== null),
        ], fn ($v) => $v !== null && $v !== ''));
    }

    private static function customerEvent(CustomerEvent $e): void
    {
        if (!in_array($e->type, ['visit', 'note', 'status'], true) || !($case = self::caseId($e->customer_id)) || !self::connection()) {
            return;
        }
        $base = ['case_id' => $case, 'user_id' => $e->user_id ? ExternalRef::remoteFor(SmartDcmSync::SYSTEM, 'user', (int) $e->user_id) : null,
            'employee_code' => $e->user_id ? User::query()->whereKey($e->user_id)->value('employee_code') : null,
            'at' => ($e->created_at ?? now())->setTimezone(self::tz())->toAtomString()];
        if ($e->type === 'visit') {
            $m = (array) $e->meta;
            self::enqueue('visit', array_filter(['external_id' => 'rovexy-visit-' . $e->id] + $base + ['lat' => $m['lat'] ?? null, 'lng' => $m['lng'] ?? null,
                'distance_m' => $m['distance_m'] ?? null, 'accuracy_m' => $m['accuracy_m'] ?? null, 'notes' => $e->body], fn ($v) => $v !== null));
            return;
        }
        self::enqueue('event', array_filter(['external_id' => 'rovexy-ev-' . $e->id, 'type' => $e->type] + $base + [
            'status' => $e->type === 'status' ? ($e->meta['to'] ?? null) : null, 'previous_status' => $e->type === 'status' ? ($e->meta['from'] ?? null) : null, 'notes' => $e->body], fn ($v) => $v !== null));
    }

    private static function allocation(CustomerAssignment $a): void
    {
        if (!($case = self::caseId($a->customer_id)) || !($conn = self::connection()) || !$conn->option('send_allocations', true)) {
            return;
        }
        self::enqueue('event', array_filter(['external_id' => 'rovexy-alloc-' . $a->id, 'type' => $a->action === 'withdrawn' ? 'withdrawn' : 'allocation', 'case_id' => $case,
            'assigned_user_id' => $a->user_id ? ExternalRef::remoteFor(SmartDcmSync::SYSTEM, 'user', (int) $a->user_id) : null,
            'assigned_employee_code' => $a->user_id ? User::query()->whereKey($a->user_id)->value('employee_code') : null,
            'team_id' => $a->team_id ? ExternalRef::remoteFor(SmartDcmSync::SYSTEM, 'team', (int) $a->team_id) : null,
            'by_user_id' => ExternalRef::remoteFor(SmartDcmSync::SYSTEM, 'user', (int) $a->actor_user_id),
            'at' => ($a->created_at ?? now())->setTimezone(self::tz())->toAtomString()], fn ($v) => $v !== null));
    }

    private static function paymentReview(CallDisposition $d): void
    {
        $customerId = Call::query()->whereKey($d->call_id)->value('customer_id');
        if (!in_array($d->review_status, ['verified', 'rejected'], true) || !($case = self::caseId($customerId)) || !self::connection()) {
            return;
        }
        self::enqueue('event', array_filter(['external_id' => 'rovexy-review-' . $d->id . '-' . $d->review_status, 'type' => 'payment_review', 'case_id' => $case,
            'disposition_external_id' => 'rovexy-disp-' . $d->id, 'review_status' => $d->review_status, 'notes' => $d->review_note,
            'at' => ($d->reviewed_at ?? now())->setTimezone(self::tz())->toAtomString()], fn ($v) => $v !== null));
    }

    private static function caseId(?int $customerId): ?string
    {
        return $customerId ? ExternalRef::remoteFor(SmartDcmSync::SYSTEM, 'case', $customerId) : null;
    }

    private static function enqueue(string $kind, array $payload): void
    {
        DB::table('integration_outbox')->insert(['company_id' => app(CurrentCompany::class)->id(), 'system' => SmartDcmSync::SYSTEM, 'kind' => $kind,
            'payload' => json_encode($payload), 'status' => 'pending', 'created_at' => now()]);
    }

    private static function tz(): string
    {
        return app(CurrentCompany::class)->get()->timezone ?: 'Asia/Kolkata';
    }

    private static function safely(callable $fn): bool
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error('smartdcm.outbox_error', ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }
        return true;
    }
}
