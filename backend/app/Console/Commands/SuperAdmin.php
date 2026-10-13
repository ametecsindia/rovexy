<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Platform\PlatformAdmin;
use Illuminate\Console\Command;

/** Creates (or resets the password of) an Ametecs staff account for the Super Admin console at /super. */
final class SuperAdmin extends Command
{
    protected $signature = 'ammp:super-admin {email} {--name=Super Admin} {--role=super : super | sales | support | accountant}';
    protected $description = 'Create a Super Admin (Ametecs staff) account, or reset its password.';

    public function handle(): int
    {
        if (!array_key_exists((string) $this->option('role'), \App\Http\Middleware\PlatformAccess::ROLES)) {
            $this->error('Role must be super, sales, support or accountant.');
            return self::FAILURE;
        }
        $password = (string) $this->secret('Password (min 12 characters)');
        if (strlen($password) < 12) {
            $this->error('Password too short.');
            return self::FAILURE;
        }
        $a = PlatformAdmin::query()->firstOrNew(['email' => strtolower($this->argument('email'))]);
        $a->fill(['name' => $a->exists ? $a->name : $this->option('name'), 'password' => $password, 'role' => $this->option('role'), 'status' => 'active'])->save();
        $this->info(($a->wasRecentlyCreated ? 'Created' : 'Updated') . " {$a->email} ({$a->role}). Sign in at " . url($a->role === 'accountant' ? '/accounts' : '/super'));
        return self::SUCCESS;
    }
}
