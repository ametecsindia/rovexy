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

    protected $fillable = ['voucher_code', 'numbers', 'smart31_numbers', 'expires_at', 'created_by'];

    protected function casts(): array
    {
        return ['expires_at' => 'date'];
    }
}
