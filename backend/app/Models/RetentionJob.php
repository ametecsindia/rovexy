<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class RetentionJob extends Model
{
    use BelongsToCompany;

    protected $table = 'retention_jobs';

    protected $fillable = ['kind','status','scope','planned_by','approved_by','executed_count','started_at','finished_at'];

    protected function casts(): array
    {
        return ['scope' => 'array','started_at' => 'datetime','finished_at' => 'datetime'];
    }
}
