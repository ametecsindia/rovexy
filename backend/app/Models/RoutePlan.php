<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** G8: the admin's visiting order for one field employee on one day; the phone shows it once published (G9). */
class RoutePlan extends Model
{
    use BelongsToCompany;

    protected $fillable = ['user_id', 'plan_date', 'status', 'start_lat', 'start_lng', 'stops', 'distance_m', 'duration_s', 'points', 'legs',
        'optimised', 'stops_hash', 'created_by', 'published_at'];

    protected function casts(): array
    {
        return ['plan_date' => 'date:Y-m-d', 'stops' => 'array', 'points' => 'array', 'legs' => 'array', 'optimised' => 'boolean',
            'start_lat' => 'float', 'start_lng' => 'float', 'published_at' => 'datetime'];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
