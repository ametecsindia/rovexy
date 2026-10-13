<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Customers\PhoneNormaliser;
use App\Models\Call;
use App\Models\CallDisposition;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\CustomerEvent;
use App\Models\FollowUp;
use App\Models\ImportJob;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One place that turns stored records into what a collector sees on a case: dues (as uploaded, less VERIFIED
 * payments only), last call, promises (open / kept / broken), callbacks, and the activity timeline.
 * Nothing here is estimated: a figure that is not in the records is returned as null and shown as "not available".
 *
 * Payments live on call outcomes (call_dispositions): a payment has a payment_mode, a promise has an amount only.
 * Field entries recorded without a call are calls with provider = manual (see recordEntry) and are not counted as calls.
 */
final class CaseService
{
    /** Per-case activity for a list of customers, keyed by customer id (2 grouped queries per 1000 cases). */
    public function activity(Collection $ids): array
    {
        $out = [];
        foreach ($ids->chunk(1000) as $chunk) {
            $calls = Call::query()->whereIn('customer_id', $chunk)->where('provider', '!=', 'manual')
                ->selectRaw('customer_id, MAX(initiated_at) AS last_call_at, COUNT(*) AS calls, SUM(CASE WHEN answered_at IS NULL THEN 0 ELSE 1 END) AS connected')
                ->groupBy('customer_id')->get()->keyBy('customer_id');
            $disp = CallDisposition::query()->join('calls', 'calls.id', '=', 'call_dispositions.call_id')->whereIn('calls.customer_id', $chunk)
                ->orderBy('call_dispositions.submitted_at')->orderBy('call_dispositions.id')
                ->get(['calls.customer_id', 'call_dispositions.code', 'call_dispositions.amount', 'call_dispositions.payment_mode', 'call_dispositions.review_status',
                    'call_dispositions.paid_on', 'call_dispositions.follow_up_at', 'call_dispositions.submitted_at'])->groupBy('customer_id');
            $fu = FollowUp::query()->whereIn('customer_id', $chunk)->where('status', 'open')->selectRaw('customer_id, MIN(due_at) AS next_at')
                ->groupBy('customer_id')->pluck('next_at', 'customer_id');
            foreach ($chunk as $id) {
                $c = $calls[$id] ?? null;
                $p = $this->payments($disp[$id] ?? collect());
                $out[$id] = [
                    'last_call_at' => $c?->last_call_at ? (new \DateTimeImmutable((string) $c->last_call_at, new \DateTimeZone('UTC')))->format(DATE_ATOM) : null,
                    'calls' => (int) ($c?->calls ?? 0), 'connected' => (int) ($c?->connected ?? 0),
                    'last_outcome' => ($disp[$id] ?? collect())->last()?->code,
                    'next_callback_at' => isset($fu[$id]) ? (new \DateTimeImmutable((string) $fu[$id], new \DateTimeZone('UTC')))->format(DATE_ATOM) : null,
                ] + $p;
            }
        }
        return $out;
    }

    /** Promise + payment totals from a customer's outcomes (oldest first). */
    private function payments(Collection $d): array
    {
        $pay = $d->filter(fn ($x) => $x->payment_mode !== null && $x->review_status !== 'rejected');
        $promise = $d->filter(fn ($x) => $x->amount !== null && $x->payment_mode === null)->last();
        $kept = $promise && $pay->contains(fn ($x) => $x->submitted_at >= $promise->submitted_at);
        $today = now()->toDateString();
        return [
            'collected_verified' => round((float) $pay->where('review_status', 'verified')->sum('amount'), 2),
            'collected_pending' => round((float) $pay->where('review_status', 'pending')->sum('amount'), 2),
            // The phone works out "payments recorded today" itself, so the figure is right even before the next sync.
            'last_payment_recorded_on' => $pay->last() ? substr((string) $pay->last()->submitted_at, 0, 10) : null,
            'last_payment_recorded_amount' => $pay->last() ? (float) $pay->last()->amount : null,
            'promise_amount' => $promise ? (float) $promise->amount : null,
            'promise_date' => $promise?->follow_up_at ? substr((string) $promise->follow_up_at, 0, 10) : null,
            // open = date not reached; kept = a payment was recorded after the promise; broken = date passed, nothing paid.
            'promise_status' => $promise ? ($kept ? 'kept' : (substr((string) $promise->follow_up_at, 0, 10) < $today ? 'broken' : 'open')) : null,
        ];
    }

