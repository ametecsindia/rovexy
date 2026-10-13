<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DevicePolicySnapshot extends Model
{
    use BelongsToCompany;

    protected $table = 'device_policy_snapshots';

    public const UPDATED_AT = null;

    protected $fillable = ['device_id','fingerprint','values','sources','version_refs'];

    protected function casts(): array
    {
        return ['values' => 'array','sources' => 'array','version_refs' => 'array'];
    }
}
