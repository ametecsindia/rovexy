<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\Call;
use App\Models\Smart31Number;
use Illuminate\Support\Facades\DB;

/**
 * G15: billable calling add-ons per month. A Click2Call user = someone who placed at least one real (not test)
 * Click2Call call that month (₹350); a Smart31 user = one of them whose call went out on a Smart31 rotation number
 * (₹550 instead of ₹350). Counting people who actually used it, not who merely had access, keeps the bill provable.
 */
final class CallingUsage
{
    /** Counts for the bound company (cloud workspace or the one company of an on-prem install). */
    public static function forMonth(string $month): array
    {
        [$from, $to] = [\Illuminate\Support\Carbon::parse("{$month}-01")->startOfMonth(), \Illuminate\Support\Carbon::parse("{$month}-01")->endOfMonth()];
        $calls = Call::query()->where('provider', 'click2call')->where('is_test', false)->whereBetween('initiated_at', [$from, $to]);
        $pool = Smart31Number::query()->pluck('number')->all();
        return ['month' => $month, 'c2c_users' => (clone $calls)->distinct()->count('user_id'),
            'smart31_users' => $pool ? (clone $calls)->whereIn('caller_number', $pool)->distinct()->count('user_id') : 0];
    }

    /** What an on-prem install reports at check-in: this month and last month (last month's count can still grow on the 1st). */
    public static function report(): array
    {
        return [self::forMonth(now()->format('Y-m')), self::forMonth(now()->subMonthNoOverflow()->format('Y-m'))];
    }

    /** Licence server: store what an install reported (never lowers a count). */
    public static function record(int $licenceId, mixed $usage): void
    {
        foreach (is_array($usage) ? $usage : [] as $u) {
            if (!is_array($u) || !preg_match('/^\d{4}-\d{2}$/', (string) ($u['month'] ?? ''))) continue;
            $key = ['licence_id' => $licenceId, 'month' => $u['month']];
            $row = DB::table('licence_usage')->where($key)->first();
            $vals = ['c2c_users' => max((int) ($row->c2c_users ?? 0), max(0, (int) ($u['c2c_users'] ?? 0))),
                'smart31_users' => max((int) ($row->smart31_users ?? 0), max(0, (int) ($u['smart31_users'] ?? 0))), 'updated_at' => now()];
            $row ? DB::table('licence_usage')->where($key)->update($vals) : DB::table('licence_usage')->insert($key + $vals + ['created_at' => now()]);
        }
    }

    /** Amount for a month's counts, with today's prices (Super Admin → Settings). GST is added on the invoice. */
    public static function amount(int $c2c, int $smart31): float
    {
        $p = (float) PlatformSettings::get('price_c2c_user');
        $ps = (float) PlatformSettings::get('price_smart31_user');
        return round(max(0, $c2c - $smart31) * $p + $smart31 * $ps, 2);
    }
}
