<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PolicyAcknowledgement extends Model
{
    use BelongsToCompany;

    protected $table = 'policy_acknowledgements';

    public $timestamps = false;

    protected $fillable = ['device_id','fingerprint','source','results','state','compliant','reported_at'];

    protected function casts(): array
    {
        return ['results' => 'array','compliant' => 'bool','reported_at' => 'datetime'];
    }
}
