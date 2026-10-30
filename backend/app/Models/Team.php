<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use BelongsToCompany;

    protected $table = 'teams';

    protected $fillable = ['code', 'name', 'branch_id'];

    protected function casts(): array
    {
        return [];
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('membership', 'company_id');
    }
}
