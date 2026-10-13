<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

/** Lookup port implemented by the Laravel layer (DB) and by tests (arrays). Returns company IDs only for ACTIVE companies. */
interface CompanyDirectory
{
    public function idForVerifiedDomain(string $host): ?int;

    public function idForSlug(string $slug): ?int;
}
