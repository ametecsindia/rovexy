<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class MediaSession extends Model
{
    use BelongsToCompany;

    protected $table = 'media_sessions';

    protected $fillable = ['uuid','kind','requested_by','device_id','user_id','purpose','state','end_reason','timeout_minutes','options','protected_content_reported','requested_at','accepted_at','started_at','ended_at'];

    protected function casts(): array
    {
        return ['options' => 'array','protected_content_reported' => 'bool','requested_at' => 'datetime','accepted_at' => 'datetime','started_at' => 'datetime','ended_at' => 'datetime'];
    }

    public function snapshot(): array
    {
        return ['state' => $this->state, 'kind' => $this->kind, 'requested_at' => $this->requested_at->toAtomString(),
            'started_at' => $this->started_at?->toAtomString(), 'timeout_minutes' => $this->timeout_minutes];
    }
}
