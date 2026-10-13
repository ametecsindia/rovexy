<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PushRegistration extends Model
{
    use BelongsToCompany;

    protected $table = 'push_registrations';

    protected $fillable = ['device_id','provider','token','token_hash','status','last_error','last_success_at'];

    protected function casts(): array
    {
        return ['token' => 'encrypted','last_success_at' => 'datetime'];
    }
}
