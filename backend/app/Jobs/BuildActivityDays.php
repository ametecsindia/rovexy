<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ActivityEvent;
use App\Models\Call;
use App\Models\User;
use App\Services\Activity\DayBuilder;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;

/** Rebuilds one company-day of the employee activity report for everyone who did anything that day. */
final class BuildActivityDays extends TenantJob
{
    public int $timeout = 1800;

    public function __construct(int $companyId, public readonly string $date)
    {
        parent::__construct($companyId);
    }

    public function run(DayBuilder $builder): void
    {
        $from = Carbon::parse($this->date, CurrentCompany::tz())->startOfDay()->utc();
        $to = $from->copy()->addDay();
        $ids = ActivityEvent::query()->whereBetween('occurred_at', [$from, $to])->distinct()->pluck('user_id')
            ->merge(Call::query()->whereBetween('initiated_at', [$from, $to])->distinct()->pluck('user_id'))
            ->filter()->unique();
        User::query()->whereIn('id', $ids)->each(fn (User $u) => $builder->save($u, $this->date));
        \Illuminate\Support\Facades\Cache::put("activity-built:{$this->companyId}:{$this->date}", 1, now()->addDays(120));   // days with nobody working too
    }
}
