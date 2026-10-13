<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Devices\ApprovalState;
use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManagedDevice extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'asset_tag', 'installation_id', 'amapi_device_name', 'manufacturer', 'model', 'os_version', 'sdk_int', 'app_version',
        'approval_state', 'approval_code', 'approval_code_expires_at', 'management_mode', 'management_status', 'capabilities',
        'credential_epoch', 'assigned_user_id', 'team_id', 'desired_policy_fingerprint', 'applied_policy_fingerprint',
        'last_good_policy_fingerprint', 'policy_state', 'compliant', 'compliance_detail', 'presence', 'work_state',
        'battery_pct', 'network_type', 'last_contact_at', 'last_ip',
    ];

    protected function casts(): array
    {
        return [
            'approval_state' => ApprovalState::class,
            'approval_code_expires_at' => 'datetime',
            'capabilities' => 'array',
            'compliance_detail' => 'array',
            'compliant' => 'bool',
            'last_contact_at' => 'datetime',
        ];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(DeviceCredential::class, 'device_id');
    }

    public function activeCredential(): ?DeviceCredential
    {
        return $this->credentials()->where('status', 'active')->where('epoch', $this->credential_epoch)->first();
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function deviceFacts(): array
    {
        return [
            'management' => $this->management_mode,
            'sdk' => (int) ($this->sdk_int ?? 0),
            'usb_data_signaling' => (bool) data_get($this->capabilities, 'usb_data_signaling', false),
        ];
    }
}
