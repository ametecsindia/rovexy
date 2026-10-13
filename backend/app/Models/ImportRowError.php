<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ImportRowError extends Model
{
    use BelongsToCompany;

    protected $table = 'import_row_errors';

    public $timestamps = false;

    protected $fillable = ['import_job_id','row_number','field','code','message'];

    protected function casts(): array
    {
        return [];
    }
}
