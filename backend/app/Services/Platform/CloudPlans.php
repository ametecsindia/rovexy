<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\Platform\Plan;
use Illuminate\Support\Collection;

/**
 * ROVEXY Cloud (10 Nov 2026 rates): one product with every feature, two Google Maps models, optional Click2Call and
 * Smart31, billed quarterly in advance at the listed monthly rate. Each Maps model × telephony choice is one plan
 * (cloud_model = 'quarterly'), so orders, licences, renewals, more users and invoices run through the existing engine.
 * The prices live in each plan's cloud tiers (Super Admin → Plans & pricing); SEED only creates them the first time.
 */
final class CloudPlans
{
    public const MAPS = ['ametecs' => 'Ametecs Google Maps', 'own' => 'Own Google Maps'];

    public const TELEPHONY = ['none' => '', 'c2c' => ' + Click2Call', 'c2c_smart31' => ' + Click2Call + Smart31'];

    /** Seed rates (₹/user/month): base tiers per Maps model [min, max, rate] and telephony add-on per user per month. */
    public const SEED = [
        'ametecs' => [[5, 9, 350], [10, 49, 300], [50, 50, 250]],   // above 50: contact sales
        'own' => [[5, 100000, 150]],
    ];

    public const ADDON = ['none' => 0, 'c2c' => 350, 'c2c_smart31' => 550];

    public static function code(string $maps, string $tel): string
    {
        return 'cloud-' . $maps . ['none' => '', 'c2c' => '-c2c', 'c2c_smart31' => '-c2c-smart31'][$tel];
    }

    /** Every feature except the two telephony ones, which come with the add-on. */
    public static function features(string $tel): array
    {
        $base = array_values(array_diff(array_keys(Licensing::FEATURES), ['click2call', 'smart31']));
        return array_merge($base, ['none' => [], 'c2c' => ['click2call'], 'c2c_smart31' => ['click2call', 'smart31']][$tel]);
    }

    /** The website's cloud plans, keyed by code, with their tiers. */
    public static function forSite(): Collection
    {
        return Plan::query()->with('cloudTiers')->where('cloud_model', 'quarterly')->where('active', true)->where('is_public', true)->get()->keyBy('code');
    }
}
