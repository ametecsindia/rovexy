<?php

use App\Jobs\ReconcileRecordings;
use App\Models\Company;
use App\Services\MediaSessionService;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// `php artisan test` (the collision package that normally provides it isn't installed): runs PHPUnit, passing any filter on.
Artisan::command('test {args?*}', function (array $args = []) {
    passthru(implode(' ', array_map('escapeshellarg', [PHP_BINARY, base_path('vendor/bin/phpunit'), ...$args])), $code);
    return $code;
})->purpose('Run the test suite (PHPUnit)');

Schedule::call(function () {
    Company::query()->where('status', 'active')->each(fn (Company $c) => ReconcileRecordings::dispatch($c->id));
})->everyTenMinutes()->name('recordings:reconcile')->withoutOverlapping();

Schedule::call(function () {
    $ctx = app(CurrentCompany::class);
    Company::query()->where('status', 'active')->each(fn (Company $c) => $ctx->run($c, fn () => app(MediaSessionService::class)->expireStale()));
})->everyMinute()->name('media:expire');

Schedule::call(function () {
    // Exotel results/recordings for calls still open (covers servers that Exotel's callback cannot reach, e.g. a LAN PC).
    $ctx = app(CurrentCompany::class);
    $ctx->withoutScope(fn () => \App\Models\Call::query()->where('provider', 'exotel')->where('initiated_at', '>', now()->subHours(3))
        ->where(fn ($q) => $q->whereNotIn('state', \App\Domain\Calling\CallLifecycle::TERMINAL)->orWhere('recording_status', 'pending'))->get())
        ->each(fn ($call) => $ctx->run(Company::query()->findOrFail($call->company_id), fn () => app(\App\Services\CallService::class)->syncProvider($call)));
})->everyMinute()->name('exotel:sync')->withoutOverlapping();

Schedule::call(function () {
    // Presence: a device with no contact for 3 minutes is offline (heartbeat every 60 s while the app runs).
    \Illuminate\Support\Facades\DB::table('managed_devices')->where('presence', 'online')->where('last_contact_at', '<', now()->subMinutes(3))->update(['presence' => 'offline']);
})->everyMinute()->name('presence:sweep');

Schedule::call(function () {
    // SmartPRS2 / SmartEPT field-force outbox: retries anything the after-response flush could not deliver.
    $ff = app(\App\Services\FieldForceIntegration::class);
    Company::query()->where('status', 'active')->each(fn (Company $c) => $ff->flushCompany($c));
})->everyMinute()->name('integrations:flush')->withoutOverlapping();

Schedule::command('ammp:reminders')->hourly()->name('platform:reminders')->withoutOverlapping();

// On-prem install: check the licence with the licence server every night (keeps the last verdict when it can't be reached).
Schedule::call(fn () => app(\App\Services\Licensing\LicenceClient::class)->check())
    ->dailyAt('01:00')->name('licence:check')->withoutOverlapping()->when(fn () => \App\Services\Licensing\LicenceClient::isClient());

// On-prem install: re-check every 10 minutes while a payment is pending (the upgrade lands by itself) or the licence server
// was unreachable (recovers as soon as it is back), instead of waiting for the nightly check.
Schedule::call(function () {
    $c = app(\App\Services\Licensing\LicenceClient::class);
    $row = $c->row();
    if ($row->unreachable_since || ($c->payload()['open_order'] ?? null)) {
        $c->check();
    }
})->everyTenMinutes()->name('licence:recheck')->withoutOverlapping()->when(fn () => \App\Services\Licensing\LicenceClient::isClient());

// Central: payments whose callback and webhook both went missing (closed browser + webhook outage) are settled from the
// gateway itself, so a customer who paid is never left waiting for staff.
Artisan::command('rovexy:payments-reconcile', function () {
    $n = 0;
    \App\Models\Platform\SalesOrder::query()->whereIn('status', ['created', 'cancelled'])->whereNotNull('razorpay_order_id')
        ->where('updated_at', '>', now()->subDays(7))->orderBy('id')->each(function ($o) use (&$n) {
            $n += \App\Services\Platform\Razorpay::settle($o, 'reconcile') === true ? 1 : 0;
        });
    $this->info("Settled {$n} payment(s).");
})->purpose('Settle Razorpay payments that no callback or webhook reported');
Schedule::command('rovexy:payments-reconcile')->everyTenMinutes()->name('payments:reconcile')->withoutOverlapping()
    ->when(fn () => !\App\Services\Licensing\LicenceClient::isClient());
