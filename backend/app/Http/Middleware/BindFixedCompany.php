<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;

/**
 * On-premises (TENANCY_MODE=fixed) there is one company, so every web request belongs to it. Without this, a signed-in
 * browser's page load (/admin, /admin/…) crashed with "Tenant query on User without a bound company": the database
 * session store looks up the signed-in user when it saves the session, and web routes do not run ResolveCompany.
 */
final class BindFixedCompany
{
    public function __construct(private readonly CurrentCompany $current)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (config('ammp.tenancy.mode') === 'fixed' && !$this->current->bound()
            && ($c = Company::query()->whereKey(config('ammp.tenancy.fixed_company_id'))->where('status', 'active')->first())) {
            $this->current->set($c);
        }
        return $next($request);
    }
}
