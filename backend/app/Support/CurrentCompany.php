<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;

/**
 * Request/job-scoped tenant context. Registered as a scoped singleton (reset per request and per queued job).
 * Tenant models refuse to query while no company is bound, unless code explicitly opts into platform scope.
 */
final class CurrentCompany
{
    private ?Company $company = null;
    private int $bypassDepth = 0;

    public function set(Company $company): void
    {
        if ($this->company !== null && $this->company->id !== $company->id) {
            throw new \LogicException('Tenant context cannot be switched within a request.');
        }
        $this->company = $company;
    }

    public function get(): Company
    {
        return $this->company ?? throw new \LogicException('No company bound to this context.');
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }

    /** The company's time zone (IST by default). Every "today" / calendar date shown to people uses this; storage stays UTC. */
    public static function tz(): string
    {
        $c = app(self::class);
        return ($c->bound() ? $c->get()->timezone : null) ?: 'Asia/Kolkata';
    }

    public function bound(): bool
    {
        return $this->company !== null;
    }

    /** Runs $fn with $company bound, restoring the previous state afterwards (used by jobs and console commands). */
    public function run(Company $company, callable $fn): mixed
    {
        $prev = $this->company;
        $this->company = $company;
        try {
            return $fn();
        } finally {
            $this->company = $prev;
        }
    }

    /** Platform-level code paths (bootstrap lookups, platform admin) — keep these few and audited. */
    public function withoutScope(callable $fn): mixed
    {
        $this->bypassDepth++;
        try {
            return $fn();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function bypassing(): bool
    {
        return $this->bypassDepth > 0;
    }
}
