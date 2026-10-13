<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkSession extends Model
{
    use BelongsToCompany;

    protected $table = 'work_sessions';

    public const UPDATED_AT = null;

    protected $fillable = ['device_id','user_id','access_hash','refresh_hash','epoch','access_expires_at','refresh_expires_at','lease_expires_at','last_validated_at','ended_at','end_reason'];

    protected function casts(): array
    {
        return ['access_expires_at' => 'datetime','refresh_expires_at' => 'datetime','lease_expires_at' => 'datetime','last_validated_at' => 'datetime','ended_at' => 'datetime'];
    }

    public function device(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ManagedDevice::class, 'device_id');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
