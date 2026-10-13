<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PolicyProfile extends Model
{
    use BelongsToCompany;

    protected $table = 'policy_profiles';

    protected $fillable = ['name','description'];

    protected function casts(): array
    {
        return [];
    }

    public function versions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PolicyVersion::class, 'profile_id');
    }

    public function published(): ?PolicyVersion
    {
        return $this->versions()->where('status', 'published')->orderByDesc('version')->first();
    }
}
