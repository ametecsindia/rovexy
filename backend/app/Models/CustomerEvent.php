<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Activity on a case that is not a call: employee notes and status changes. */
class CustomerEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = ['customer_id', 'user_id', 'type', 'body', 'meta', 'created_at'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
