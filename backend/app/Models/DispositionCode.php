<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DispositionCode extends Model
{
    use BelongsToCompany;

    protected $table = 'disposition_codes';

    public $timestamps = false;

    protected $fillable = ['code','label','requires_follow_up','active','captures'];

    protected function casts(): array
    {
        return ['requires_follow_up' => 'bool','active' => 'bool'];
    }
}
