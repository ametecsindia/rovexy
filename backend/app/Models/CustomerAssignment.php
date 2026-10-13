<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CustomerAssignment extends Model
{
    use BelongsToCompany;

    protected $table = 'customer_assignments';

    public const UPDATED_AT = null;

    protected $fillable = ['customer_id','user_id','team_id','action','actor_user_id','import_job_id'];

    protected function casts(): array
    {
        return [];
    }
}
