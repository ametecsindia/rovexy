<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    use BelongsToCompany;

    protected $table = 'import_jobs';

    protected $fillable = ['uploaded_by','original_name','stored_key','format','size_bytes','sha256','headers','mapping','options','status','total_rows','valid_rows','error_rows','created_count','updated_count','skipped_count','failure','started_at','finished_at','portfolio'];

    protected function casts(): array
    {
        return ['headers' => 'array','mapping' => 'array','options' => 'array','started_at' => 'datetime','finished_at' => 'datetime'];
    }

    public function rowErrors(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ImportRowError::class);
    }
}
