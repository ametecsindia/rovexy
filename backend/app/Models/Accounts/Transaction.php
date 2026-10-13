<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line in the books. `amount` is always positive; `kind` (income | expense) gives the direction. */
class Transaction extends Model
{
    protected $table = 'acc_transactions';

    public const METHODS = ['bank', 'upi', 'cash', 'cheque', 'card', 'razorpay', 'other'];

    protected $fillable = ['kind', 'txn_date', 'amount', 'tax_amount', 'category_id', 'account_id', 'party', 'method', 'reference', 'description', 'status',
        'source', 'source_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['txn_date' => 'date:Y-m-d', 'amount' => 'float', 'tax_amount' => 'float'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
