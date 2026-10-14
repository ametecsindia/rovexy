<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Post-update health check (run by the updater before the app comes back up): the database answers and the schema is
 * current, routes load, every Blade view compiles, and the licence code runs. Exit 0 = healthy.
 */
final class HealthCheck extends Command
{
    protected $signature = 'rovexy:health-check';
    protected $description = 'Check that this ROVEXY install works (used after an update)';

    public function handle(): int
    {
        $checks = [
            'database' => fn () => DB::select('select 1 as ok')[0]->ok == 1,
            'migrations' => fn () => DB::table('migrations')->count() > 0 && !$this->pendingMigrations(),
            'routes' => fn () => app('router')->getRoutes()->count() > 20,
            'views' => fn () => $this->callSilently('view:cache') === 0,
            'licence' => fn () => is_array(app(\App\Services\Platform\Licensing::class)->current()),
        ];
        $ok = true;
        foreach ($checks as $name => $fn) {
            try {
                $pass = (bool) $fn();
            } catch (\Throwable $e) {
                $pass = false;
                $this->line("{$name}: " . $e->getMessage());
            }
            $this->line(($pass ? 'OK   ' : 'FAIL ') . $name);
            $ok = $ok && $pass;
        }
        $this->callSilently('view:clear');
        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function pendingMigrations(): bool
    {
        $ran = DB::table('migrations')->pluck('migration')->all();
        $files = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);
        return (bool) array_diff($files, $ran);
    }
}
