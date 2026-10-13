<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Tenancy\CompanyDirectory;
use Illuminate\Support\Facades\DB;

final class DbCompanyDirectory implements CompanyDirectory
{
    public function idForVerifiedDomain(string $host): ?int
    {
        $id = DB::table('company_domains')
            ->join('companies', 'companies.id', '=', 'company_domains.company_id')
            ->where('company_domains.host', $host)
            ->whereNotNull('company_domains.verified_at')
            ->where('companies.status', 'active')
            ->value('companies.id');
        return $id === null ? null : (int) $id;
    }

    public function idForSlug(string $slug): ?int
    {
        $id = DB::table('companies')->where('slug', $slug)->where('status', 'active')->value('id');
        return $id === null ? null : (int) $id;
    }
}
