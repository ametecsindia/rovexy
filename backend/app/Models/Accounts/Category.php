<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $table = 'acc_categories';

    protected $fillable = ['kind', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'system' => 'boolean'];
    }
}
