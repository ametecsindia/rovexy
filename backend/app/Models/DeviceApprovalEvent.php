<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DeviceApprovalEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'device_approval_events';

    public const UPDATED_AT = null;

    protected $fillable = ['device_id','action','actor_user_id','reason','meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }
}
