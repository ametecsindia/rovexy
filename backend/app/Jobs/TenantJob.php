<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Every queued job carries its company_id and re-binds tenancy before touching data. */
abstract class TenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $companyId)
    {
    }

    public function handle(CurrentCompany $current): void
    {
        $company = Company::query()->whereKey($this->companyId)->where('status', 'active')->first();
        if ($company === null) {
            return;
        }
        $current->run($company, fn () => app()->call([$this, 'run']));
    }
}
