<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\CompanyResolver;
use App\Models\Company;
use App\Support\CurrentCompany;
use App\Support\DbCompanyDirectory;
use Closure;
use Illuminate\Http\Request;

final class ResolveCompany
{
    public function __construct(private readonly CurrentCompany $current)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $cfg = config('ammp.tenancy');
        $resolver = new CompanyResolver(
            new DbCompanyDirectory(),
            $cfg['mode'] === 'fixed' ? ($cfg['fixed_company_id'] ?? throw new \RuntimeException('TENANCY_FIXED_COMPANY_ID is required in fixed mode.')) : null,
            $cfg['base_domain'],
        );
        $slug = $request->header('X-Workspace');
        // Plain download links (<a href>) can't send headers: they carry ?workspace= instead. Same trust as the header (the session still decides access).
        if ($slug === null && $request->isMethod('GET') && is_string($request->query('workspace'))) {
            $slug = $request->query('workspace');
        }
        // ponytail: local-dev only — a bare loopback or private-LAN IP (emulator 10.0.2.2, a phone on the same Wi-Fi hitting 192.168.x.x) names no company,
        // so use the configured dev workspace. Still resolved and validated like any slug (unknown slug = rejected). Never active outside APP_ENV=local.
        if ($slug === null && $cfg['mode'] !== 'fixed' && app()->environment('local') && self::isLocalHost($request->getHost())) {   // fixed mode already has its one company
            $slug = $cfg['local_workspace'];
        }
        $id = $resolver->resolve($request->getHost(), $slug);
        $company = Company::query()->whereKey($id)->where('status', 'active')->firstOrFail();
        $this->current->set($company);
        return $next($request);
    }

    private static function isLocalHost(string $host): bool
    {
        $ip = trim($host, '[]');
        return $host === 'localhost'
            || (filter_var($ip, FILTER_VALIDATE_IP) !== false && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);
    }
}
