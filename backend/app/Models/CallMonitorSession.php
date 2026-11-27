<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One "listen in" on a live Click2Call call: who listened, on which phone, and whether the gateway added them. */
class CallMonitorSession extends Model
{
    use BelongsToCompany;

    protected $fillable = ['call_id', 'user_id', 'listen_number', 'status', 'error'];

    protected $hidden = ['listen_number'];   // a supervisor's own phone: shown masked only

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
