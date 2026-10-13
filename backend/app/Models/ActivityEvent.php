<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ActivityEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'activity_events';

    public $timestamps = false;

    protected $fillable = ['device_id','user_id','type','occurred_at','meta'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime','meta' => 'array'];
    }
}
