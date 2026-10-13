<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use Illuminate\Database\Eloquent\Model;

/** A place money is kept: bank account, cash box, UPI, payment gateway. Balance = opening + income − expense (completed). */
class Account extends Model
{
    protected $table = 'acc_accounts';

    public const TYPES = ['bank', 'cash', 'upi', 'card', 'gateway', 'other'];

    protected $fillable = ['name', 'type', 'bank_name', 'account_number', 'ifsc', 'opening_balance', 'opening_date', 'is_default', 'status', 'notes'];

    protected function casts(): array
    {
        return ['opening_balance' => 'float', 'opening_date' => 'date:Y-m-d', 'is_default' => 'boolean'];
    }
}
