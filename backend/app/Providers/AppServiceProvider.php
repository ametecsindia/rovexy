<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\CurrentCompany;
use App\Telephony\TelephonyManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentCompany::class);   // reset per request and per queued job
        $this->app->singleton(TelephonyManager::class);
    }

    public function boot(): void
    {
        $this->guardProductionConfig();
        // Live numbers: any change to a case, outcome/payment, callback or assignment tells open consoles to refresh
        // (dashboard, reports, payments, customers). IDs only; screens re-fetch through their own APIs.
        // ponytail: one broadcast per company per type per second, so a 10k-row import is one ping, not 10k.
        $feeds = [\App\Models\Customer::class => 'case.changed', \App\Models\CustomerAssignment::class => 'case.changed', \App\Models\CustomerEvent::class => 'case.changed',
            \App\Models\FollowUp::class => 'case.changed', \App\Models\CallDisposition::class => 'payment.changed'];
        foreach ($feeds as $model => $type) {
            $model::saved(static function ($m) use ($type) {
                $cid = $m->company_id ?? null;
                if ($cid !== null && \Illuminate\Support\Facades\Cache::add("feed:{$cid}:{$type}", 1, 1)) {
                    \App\Events\AdminFeed::dispatchFor((int) $cid, $type, []);
                }
            });
        }
        // One password rule everywhere a password is set (console, staff, employees, website sign-up and buy).
        \Illuminate\Validation\Rules\Password::defaults(fn () => \Illuminate\Validation\Rules\Password::min(8));   // any characters, at least 8
        // Links built from a request (set-password, console, payment) keep https even when a proxy (Cloudflare "Flexible",
        // a load balancer) reaches PHP over plain http.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
        // SMTP from Super Admin → Settings, applied only when something actually sends mail.
        $this->app->afterResolving('mail.manager', fn () => \App\Services\Platform\PlatformSettings::applyMail());

        $cfg = config('ammp.rate_limits');
        $device = fn (Request $r) => (string) $r->header('X-Installation-Id', $r->ip());
        RateLimiter::for('bootstrap', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('register', fn (Request $r) => Limit::perHour($cfg['register_per_ip_per_hour'])->by($r->ip()));
        RateLimiter::for('status', fn (Request $r) => [Limit::perMinute($cfg['status_poll_per_device_per_minute'])->by('dev:' . $device($r)), Limit::perMinute(300)->by($r->ip())]);
        RateLimiter::for('login', fn (Request $r) => [Limit::perMinute($cfg['login_per_device_per_minute'])->by('dev:' . ($r->attributes->get('device')?->id ?? $r->ip())), Limit::perHour(30)->by('code:' . app(\App\Support\CurrentCompany::class)->id() . ':' . $r->input('employee_code'))]);
        RateLimiter::for('admin-login', fn (Request $r) => [Limit::perMinute(5)->by($r->ip()), Limit::perHour(20)->by('email:' . app(\App\Support\CurrentCompany::class)->id() . ':' . strtolower((string) $r->input('email')))]);
        RateLimiter::for('calls', fn (Request $r) => Limit::perMinute(10)->by('u:' . $r->user()?->id));
        RateLimiter::for('chat', fn (Request $r) => Limit::perMinute(60)->by('u:' . $r->user()?->id));
        RateLimiter::for('maps', fn (Request $r) => Limit::perMinute(30)->by('u:' . $r->user()?->id));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(5)->by('u:' . $r->user()?->id));
        RateLimiter::for('telephony', fn (Request $r) => Limit::perMinute(3000)->by($r->ip()));
        // Public website: by IP only. `throttle:10,1` would call $request->user(), and a visitor who is also signed in to a
        // company console on the same host has a session user that can't be loaded outside that company (500 on /buy).
        RateLimiter::for('site', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('site-pay', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
    }

    /** Production must never silently fall back to simulated success. */
    private function guardProductionConfig(): void
    {
        if (!$this->app->isProduction()) {
            return;
        }
        $problems = [];
        if (config('ammp.telephony.driver') === 'simulator') {
            $problems[] = 'TELEPHONY_DRIVER=simulator';
        }
        if (config('ammp.push.driver') === 'log') {
            $problems[] = 'PUSH_DRIVER=log';
        }
        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG=true';
        }
        if (!str_starts_with((string) config('app.url'), 'https://')) {
            $problems[] = 'APP_URL must be https://';
        }
        if ($problems !== []) {
            throw new \RuntimeException('Refusing to boot in production with: ' . implode(', ', $problems));
        }
    }
}
