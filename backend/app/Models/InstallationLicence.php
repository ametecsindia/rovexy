<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** On-prem install: the licence it last verified (one row). The token is re-verified on every read — see LicenceClient. */
class InstallationLicence extends Model
{
    protected $table = 'installation_licence';

    protected $fillable = ['licence_key', 'key_saved_at', 'token', 'source', 'status', 'last_checked_at', 'unreachable_since', 'last_error', 'installed_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['key_saved_at' => 'datetime', 'last_checked_at' => 'datetime', 'unreachable_since' => 'datetime', 'installed_at' => 'datetime'];
    }
}
