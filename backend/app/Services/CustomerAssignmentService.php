<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\DomainException;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\ManagedDevice;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

final class CustomerAssignmentService
{
    public function __construct(private readonly PushService $push)
    {
    }

    /** @param list<int> $customerIds */
    public function assign(array $customerIds, ?int $userId, ?int $teamId, User $actor): int
    {
        if ($userId === null && $teamId === null) {
            throw new DomainException('assignee_required');
        }
        if ($userId !== null && !User::query()->whereKey($userId)->where('status', 'active')->exists()) {
            throw new DomainException('unknown_employee');
        }
        if ($teamId !== null && !Team::query()->whereKey($teamId)->exists()) {
            throw new DomainException('unknown_team');
        }
        if ($userId !== null && $teamId === null) {
            // Transfer keeps the case in the receiving caller's group, so that group's manager sees it.
            $teamId = DB::table('team_user')->where('user_id', $userId)->orderByRaw("membership = 'member' desc")->value('team_id');
            $teamId = $teamId === null ? null : (int) $teamId;
        }
        $this->assertScope($actor, $userId, $teamId);
        $this->assertCustomersInScope($actor, $customerIds);
        $previousUsers = Customer::query()->whereIn('id', $customerIds)->pluck('assigned_user_id')->filter()->unique()->all();
        $n = DB::transaction(function () use ($customerIds, $userId, $teamId, $actor) {
            $n = 0;
            foreach (Customer::query()->whereIn('id', $customerIds)->lockForUpdate()->get() as $c) {
                $action = ($c->assigned_user_id || $c->assigned_team_id) ? 'reassigned' : 'assigned';
                $c->forceFill(['assigned_user_id' => $userId, 'assigned_team_id' => $teamId, 'withdrawn_at' => null])->save();
                CustomerAssignment::query()->create(['customer_id' => $c->id, 'user_id' => $userId, 'team_id' => $teamId, 'action' => $action, 'actor_user_id' => $actor->id]);
                if ($userId !== null) {
                    // Open follow-ups move with the case; call history, recordings, payments and notes stay on the customer.
                    \App\Models\FollowUp::query()->where('customer_id', $c->id)->where('status', 'open')->update(['user_id' => $userId]);
                }
                $n++;
            }
            return $n;
        });
        Audit::record('customers.assigned', null, ['count' => $n, 'user_id' => $userId, 'team_id' => $teamId]);
        $this->notify(array_merge($previousUsers, array_filter([$userId])));
        return $n;
    }

    /**
     * Random allocation of already-imported customers inside one group: shuffled and shared evenly between the chosen
     * telecallers (all active telecallers of the group when none chosen). Managers: own group only (assertScope).
     * @return array{updated:int, per_caller:array<int,int>}
     */
    public function assignRandom(array $customerIds, int $teamId, array $userIds, User $actor): array
    {
        if (!Team::query()->whereKey($teamId)->exists()) {
            throw new DomainException('group_required', 'Choose the group.');
        }
        $this->assertScope($actor, null, $teamId);
        $members = User::query()->where('status', 'active')
            ->whereIn('id', DB::table('team_user')->where('team_id', $teamId)->where('membership', 'member')->pluck('user_id'))->pluck('id')->all();
        $picked = array_values(array_unique(array_map('intval', $userIds)));
        if ($picked && array_diff($picked, $members)) {
            throw new DomainException('caller_not_in_group', 'Some selected callers are not telecallers of this group.');
        }
        $callers = $picked ?: $members;
        if (!$callers) {
            throw new DomainException('no_callers', 'This group has no active telecallers.');
        }
        $ids = array_values(array_unique(array_map('intval', $customerIds)));
        $this->assertCustomersInScope($actor, $ids);
        shuffle($ids);
        shuffle($callers);
        $buckets = [];
        foreach ($ids as $i => $cid) {
            $buckets[$callers[$i % count($callers)]][] = $cid;
        }
        $per = [];
        foreach ($buckets as $uid => $chunk) {
            $per[$uid] = $this->assign($chunk, $uid, $teamId, $actor);
        }
        return ['updated' => array_sum($per), 'per_caller' => $per];
    }

    /** Transfer every active case of one caller to another caller, or at random between callers of a group. */
    public function transferAll(int $fromUserId, array $to, User $actor): array
    {
        $ids = Customer::query()->where('assigned_user_id', $fromUserId)->whereNull('withdrawn_at')->pluck('id')->all();
        if (!$ids) {
            throw new DomainException('nothing_to_transfer', 'This caller has no customers.');
        }
        if (($to['mode'] ?? 'single') === 'random') {
            return $this->assignRandom($ids, (int) ($to['team_id'] ?? 0), array_diff($to['user_ids'] ?? [], [$fromUserId]), $actor);
        }
        if ((int) ($to['user_id'] ?? 0) === $fromUserId) {
            throw new DomainException('same_caller', 'Choose a different caller.');
        }
        return ['updated' => $this->assign($ids, (int) ($to['user_id'] ?? 0) ?: null, null, $actor)];
    }

    /** Withdrawn customers disappear from the phone at its next successful sync; call history is retained. */
    public function withdraw(array $customerIds, User $actor): int
    {
        $this->assertCustomersInScope($actor, $customerIds);
        $previousUsers = Customer::query()->whereIn('id', $customerIds)->pluck('assigned_user_id')->filter()->unique()->all();
        $n = DB::transaction(function () use ($customerIds, $actor) {
            $n = 0;
            foreach (Customer::query()->whereIn('id', $customerIds)->whereNull('withdrawn_at')->lockForUpdate()->get() as $c) {
                $c->forceFill(['withdrawn_at' => now()])->save();
                CustomerAssignment::query()->create(['customer_id' => $c->id, 'user_id' => null, 'team_id' => null, 'action' => 'withdrawn', 'actor_user_id' => $actor->id]);
                $n++;
            }
            return $n;
        });
        Audit::record('customers.withdrawn', null, ['count' => $n]);
        $this->notify($previousUsers);
        return $n;
    }

    /** Supervisors may only move customers currently within their teams (by team or by a team member owner). */
    private function assertCustomersInScope(User $actor, array $customerIds): void
    {
        $teams = $actor->supervisedTeamIds();
        if ($teams === null) {
            return;
        }
        $members = \Illuminate\Support\Facades\DB::table('team_user')->whereIn('team_id', $teams)->pluck('user_id');
        $inScope = Customer::query()->whereIn('id', $customerIds)
            ->where(fn ($w) => $w->whereIn('assigned_team_id', $teams)->orWhereIn('assigned_user_id', $members))->count();
        if ($inScope !== count(array_unique($customerIds))) {
            throw new DomainException('forbidden', 'Some customers are outside your teams.');
        }
    }

    private function assertScope(User $actor, ?int $userId, ?int $teamId): void
    {
        $teams = $actor->supervisedTeamIds();
        if ($teams === null) {
            return;
        }
        if ($teamId !== null && !in_array($teamId, $teams, true)) {
            throw new DomainException('forbidden', 'Team is outside your scope.');
        }
        if ($userId !== null && !$actor->canSeeUser(User::query()->findOrFail($userId))) {
            throw new DomainException('forbidden', 'Employee is outside your scope.');
        }
    }

    private function notify(array $userIds): void
    {
        ManagedDevice::query()->whereIn('assigned_user_id', $userIds)->where('approval_state', 'approved')
            ->each(fn (ManagedDevice $d) => $this->push->ping($d, 'customers'));
    }
}
