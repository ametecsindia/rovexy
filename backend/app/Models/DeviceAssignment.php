<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DeviceAssignment extends Model
{
    use BelongsToCompany;

    protected $table = 'device_assignments';

    public $timestamps = false;

    protected $fillable = ['device_id','user_id','team_id','assigned_by','assigned_at','ended_at','end_reason'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
