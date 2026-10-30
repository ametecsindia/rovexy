<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Team;

/**
 * The console's Branch / Team / Employee filter: ?org_branch= ?org_team= ?org_user= on any GET. It only ever narrows what
 * the signed-in user may already see (User::teamsInView / usersInView). Ignored on writes, so it never touches authorisation.
 */
final class OrgFilter
{
    private static function id(string $key): ?int
    {
        $r = request();
        $v = $r?->isMethod('GET') ? $r->query($key) : null;
        return is_string($v) && ctype_digit($v) ? (int) $v : null;
    }

    public static function userId(): ?int
    {
        return self::id('org_user');
    }

    /** @return list<int>|null null = no branch/team filter */
    public static function teamIds(): ?array
    {
        if (($t = self::id('org_team')) !== null) {
            return [$t];
        }
        if (($b = self::id('org_branch')) !== null) {
            return Team::query()->where('branch_id', $b)->pluck('id')->map(fn ($i) => (int) $i)->all();
        }
        return null;
    }

    public static function active(): bool
    {
        return self::userId() !== null || self::teamIds() !== null;
    }
}
