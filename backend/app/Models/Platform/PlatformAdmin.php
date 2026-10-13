<?php

declare(strict_types=1);

namespace App\Models\Platform;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** Ametecs staff (Super Admin console, and Accountants on /accounts). Separate from company users; never tenant-scoped. */
class PlatformAdmin extends Authenticatable
{
    protected $table = 'platform_admins';

    protected $fillable = ['email', 'name', 'phone', 'password', 'role', 'status', 'last_login_at'];

    protected $hidden = ['password', 'totp_secret', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'last_login_at' => 'datetime', 'mfa_enabled_at' => 'datetime'];
    }

    public function isSuper(): bool
    {
        return $this->role === 'super';
    }
}
