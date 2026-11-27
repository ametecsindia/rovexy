<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An angry-call (or other risk) alert from SmartRMI, acknowledged by a supervisor in the console. */
class RmiAlert extends Model
{
    use BelongsToCompany;

    protected $fillable = ['call_id', 'user_id', 'kind', 'severity', 'message', 'details', 'emailed_to', 'acknowledged_by', 'acknowledged_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'acknowledged_at' => 'datetime'];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
