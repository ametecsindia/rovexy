<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Tenant root. Not itself tenant-scoped. */
class Company extends Model
{
    protected $fillable = ['slug', 'name', 'status', 'timezone', 'default_country', 'amapi_enterprise', 'settings', 'billing_email', 'billing_phone', 'gstin', 'state_code', 'address', 'setup_fee_paid'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(CompanyDomain::class);
    }

    public function branding(): HasOne
    {
        return $this->hasOne(CompanyBranding::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }
}
