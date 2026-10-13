<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Domain\DomainException;
use App\Models\Platform\Licence;
use App\Models\User;
use App\Support\CurrentCompany;

/**
 * What the current company may do under its ROVEXY licence. Enforced on the server only:
 *  - users: active user accounts (employees, managers, admins) ≤ max_users  (0 = unlimited)
 *  - expiry: after expires_at the company has grace_days; after that phones can't sign in to work and new users / phones
 *    can't be added (the admin console stays open so the licence can be renewed)
 *  - features: plan feature keys; a route behind `feature:<key>` answers 403 feature_not_in_plan
 * The licence is the newest non-superseded row for this workspace. A company with no licence row at all is treated as unrestricted — every self-serve path (trial, buy) and
 * `ammp:create-company` creates one, so this only covers workspaces made before licensing existed.
 */
final class Licensing
{
    public const FEATURES = [
        'calling' => 'Calling from the app with outcomes and follow-ups',
        'recording' => 'Automatic call recording',
        'customers' => 'Lead upload, portfolios and assignment',
        'payments' => 'Payment capture with slips',
        'reports' => 'Reports and exports',
        'chat' => 'Messages between office and employees',
        'field_map' => 'Field map, nearest customer, routes and check-in',
        'live_map' => 'Live map of all employees and day routes',
        'media' => 'Live screen and camera sessions',
        'integrations' => 'SmartPRS / SmartEPT integration',
    ];

    private ?array $memo = null;

    public function __construct(private readonly CurrentCompany $current)
    {
    }

    /**
     * @return array{state:string, kind:?string, plan:?string, max_users:int, used_users:int, features:list<string>,
     *               expires_at:?string, grace_until:?string, key:?string, message:?string}
     */
    public function current(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }
        $lic = $this->licence();
        if ($lic === null) {
            return $this->memo = ['state' => 'unrestricted', 'kind' => null, 'plan' => null, 'max_users' => 0, 'used_users' => $this->usedUsers(),
                'features' => array_keys(self::FEATURES), 'expires_at' => null, 'grace_until' => null, 'key' => null, 'message' => null];
        }
        $expires = $lic['expires_at'] ? \Illuminate\Support\Carbon::parse($lic['expires_at']) : null;
        $graceUntil = $expires?->copy()->addDays((int) $lic['grace_days']);
        $state = match (true) {
            $lic['status'] !== 'active' => match ($lic['status']) {             // suspended | revoked | on-prem verdicts
                'licence_expired' => 'expired', 'licence_suspended' => 'suspended', 'licence_revoked', 'licence_superseded' => 'revoked', default => $lic['status'],
            },
            $expires === null || $expires->isFuture() => 'active',
            $graceUntil->isFuture() => 'grace',
            default => 'expired',
        };
        return $this->memo = [
            'state' => $state, 'kind' => $lic['kind'], 'plan' => $lic['plan'], 'max_users' => (int) $lic['max_users'], 'used_users' => $this->usedUsers(),
            'features' => array_values($lic['features']), 'expires_at' => $expires?->toAtomString(), 'grace_until' => $graceUntil?->toAtomString(),
            'key' => $lic['key'], 'message' => self::message($state, $lic['kind'], $graceUntil),
        ];
    }

    public function usable(): bool
    {
        return in_array($this->current()['state'], ['active', 'grace', 'unrestricted'], true);
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->current()['features'], true);
    }

    /** Before adding $adding active users (new employee, bulk upload, re-activation). */
    public function assertCanAddUsers(int $adding = 1): void
    {
        $this->assertUsable();
        $c = $this->current();
        if ($c['max_users'] > 0 && $c['used_users'] + $adding > $c['max_users']) {
            throw new DomainException('licence_user_limit', "Your ROVEXY plan allows {$c['max_users']} users and {$c['used_users']} are active"
                . ($adding > 1 ? " — this upload would add {$adding}" : '') . '. Deactivate users or upgrade the plan (Administration → Plan & licence).');
        }
    }

    public function assertUsable(): void
    {
        if (!$this->usable()) {
            throw new DomainException('licence_inactive', $this->current()['message'] ?? 'The ROVEXY licence is not active.');
        }
    }

    public function forget(): void
    {
        $this->memo = null;
    }

    private function licence(): ?array
    {
        if (\App\Services\Licensing\LicenceClient::isClient()) {
            return app(\App\Services\Licensing\LicenceClient::class)->effective();   // on-prem: the verdict from the licence server / file
        }
        $cid = $this->current->id();
        if ($cid === null) {
            return null;
        }
        $l = Licence::query()->where('company_id', $cid)->where('status', '!=', 'superseded')->with('plan:id,name')->latest('id')->first();
        return $l ? ['key' => $l->key, 'kind' => $l->kind, 'plan' => $l->plan?->name ?? ucfirst($l->kind), 'max_users' => $l->max_users, 'features' => $l->features ?? [],
            'status' => $l->status, 'expires_at' => $l->expires_at?->toAtomString(), 'grace_days' => $l->grace_days] : null;
    }

    private function usedUsers(): int
    {
        return $this->current->bound() ? User::query()->where('status', 'active')->count() : 0;
    }

    private static function message(string $state, ?string $kind, ?\Illuminate\Support\Carbon $graceUntil): ?string
    {
        $what = match ($kind) { 'trial' => 'free trial', 'evaluation' => 'evaluation period', 'unverified' => 'time to verify the licence key', default => 'ROVEXY licence' };
        return match ($state) {
            'active' => null,
            'grace' => "Your {$what} has ended. Renew before " . $graceUntil->format('d M Y') . ' to keep phones working.',
            'expired' => "Your {$what} has ended. Phones can't start work until it is renewed (Administration → Plan & licence).",
            'suspended' => 'Your ROVEXY licence is suspended. Contact Ametecs.',
            'revoked' => 'This ROVEXY licence is no longer valid. Contact Ametecs.',
            'unlicensed' => 'This ROVEXY install has no licence. Enter your licence key in Administration → Plan & licence.',
            'unknown_key' => 'The licence server does not know this licence key. Check it in Administration → Plan & licence, or contact Ametecs.',
            'server_mismatch' => 'This licence is registered to another server. Contact Ametecs to move it to this one.',
            'stale' => 'This server has not reached the ROVEXY licence server for ' . config('ammp.licence.offline_days') . ' days. Check its internet connection and click Check now, or install a licence file (Administration → Plan & licence).',
            default => 'The ROVEXY licence is not active.',
        };
    }
}
