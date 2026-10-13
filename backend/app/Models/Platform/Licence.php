<?php

declare(strict_types=1);

namespace App\Models\Platform;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Licence extends Model
{
    protected $fillable = ['key', 'company_id', 'plan_id', 'holder_name', 'holder_email', 'kind', 'max_users', 'features', 'status',
        'starts_at', 'expires_at', 'grace_days', 'notes',
        'deployment', 'billing', 'amc_expires_at', 'server_fingerprint', 'activated_at', 'last_validated_at', 'reported_users'];

    protected function casts(): array
    {
        return ['features' => 'array', 'starts_at' => 'datetime', 'expires_at' => 'datetime', 'max_users' => 'integer', 'grace_days' => 'integer',
            'amc_expires_at' => 'datetime', 'activated_at' => 'datetime', 'last_validated_at' => 'datetime', 'reported_users' => 'integer'];
    }

    /** Past expiry + grace (perpetual and internal never expire). */
    public function expired(): bool
    {
        return $this->expires_at !== null && now()->greaterThan($this->expires_at->copy()->addDays($this->grace_days));
    }

    /** Updates & support cover: always for non-perpetual licences, AMC date for perpetual ones. */
    public function amcActive(): bool
    {
        return $this->kind !== 'perpetual' || ($this->amc_expires_at !== null && $this->amc_expires_at->isFuture());
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LicenceEvent::class)->latest('id');
    }

    public function log(string $type, array $data = [], ?string $actor = null): void
    {
        $this->events()->create(['type' => $type, 'data' => $data, 'actor' => $actor, 'created_at' => now()]);
    }
}
