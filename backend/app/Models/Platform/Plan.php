<?php

declare(strict_types=1);

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

/**
 * A tier (Starter / Business / Enterprise = a feature set) with two price ladders: cloud ₹/user/month by size band and
 * on-prem perpetual one-time price by band (see Pricing). price_inr / months / max_users are the old fixed-package columns,
 * kept only for orders and licences made before 13 Oct 2026.
 */
class Plan extends Model
{
    protected $fillable = ['code', 'name', 'tagline', 'price_inr', 'months', 'max_users', 'min_users', 'features', 'is_public', 'active', 'sort'];

    public function cloudTiers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PlanCloudTier::class)->orderBy('min_users');
    }

    public function perpetualBands(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PlanPerpetualBand::class)->orderBy('min_users');
    }

    protected function casts(): array
    {
        return ['features' => 'array', 'is_public' => 'boolean', 'active' => 'boolean', 'price_inr' => 'float', 'months' => 'integer', 'max_users' => 'integer', 'min_users' => 'integer'];
    }

    /** Can be bought at all (price ladders decide whether a given size is priced or a custom quote). */
    public function sellable(): bool
    {
        return $this->active;
    }
}
