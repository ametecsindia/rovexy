<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Recording extends Model
{
    use BelongsToCompany;

    protected $table = 'recordings';

    protected $fillable = ['call_id','status','source_path','object_key','format','mime','size_bytes','sha256','duration_seconds','attempts','last_error','legal_hold','retention_expires_at','available_at','purged_at'];

    protected function casts(): array
    {
        return ['legal_hold' => 'bool','retention_expires_at' => 'datetime','available_at' => 'datetime','purged_at' => 'datetime'];
    }

    public function call(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
