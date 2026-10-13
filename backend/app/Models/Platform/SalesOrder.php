<?php

declare(strict_types=1);

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrder extends Model
{
    protected $fillable = ['number', 'kind', 'plan_id', 'licence_id', 'company_id', 'buyer_company', 'buyer_name', 'buyer_email', 'buyer_phone',
        'buyer_gstin', 'state_code', 'workspace', 'coupon_code', 'discount', 'admin_password', 'amount', 'cgst', 'sgst', 'igst', 'total', 'status', 'payment_method', 'payment_ref',
        'razorpay_order_id', 'razorpay_payment_id', 'paid_at', 'provisioned_at', 'invoice_number', 'created_by',
        'attempts', 'failed_at', 'failure_reason', 'cancelled_at', 'cancel_reason', 'list_price', 'pricing', 'custom_price_id',
        'deployment', 'licence_kind', 'billing', 'users', 'lines', 'valid_until'];

    protected $appends = ['pay_state'];

    protected $hidden = ['admin_password'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'discount' => 'float', 'cgst' => 'float', 'sgst' => 'float', 'igst' => 'float', 'total' => 'float', 'paid_at' => 'datetime', 'provisioned_at' => 'datetime', 'failed_at' => 'datetime', 'cancelled_at' => 'datetime', 'attempts' => 'integer', 'list_price' => 'float', 'custom_price_id' => 'integer',
            'users' => 'integer', 'lines' => 'array', 'valid_until' => 'date:Y-m-d'];
    }

    /** What people see: paid | cancelled | failed (last attempt failed, can be retried) | pending (not paid yet). */
    public function getPayStateAttribute(): string
    {
        return match (true) {
            $this->status === 'paid' => 'paid',
            $this->status === 'cancelled' => 'cancelled',
            $this->expiredQuote() => 'expired',
            $this->failed_at !== null => 'failed',
            default => 'pending',
        };
    }

    /** An unpaid order past its validity date: the pay link no longer takes payment (staff raise a fresh one). */
    public function expiredQuote(): bool
    {
        return $this->status === 'created' && $this->valid_until !== null && $this->valid_until->copy()->endOfDay()->isPast();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function licence(): BelongsTo
    {
        return $this->belongsTo(Licence::class);
    }

    /** Pay / invoice link token: no login needed, can't be guessed from the order number. */
    public function token(): string
    {
        return substr(hash_hmac('sha256', 'pay:' . $this->number, (string) config('app.key')), 0, 32);
    }

    public function payUrl(): string
    {
        return url("/pay/{$this->number}/{$this->token()}");
    }
}
