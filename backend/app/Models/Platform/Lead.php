<?php

declare(strict_types=1);

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    public const STATUSES = ['new', 'contacted', 'demo', 'quoted', 'won', 'lost'];

    protected $fillable = ['name', 'company', 'email', 'phone', 'users', 'message', 'source', 'status', 'notes', 'follow_up_on'];

    protected function casts(): array
    {
        return ['follow_up_on' => 'date:Y-m-d'];
    }
}
