<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class LocationEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'location_events';

    public $timestamps = false;

    protected $fillable = ['device_id','user_id','work_session_id','lat','lng','accuracy_m','provider','battery_pct','network_type','captured_at'];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime','received_at' => 'datetime'];
    }
}
