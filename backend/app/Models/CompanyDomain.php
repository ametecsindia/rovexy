<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CompanyDomain extends Model
{
    use BelongsToCompany;

    protected $table = 'company_domains';

    protected $fillable = ['host','verification_token','verified_at'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }
}
