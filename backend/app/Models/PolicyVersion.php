<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PolicyVersion extends Model
{
    use BelongsToCompany;

    protected $table = 'policy_versions';

    protected $fillable = ['profile_id','version','settings','status','conflicts','notes','created_by','published_by','published_at','rolled_back_from_version_id'];

    protected function casts(): array
    {
        return ['settings' => 'array','conflicts' => 'array','published_at' => 'datetime'];
    }
}
