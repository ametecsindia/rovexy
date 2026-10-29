<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** G5: Google's answer for one address, kept so next month's upload of the same address costs nothing. */
class GeocodeResult extends Model
{
    use BelongsToCompany;

    protected $fillable = ['address_key', 'status', 'lat', 'lng', 'precision', 'formatted_address'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float'];
    }
}
