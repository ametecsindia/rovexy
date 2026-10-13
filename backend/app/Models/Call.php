<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Call extends Model
{
    use BelongsToCompany;

    protected $table = 'calls';

    protected $fillable = ['uuid','provider','provider_call_id','user_id','device_id','customer_id','destination','which_number','calling_mode','agent_leg','state','hangup_cause','failure_reason','initiated_at','agent_answered_at','ringing_at','answered_at','ended_at','ring_seconds','talk_seconds','total_seconds','recording_required','recording_status','disposition_status'];

    protected function casts(): array
    {
        return ['initiated_at' => 'datetime','agent_answered_at' => 'datetime','ringing_at' => 'datetime','answered_at' => 'datetime','ended_at' => 'datetime','recording_required' => 'bool'];
    }

    public function recording(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Recording::class);
    }

    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CallEvent::class);
    }

    public function disposition(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CallDisposition::class);
    }

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, \App\Domain\Calling\CallLifecycle::TERMINAL, true);
    }

    /** Snapshot in the shape the domain CallLifecycle expects. */
    public function lifecycleSnapshot(): array
    {
        $f = fn ($d) => $d?->utc()->format('Y-m-d\\TH:i:s\\Z');
        return ['state' => $this->state, 'initiated_at' => $f($this->initiated_at), 'agent_answered_at' => $f($this->agent_answered_at),
            'ringing_at' => $f($this->ringing_at), 'answered_at' => $f($this->answered_at), 'ended_at' => $f($this->ended_at), 'hangup_cause' => $this->hangup_cause];
    }
}