    /** Dues: as uploaded, then less verified payments made on/after the upload date. Null when not uploaded. */
    public function dues(Customer $c, float $verifiedSinceUpload): array
    {
        $tz = new \DateTimeZone(app(\App\Support\CurrentCompany::class)->get()->timezone ?? 'UTC');
        $today = new \DateTimeImmutable('today', $tz);
        $dpd = $c->oldest_due_date && ($c->overdue_amount === null || (float) $c->overdue_amount > 0)
            ? max(0, (int) (new \DateTimeImmutable($c->oldest_due_date->format('Y-m-d'), $tz))->diff($today)->format('%r%a')) : null;
        $minus = fn ($v) => $v === null ? null : round(max(0, (float) $v - $verifiedSinceUpload), 2);
        return [
            'loan_amount' => $c->loan_amount !== null ? (float) $c->loan_amount : null,
            'outstanding' => $c->outstanding !== null ? (float) $c->outstanding : null,
            'principal_os' => $c->principal_os !== null ? (float) $c->principal_os : null,
            'interest_os' => $c->interest_os !== null ? (float) $c->interest_os : null,
            'charges' => $c->charges !== null ? (float) $c->charges : null,
            'overdue_amount' => $c->overdue_amount !== null ? (float) $c->overdue_amount : null,
            'emi_amount' => $c->emi_amount !== null ? (float) $c->emi_amount : null,
            'due_date' => $c->due_date?->format('Y-m-d'),
            'oldest_due_date' => $c->oldest_due_date?->format('Y-m-d'),
            'overdue_installments' => $c->overdue_installments,
            'days_overdue' => $dpd,
            'last_paid_on' => $c->last_paid_on?->format('Y-m-d'),
            'last_paid_amount' => $c->last_paid_amount !== null ? (float) $c->last_paid_amount : null,
            'balance_as_of' => $c->balance_as_of?->format('Y-m-d'),
            'verified_since_upload' => $verifiedSinceUpload,
            'remaining_balance' => $minus($c->outstanding),
            'overdue_now' => $minus($c->overdue_amount),
        ];
    }

    /** Portfolio name for a customer (the upload it came from). */
    public static function portfolioName(?int $importId): ?string
    {
        if (!$importId) {
            return null;
        }
        $j = ImportJob::query()->find($importId, ['id', 'portfolio', 'original_name']);
        return $j ? ($j->portfolio ?: pathinfo($j->original_name, PATHINFO_FILENAME)) : null;
    }

