<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * type = invoice: we bill a customer (money in). type = bill: a vendor bills us (money out).
 * Stored status: draft | issued | partial | paid | cancelled. "Overdue" is derived (issued/partial past the due date).
 */
class Invoice extends Model
{
    protected $table = 'acc_invoices';

    protected $fillable = ['type', 'number', 'party_name', 'party_email', 'party_phone', 'party_gstin', 'party_address', 'issue_date', 'due_date', 'items',
        'subtotal', 'tax_rate', 'tax_amount', 'total', 'amount_paid', 'status', 'category_id', 'notes', 'created_by'];

    protected $appends = ['balance', 'is_overdue', 'display_status'];

    protected function casts(): array
    {
        return ['issue_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'items' => 'array', 'subtotal' => 'float', 'tax_rate' => 'float',
            'tax_amount' => 'float', 'total' => 'float', 'amount_paid' => 'float'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function getBalanceAttribute(): float
    {
        return round(max(0, $this->total - $this->amount_paid), 2);
    }

    public function getIsOverdueAttribute(): bool
    {
        return in_array($this->status, ['issued', 'partial'], true) && $this->due_date !== null && $this->due_date->toDateString() < \App\Services\Accounts\Ledger::today();
    }

    public function getDisplayStatusAttribute(): string
    {
        return $this->is_overdue ? 'overdue' : $this->status;
    }
}
