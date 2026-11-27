<?php

declare(strict_types=1);

namespace App\Models\C2c;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A voucher added from the Ametecs licence server (LeadGER obd_credits_history). */
class Voucher extends Model
{
    use BelongsToCompany;

    protected $table = 'c2c_vouchers';

    protected $fillable = ['voucher_code', 'numbers', 'smart31_numbers', 'expires_at', 'created_by', 'status', 'last_ok_at', 'checked_at', 'revoked_at', 'status_message'];

    protected function casts(): array
    {
        return ['expires_at' => 'date', 'last_ok_at' => 'datetime', 'checked_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** The LC Manager still allows it: active, not past its expiry day, and confirmed within the grace period. */
    public function licensed(): bool
    {
        return ($this->status ?? 'active') === 'active'
            && (!$this->expires_at || $this->expires_at->copy()->endOfDay()->gte(now()))
            && ($this->last_ok_at ?? $this->created_at)?->gte(now()->subHours(\App\Telephony\LcGuard::GRACE_HOURS)) === true;
    }
}
