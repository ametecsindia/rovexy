<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PolicyAssignment extends Model
{
    use BelongsToCompany;

    protected $table = 'policy_assignments';

    protected $fillable = ['profile_id','target_type','target_id'];

    protected function casts(): array
    {
        return [];
    }
}
