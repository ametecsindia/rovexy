<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\DomainException;

/**
 * Resolves exactly one company for a request, or throws. Never falls back.
 *
 * Order: fixed-company mode → verified custom domain → workspace slug (X-Workspace header / ?workspace=, from the
 * /w/<slug>/ address). There are no per-company sub-domains.
 * If both a domain and a slug resolve and disagree, the request is rejected.
 */
final class CompanyResolver
{
    public function __construct(
        private readonly CompanyDirectory $directory,
        private readonly ?int $fixedCompanyId = null,
    ) {
    }

    public function resolve(string $host, ?string $explicitSlug = null): int
    {
        $host = strtolower(rtrim(trim($host), '.'));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        $slug = $explicitSlug !== null ? strtolower(trim($explicitSlug)) : null;
        if ($slug === '') {
            $slug = null;
        }
        if ($slug !== null && !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug)) {
            throw new DomainException('company_unresolved', 'Invalid workspace slug.');
        }

        if ($this->fixedCompanyId !== null) {
            // On-prem fixed mode: a slug, if sent, must still be this company's.
            if ($slug !== null && $this->directory->idForSlug($slug) !== $this->fixedCompanyId) {
                throw new DomainException('company_conflict', 'Workspace does not belong to this server.');
            }
            return $this->fixedCompanyId;
        }

        $byDomain = $host !== '' ? $this->directory->idForVerifiedDomain($host) : null;

        $bySlug = $slug !== null ? $this->directory->idForSlug($slug) : null;

        if ($slug !== null && $bySlug === null) {
            throw new DomainException('company_unresolved', 'Unknown workspace.');
        }
        if ($byDomain !== null && $bySlug !== null && $byDomain !== $bySlug) {
            throw new DomainException('company_conflict', 'Domain and workspace identify different companies.');
        }
        $id = $byDomain ?? $bySlug;
        if ($id === null) {
            throw new DomainException('company_unresolved', 'Company could not be resolved.');
        }
        return $id;
    }
}
