<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DeviceSession extends Model
{
    use BelongsToCompany;

    protected $table = 'device_sessions';

    public const UPDATED_AT = null;

    protected $fillable = ['device_id','token_hash','epoch','expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
