<?php

declare(strict_types=1);

namespace App\Services\Platform;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide settings (key → value) for the Super Admin side. Secrets are stored encrypted and are never sent to the
 * browser — the console only learns whether one is set.
 */
final class PlatformSettings
{
    public const DEFAULTS = [
        'seller_name' => 'Ametecs India Private Limited',
        'seller_address' => 'Modern Profound Techpark, Ground Floor, HIVE Business Space, Whitefields, Kondapur, Hyderabad, Telangana 500084',
        'seller_gstin' => '',
        'seller_state' => '36',          // Telangana
        'seller_email' => 'sales@ametecsindia.com',
        'seller_phone' => '+91 90000 98877',
        'invoice_prefix' => 'SC',
        'gst_rate' => '18',
        'trial_days' => '14',
        'trial_users' => '5',
        'grace_days' => '7',
        // per-user pricing (Pricing): cloud rates in the plan ladders are the ANNUAL-billing ₹/user/month
        'price_annual_discount_pct' => '25',       // annual is this much cheaper than quarterly (the undiscounted base)
        'price_half_yearly_discount_pct' => '10',  // half-yearly discount off the base
        'setup_base' => '5000',                    // setup & onboarding: base + per user above the included count
        'setup_included_users' => '25',
        'setup_per_extra' => '100',
        'setup_on_cloud' => '0',                   // 1 = cloud first orders also carry the setup fee (on-prem always does)
        'amc_pct' => '18',                         // perpetual AMC per year, % of the current perpetual price
        'quote_validity_days' => '7',              // an unpaid order's pay link works this long
        'razorpay_key_id' => '',
        // website text (blank = the built-in wording)
        'site_announcement' => '',
        'site_hero_title' => '',
        'site_hero_text' => '',
        'site_meta_description' => '',
        // outgoing e-mail (SMTP). Blank host = use MAIL_* from backend/.env
        'mail_host' => '',
        'mail_port' => '587',
        'mail_encryption' => 'tls',      // tls (STARTTLS, port 587) | ssl (port 465) | none
        'mail_username' => '',
        'mail_from_address' => '',
        'mail_from_name' => 'ROVEXY',
        // automation
        'reminders_enabled' => '1',      // renewal / trial / unpaid-order e-mails (ammp:reminders, hourly)
        'trial_verify_email' => '1',     // no longer read: trials and website purchases always confirm the e-mail with a code
        // security
        'staff_mfa_required' => '0',     // 1 = every staff / accountant sign-in needs a 2-step code (first sign-in enrols)
        // push notifications (G3): where the Firebase credential came from — 'settings' (pasted here) | 'licence'
        'fcm_source' => '',
    ];

    public const SECRETS = ['razorpay_key_secret', 'razorpay_webhook_secret', 'mail_password', 'fcm_service_account_json'];

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = DB::table('platform_settings')->where('key', $key)->value('value');
        if ($v === null || $v === '') {
            return $default ?? (self::DEFAULTS[$key] ?? null);
        }
        return in_array($key, self::SECRETS, true) ? Crypt::decryptString($v) : $v;
    }

    public static function set(string $key, ?string $value): void
    {
        $stored = $value !== null && $value !== '' && in_array($key, self::SECRETS, true) ? Crypt::encryptString($value) : $value;
        DB::table('platform_settings')->updateOrInsert(['key' => $key], ['value' => $stored, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** What the console may see: plain values, and only "set / not set" for secrets. */
    public static function forConsole(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $k) {
            $out[$k] = self::get($k);
        }
        foreach (self::SECRETS as $k) {
            $out[$k . '_set'] = DB::table('platform_settings')->where('key', $k)->whereNotNull('value')->where('value', '!=', '')->exists();
        }
        $out['mail_configured'] = self::mailReady();
        $out['push'] = \App\Services\PushCredential::summary();
        return $out;
    }

    /** True when e-mails really leave the server: SMTP saved here, or a real mailer in .env. */
    public static function mailReady(): bool
    {
        return (bool) self::get('mail_host') || !in_array(config('mail.default'), app()->runningUnitTests() ? ['log', null] : ['log', 'array', null], true);   // tests read the 'array' mailer's messages
    }

    /**
     * Point Laravel's mailer at the SMTP server saved in Super Admin → Settings. Runs when the mailer is first needed
     * (AppServiceProvider), so ordinary requests never touch these settings. Blank host = keep backend/.env.
     */
    public static function applyMail(): void
    {
        try {
            $host = self::get('mail_host');
            if (!$host) {
                return;
            }
            $enc = self::get('mail_encryption');
            config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'scheme' => $enc === 'ssl' ? 'smtps' : 'smtp',
                'host' => $host, 'port' => (int) self::get('mail_port'), 'username' => self::get('mail_username') ?: null,
                'password' => self::get('mail_password') ?: null, 'timeout' => 20] + ($enc === 'none' ? ['auto_tls' => false] : []),
                'mail.from' => ['address' => self::get('mail_from_address') ?: self::get('mail_username') ?: config('mail.from.address'), 'name' => self::get('mail_from_name') ?: 'ROVEXY']]);
        } catch (\Throwable) {
            // settings table not migrated yet: keep .env
        }
    }
}
