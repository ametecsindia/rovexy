<?php

declare(strict_types=1);

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

class PlanPerpetualBand extends Model
{
    public $timestamps = false;

    protected $fillable = ['plan_id', 'min_users', 'max_users', 'price_inr'];

    protected function casts(): array
    {
        return ['min_users' => 'integer', 'max_users' => 'integer', 'price_inr' => 'float'];
    }
}
