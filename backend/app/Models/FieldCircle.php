<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** G7: a field employee's own working area — a centre and a radius drawn by the admin. */
class FieldCircle extends Model
{
    use BelongsToCompany;

    protected $fillable = ['user_id', 'name', 'lat', 'lng', 'radius_m', 'updated_by'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'radius_m' => 'integer'];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Is this point inside the circle? */
    public function contains(?float $lat, ?float $lng): bool
    {
        return Customer::validCoords($lat, $lng) && \App\Services\GoogleMaps::straightMeters([$this->lat, $this->lng], [$lat, $lng]) <= $this->radius_m;
    }
}
