<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DeviceCredential extends Model
{
    use BelongsToCompany;

    protected $table = 'device_credentials';

    public const UPDATED_AT = null;

    protected $fillable = ['device_id','public_key_pem','fingerprint','epoch','status','revoked_at'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }
}
