<?php

declare(strict_types=1);

use App\Domain\DomainException;
use App\Domain\Tenancy\CompanyDirectory;
use App\Domain\Tenancy\CompanyResolver;
use PHPUnit\Framework\TestCase;

final class TenancyTest extends TestCase
{
    private function dir(): CompanyDirectory
    {
        return new class implements CompanyDirectory {
            public function idForVerifiedDomain(string $host): ?int
            {
                return ['mobile.acme.in' => 1, 'mdm.globex.com' => 2][$host] ?? null;
            }

            public function idForSlug(string $slug): ?int
            {
                return ['acme' => 1, 'globex' => 2][$slug] ?? null;
            }
        };
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (DomainException $e) {
            return $e->reason;
        }
        return 'none';
    }

    public function test_resolves_verified_domain_and_workspace_slug_never_a_subdomain(): void
    {
        $r = new CompanyResolver($this->dir());
        self::assertSame(1, $r->resolve('mobile.acme.in'));
        self::assertSame(2, $r->resolve('ammp.example', 'GLOBEX'));
        self::assertSame('company_unresolved', $this->code(fn () => $r->resolve('globex.ammp.example:443')));   // sub-domains name no company
    }

    public function test_rejects_unresolved_and_conflicting_identity(): void
    {
        $r = new CompanyResolver($this->dir());
        self::assertSame('company_unresolved', $this->code(fn () => $r->resolve('unknown.example')));
        self::assertSame('company_unresolved', $this->code(fn () => $r->resolve('ammp.example', 'nosuch')));
        self::assertSame('company_conflict', $this->code(fn () => $r->resolve('mobile.acme.in', 'globex')));
        self::assertSame('company_unresolved', $this->code(fn () => $r->resolve('ammp.example', '../etc')));
    }

    public function test_fixed_company_mode_never_switches_company(): void
    {
        $r = new CompanyResolver($this->dir(), 1);
        self::assertSame(1, $r->resolve('anything.local'));
        self::assertSame(1, $r->resolve('anything.local', 'acme'));
        self::assertSame('company_conflict', $this->code(fn () => $r->resolve('x', 'globex')));
    }
}