    /**
     * Full case profile. $forPhone: the assigned employee's own phone (numbers in full, no recording playback);
     * otherwise the admin console (numbers masked, recordings only with recordings.play).
     */
    public function profile(Customer $c, User $viewer, bool $forPhone): array
    {
        $calls = Call::query()->where('customer_id', $c->id)->with(['user:id,name', 'disposition.user:id,name', 'recording:id,call_id,status,format,duration_seconds'])
            ->orderByDesc('initiated_at')->limit(300)->get();
        $codes = \App\Models\DispositionCode::query()->pluck('label', 'code');
        $disp = $calls->pluck('disposition')->filter()->sortBy([['submitted_at', 'asc'], ['id', 'asc']])->values();
        $pay = $disp->filter(fn ($d) => $d->payment_mode !== null);
        $since = $c->balance_as_of?->format('Y-m-d');
        $verifiedSince = round((float) $pay->where('review_status', 'verified')->filter(fn ($d) => $since === null || $d->paid_on?->format('Y-m-d') >= $since)->sum('amount'), 2);
        $act = $this->activity(collect([$c->id]))[$c->id];
        $act['last_outcome_label'] = $act['last_outcome'] ? ($codes[$act['last_outcome']] ?? $act['last_outcome']) : null;
        $mask = fn (?string $p) => $p === null ? null : ($forPhone ? $p : PhoneNormaliser::mask($p));
        $canPlay = !$forPhone && $viewer->hasPermission('recordings.play');
        $assignee = $c->assigned_user_id ? User::query()->find($c->assigned_user_id, ['id', 'name', 'employee_code']) : null;
        $team = $c->assigned_team_id ? \App\Models\Team::query()->find($c->assigned_team_id, ['id', 'name']) : null;
        $assignments = CustomerAssignment::query()->where('customer_id', $c->id)->orderByDesc('id')->limit(50)->get();
        $names = User::query()->whereIn('id', $assignments->pluck('user_id')->merge($assignments->pluck('actor_user_id'))->filter()->unique())->pluck('name', 'id');
        $teams = \App\Models\Team::query()->pluck('name', 'id');
        $followUps = FollowUp::query()->where('customer_id', $c->id)->with('user:id,name')->orderByDesc('due_at')->limit(100)->get();
        $events = CustomerEvent::query()->where('customer_id', $c->id)->with('user:id,name')->orderByDesc('id')->limit(200)->get();
        $promises = $disp->filter(fn ($d) => $d->amount !== null && $d->payment_mode === null)->map(function ($d) use ($pay, $codes) {
            $kept = $pay->contains(fn ($p) => $p->review_status !== 'rejected' && $p->submitted_at >= $d->submitted_at);
            $date = $d->follow_up_at?->format('Y-m-d');
            return ['amount' => (float) $d->amount, 'date' => $date, 'recorded_at' => $d->submitted_at?->toAtomString(), 'by' => $d->user?->name,
                'outcome' => $codes[$d->code] ?? $d->code, 'status' => $kept ? 'kept' : ($date !== null && $date < now()->toDateString() ? 'broken' : 'open')];
        })->reverse()->values();

        $timeline = collect();
        foreach ($calls as $call) {
            $d = $call->disposition;
            $manual = $call->provider === 'manual';
            $timeline->push(['type' => $manual ? 'entry' : 'call', 'at' => $call->initiated_at?->toAtomString(), 'by' => $call->user?->name,
                'call_id' => $call->id, 'state' => $call->state, 'talk_seconds' => (int) $call->talk_seconds, 'number' => $mask($call->destination), 'which' => $call->which_number,
                'outcome' => $d ? ($codes[$d->code] ?? $d->code) : null, 'outcome_code' => $d?->code, 'notes' => $d?->notes,
                'amount' => $d?->amount !== null ? (float) $d->amount : null, 'payment_mode' => $d?->payment_mode, 'payment_ref' => $d?->payment_ref,
                'paid_on' => $d?->paid_on?->format('Y-m-d'), 'review_status' => $d?->review_status, 'promise_date' => $d && $d->amount !== null && $d->payment_mode === null ? $d->follow_up_at?->format('Y-m-d') : null,
                'follow_up_at' => $d?->follow_up_at?->toAtomString(),
                'recording' => $manual ? null : ['status' => $call->recording_status, 'format' => $call->recording?->format, 'can_play' => $canPlay && $call->recording_status === 'available']]);
        }
        foreach ($followUps as $f) {
            $timeline->push(['type' => 'callback', 'at' => $f->created_at?->toAtomString(), 'by' => $f->user?->name, 'due_at' => $f->due_at?->toAtomString(),
                'status' => $f->status, 'notes' => $f->notes]);
        }
        foreach ($events as $e) {
            $timeline->push(['type' => $e->type, 'at' => $e->created_at?->toAtomString(), 'by' => $e->user?->name, 'notes' => $e->body, 'meta' => $e->meta]);
        }
        foreach ($assignments as $a) {
            $timeline->push(['type' => 'assignment', 'at' => $a->created_at?->toAtomString(), 'by' => $a->actor_user_id ? ($names[$a->actor_user_id] ?? null) : 'Upload',
                'notes' => $a->action === 'withdrawn' ? 'Withdrawn' : 'Assigned to ' . ($a->user_id ? ($names[$a->user_id] ?? '#' . $a->user_id) : 'group pool')
                    . ($a->team_id ? ' · ' . ($teams[$a->team_id] ?? '') : '')]);
        }
        $next = $followUps->where('status', 'open')->sortBy('due_at')->first();
        return [
            'id' => $c->id, 'customer_ref' => $c->customer_ref, 'name' => $c->name, 'status' => $c->status, 'withdrawn_at' => $c->withdrawn_at?->toAtomString(),
            'phone' => $mask($c->phone), 'alt_phone' => $mask($c->alt_phone), 'email' => $c->email, 'preferred_time' => $c->preferred_time,
            'address' => $c->address, 'area' => $c->area, 'city' => $c->city, 'state' => $c->state, 'pincode' => $c->pincode,
            'location' => $c->lat !== null && $c->lng !== null ? ['lat' => (float) $c->lat, 'lng' => (float) $c->lng] : null,
            'campaign' => $c->campaign, 'portfolio' => self::portfolioName($c->last_import_id), 'portfolio_id' => $c->last_import_id,
            'assigned_user' => $assignee, 'team' => $team, 'assigned_at' => $assignments->firstWhere('action', '!=', 'withdrawn')?->created_at?->toAtomString(),
            'notes' => $c->notes, 'created_at' => $c->created_at?->toAtomString(),
            'dues' => $this->dues($c, $verifiedSince),
            'installments' => null,   // ponytail: the lead file has one row per case, so no instalment schedule exists to show.
            'activity' => $act,
            'payments' => $pay->reverse()->values()->map(fn ($d) => ['call_id' => $d->call_id, 'amount' => (float) $d->amount, 'mode' => $d->payment_mode,
                'mode_label' => \App\Support\Outcomes::PAYMENT_MODES[$d->payment_mode] ?? $d->payment_mode, 'ref' => $d->payment_ref, 'paid_on' => $d->paid_on?->format('Y-m-d'),
                'status' => $d->review_status, 'by' => $d->user?->name, 'recorded_at' => $d->submitted_at?->toAtomString(), 'has_slip' => $d->has_slip,
                'review_note' => $d->review_note]),
            'promises' => $promises,
            'follow_ups' => $followUps->map(fn ($f) => ['id' => $f->id, 'due_at' => $f->due_at?->toAtomString(), 'status' => $f->status, 'notes' => $f->notes, 'by' => $f->user?->name]),
            'next_action' => $next ? ['due_at' => $next->due_at?->toAtomString(), 'notes' => $next->notes] : null,
            'timeline' => $timeline->sortByDesc('at')->values(),
            'address_history' => \App\Models\CustomerAddressChange::query()->where('customer_id', $c->id)->with('user:id,name')->orderByDesc('id')->limit(50)->get()->map->toRow()->values(),
            'stats' => ['calls' => $act['calls'], 'connected' => $act['connected'], 'talk_seconds' => (int) $calls->where('provider', '!=', 'manual')->sum('talk_seconds')],
        ];
    }

