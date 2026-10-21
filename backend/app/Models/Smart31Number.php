<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A company number in the Smart31 rotation pool; team_ids empty = usable by every group. */
class Smart31Number extends Model
{
    use BelongsToCompany;

    protected $table = 'smart31_numbers';

    protected $fillable = ['number', 'label', 'team_ids', 'active', 'expires_on', 'last_used_at', 'calls', 'source', 'type', 'group_name', 'usage_date'];

    protected function casts(): array
    {
        return ['team_ids' => 'array', 'active' => 'boolean', 'expires_on' => 'date', 'last_used_at' => 'datetime', 'usage_date' => 'date'];
    }
}
