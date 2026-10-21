<?php

declare(strict_types=1);

namespace App\Models\C2c;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A company number from an Add Voucher (LeadGER obd_did); usable by the groups in team_ids until it expires. */
class Vmn extends Model
{
    use BelongsToCompany;

    protected $table = 'c2c_vmns';

    protected $fillable = ['virtual_number', 'click2call', 'webrtc', 'obd', 'tc_verified', 'team_ids', 'expires_at', 'voucher_id'];

    protected function casts(): array
    {
        return ['click2call' => 'boolean', 'webrtc' => 'boolean', 'obd' => 'boolean', 'tc_verified' => 'boolean', 'team_ids' => 'array', 'expires_at' => 'date'];
    }

    /** Usable until the end of its expiry day. */
    public function isAvailable(): bool
    {
        return !$this->expires_at || $this->expires_at->copy()->endOfDay()->gte(now());
    }
}