    /** Notes and status changes (also kept in customers.notes for older screens). */
    public function addNote(Customer $c, User $u, string $text, ?\DateTimeImmutable $at = null): void
    {
        $text = mb_substr(trim($text), 0, 2000);
        if ($text === '') {
            throw new \App\Domain\DomainException('note_required', 'Write a note first.');
        }
        DB::transaction(function () use ($c, $u, $text, $at) {
            CustomerEvent::query()->create(['customer_id' => $c->id, 'user_id' => $u->id, 'type' => 'note', 'body' => $text, 'created_at' => $at ?? now()]);
            $c->forceFill(['notes' => mb_substr(trim(($c->notes ?? '') . "\n[" . ($at ?? new \DateTimeImmutable())->format('Y-m-d H:i') . '] ' . $text), -10000)])->save();
        });
    }

    public const STATUSES = ['new', 'in_progress', 'follow_up', 'closed', 'do_not_call'];

    public function setStatus(Customer $c, User $u, string $status, ?string $note = null): void
    {
        in_array($status, self::STATUSES, true) || throw new \App\Domain\DomainException('invalid_status', 'Unknown case status.');
        if ($status === $c->status) {
            return;
        }
        DB::transaction(function () use ($c, $u, $status, $note) {
            CustomerEvent::query()->create(['customer_id' => $c->id, 'user_id' => $u->id, 'type' => 'status', 'body' => $note ? mb_substr($note, 0, 2000) : null,
                'meta' => ['from' => $c->status, 'to' => $status]]);
            $c->forceFill(['status' => $status])->save();
        });
    }

    /**
     * A promise or payment recorded on the case without a call (customer paid by link, visit, branch…). Stored as a
     * manual "call" so it flows through the same outcome, payment review, slip and report path as call feedback.
     */
    public function recordEntry(Customer $c, User $u, int $deviceId, string $code, ?string $notes, ?string $followUpAt, array $pay, ?\Illuminate\Http\UploadedFile $slip): CallDisposition
    {
        $call = Call::query()->create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'provider' => 'manual', 'user_id' => $u->id, 'device_id' => $deviceId,
            'customer_id' => $c->id, 'destination' => $c->phone, 'which_number' => 'primary', 'calling_mode' => 'field_entry', 'agent_leg' => 'none',
            'state' => 'completed', 'initiated_at' => now(), 'ended_at' => now(), 'talk_seconds' => 0, 'recording_status' => 'not_required']);
        try {
            return app(CallService::class)->dispose($call, $u, $code, $notes, $followUpAt, $pay, $slip);
        } catch (\Throwable $e) {
            $call->delete();   // nothing half-recorded
            throw $e;
        }
    }
}
