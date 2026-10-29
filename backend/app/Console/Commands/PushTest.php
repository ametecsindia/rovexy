<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ManagedDevice;
use App\Models\PushRegistration;
use App\Models\User;
use App\Services\PushService;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

/** Checks the Firebase credential (Super Admin / licence, G3) and sends one test push to an employee's phone: php artisan ammp:push-test EMP001 */
final class PushTest extends Command
{
    protected $signature = 'ammp:push-test {employee_code} {--reason=chat : chat (shows newest message) or media (rings for a pending call)}';
    protected $description = 'Check Firebase push settings and send a test push to one employee\'s phone.';

    public function handle(CurrentCompany $current, PushService $push): int
    {
        $driver = PushService::driver();
        $sum = \App\Services\PushCredential::summary();
        $this->line("Push driver = {$driver}");
        $this->line('Firebase credential = ' . ($sum['set'] ? "{$sum['project_id']} / {$sum['client_email']} (from {$sum['source']})" : '(not set!)'));
        if ($driver !== 'fcm' || !$sum['set']) {
            $this->error('No Firebase credential. Licence server: Super Admin → Settings → Push notifications. On-prem: Plan & licence → Check now (it arrives with the licence).');
            return self::FAILURE;
        }
        $test = \App\Services\PushCredential::test();
        $test['ok'] ? $this->info($test['detail']) : $this->error($test['detail']);
        if (!$test['ok']) return self::FAILURE;
        $sent = 0;
        foreach (Company::query()->get() as $company) {
            $current->run($company, function () use ($push, &$sent) {
                $user = User::query()->where('employee_code', $this->argument('employee_code'))->first();
                if (!$user) return;
                $devices = ManagedDevice::query()->where('assigned_user_id', $user->id)->get();
                if ($devices->isEmpty()) { $this->warn("{$user->name}: no phone assigned."); return; }
                foreach ($devices as $d) {
                    $reg = PushRegistration::query()->where('device_id', $d->id)->first();
                    if (!$reg || $reg->status !== 'active') {
                        $this->warn("{$user->name} / device #{$d->id}: phone has not registered for Firebase yet (open the app and sign in once)." . ($reg ? " Status: {$reg->status} {$reg->last_error}" : ''));
                        continue;
                    }
                    $ok = $push->ping($d, $this->option('reason'));
                    $reg->refresh();
                    $ok ? $this->info("{$user->name} / device #{$d->id}: SENT ✔") : $this->error("{$user->name} / device #{$d->id}: FAILED — {$reg->last_error}");
                    $sent += $ok ? 1 : 0;
                }
            });
        }
        if ($sent === 0) $this->warn('Nothing was sent.');
        return $sent > 0 ? self::SUCCESS : self::FAILURE;
    }
}
