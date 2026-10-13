<?php

declare(strict_types=1);

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

/** Discount code: percent or flat ₹ off the package price (before GST). */
class Coupon extends Model
{
    protected $fillable = ['code', 'type', 'value', 'plan_ids', 'new_only', 'max_uses', 'used_count', 'valid_from', 'valid_until', 'active', 'note'];

    protected function casts(): array
    {
        return ['value' => 'float', 'plan_ids' => 'array', 'new_only' => 'boolean', 'active' => 'boolean', 'valid_from' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d',
            'max_uses' => 'integer', 'used_count' => 'integer'];
    }

    /** Why this coupon can't be used for $plan on a new / renewal order, or null when it can. */
    public function problem(Plan $plan, bool $renewal): ?string
    {
        return match (true) {
            !$this->active => 'This code is no longer active.',
            $this->valid_from !== null && $this->valid_from->isFuture() => 'This code is not valid yet.',
            $this->valid_until !== null && $this->valid_until->endOfDay()->isPast() => 'This code has expired.',
            $this->max_uses !== null && $this->used_count >= $this->max_uses => 'This code has been fully used.',
            $this->plan_ids !== null && $this->plan_ids !== [] && !in_array($plan->id, $this->plan_ids, true) => 'This code does not apply to the ' . $plan->name . ' package.',
            $this->new_only && $renewal => 'This code is for new purchases only.',
            default => null,
        };
    }

    /** ₹ off a package price (never more than the price). */
    public function discountOn(float $price): float
    {
        return round(min($price, $this->type === 'percent' ? $price * $this->value / 100 : $this->value), 2);
    }
}
