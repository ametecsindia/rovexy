<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CallEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'call_events';

    public const UPDATED_AT = null;

    protected $fillable = ['call_id','provider_event_id','type','occurred_at','payload'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime','payload' => 'array'];
    }
}
