<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DeviceCommand extends Model
{
    use BelongsToCompany;

    protected $table = 'device_commands';

    protected $fillable = ['device_id','type','payload','status','requested_by','expires_at','acknowledged_at','result'];

    protected function casts(): array
    {
        return ['payload' => 'array','expires_at' => 'datetime','acknowledged_at' => 'datetime'];
    }
}
