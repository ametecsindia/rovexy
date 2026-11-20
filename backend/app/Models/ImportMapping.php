<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A saved column mapping for one bank / file format (Portfolios → upload → "Save this mapping"). */
class ImportMapping extends Model
{
    use BelongsToCompany;

    protected $fillable = ['name', 'portfolio_type', 'header_row', 'headers', 'fields', 'ignored', 'keep_other_columns', 'created_by', 'last_used_at'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'fields' => 'array', 'ignored' => 'array', 'keep_other_columns' => 'boolean', 'last_used_at' => 'datetime'];
    }
}
