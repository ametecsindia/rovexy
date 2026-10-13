<?php

declare(strict_types=1);

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

class LicenceEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['licence_id', 'type', 'data', 'actor', 'created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }
}
