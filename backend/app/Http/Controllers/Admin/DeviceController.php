<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Management\AmapiClient;
use App\Models\DeviceApprovalEvent;
use App\Models\DeviceCommand;
use App\Models\ManagedDevice;
use App\Services\DeviceApprovalService;
use App\Support\Audit;
use Illuminate\Http\Request;

final class DeviceController extends Controller
{
    public function index(Request $r)
    {
        $teams = $r->user()->supervisedTeamIds();
        return response()->json(ManagedDevice::query()
            ->when($teams !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('team_id', $teams)->orWhere('approval_state', 'pending')))
            ->when($r->query('state'), fn ($q, $s) => $q->where('approval_state', $s))
            ->with(['assignedUser:id,name,employee_code', 'team:id,name'])
            ->orderByRaw("approval_state = 'pending' desc")->orderByDesc('id')
            ->paginate(100));
    }

    /** Supervisors see only devices in their teams (plus pending requests, which carry no work data). */
    public static function scopedDevice(Request $r, int $id): ManagedDevice
    {
        $teams = $r->user()->supervisedTeamIds();
        return ManagedDevice::query()->with(['assignedUser:id,name,employee_code', 'team:id,name'])
            ->when($teams !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('team_id', $teams)->orWhere('approval_state', 'pending')))
            ->findOrFail($id);
    }

    public function show(Request $r, int $id)
    {
        $d = self::scopedDevice($r, $id);
        return response()->json([
            'device' => $d,
            'history' => DeviceApprovalEvent::query()->where('device_id', $id)->latest('id')->limit(50)->get(),
            // Last sign-in on this phone and how it ended ('rotated' = routine token refresh, not a sign-out).
            'last_session' => \App\Models\WorkSession::query()->where('device_id', $id)->where(fn ($q) => $q->whereNull('end_reason')->orWhere('end_reason', '!=', 'rotated'))
                ->latest('id')->first(['id', 'user_id', 'epoch', 'created_at', 'ended_at', 'end_reason', 'refresh_expires_at']),
        ]);
    }

    public function approve(Request $r, int $id, DeviceApprovalService $svc)
    {
        $data = $r->validate(['code' => 'required|string|max:20', 'user_id' => 'required|integer', 'team_id' => 'nullable|integer',
            'asset_tag' => 'nullable|string|max:64', 'profile_id' => 'nullable|integer']);
        $d = $svc->approve(self::scopedDevice($r, $id), $r->user(), $data['user_id'], $data['team_id'] ?? null,
            $data['asset_tag'] ?? null, $data['profile_id'] ?? null, $data['code']);
        return response()->json(['state' => $d->approval_state->value]);
    }

    public function reject(Request $r, int $id, DeviceApprovalService $svc)
    {
        $svc->reject(self::scopedDevice($r, $id), $r->user(), $r->validate(['reason' => 'required|string|max:255'])['reason']);
        return response()->json(['ok' => true]);
    }

    public function reassign(Request $r, int $id, DeviceApprovalService $svc)
    {
        $data = $r->validate(['user_id' => 'required|integer', 'team_id' => 'nullable|integer']);
        $svc->reassign(self::scopedDevice($r, $id), $r->user(), $data['user_id'], $data['team_id'] ?? null);
        return response()->json(['ok' => true]);
    }

    public function revoke(Request $r, int $id, DeviceApprovalService $svc)
    {
        $svc->revoke(self::scopedDevice($r, $id), $r->user(), $r->validate(['reason' => 'required|string|max:255'])['reason']);
        return response()->json(['ok' => true, 'note' => 'Access revoked. The device is NOT wiped and management restrictions remain.']);
    }

    /** Separate, typed confirmation; only for AMAPI-managed devices. */
    public function wipe(Request $r, int $id, AmapiClient $amapi)
    {
        $data = $r->validate(['confirm_asset' => 'required|string', 'reason' => 'required|string|min:10|max:200']);
        $d = self::scopedDevice($r, $id);
        if ($d->management_mode !== 'amapi' || !$d->amapi_device_name) {
            throw new DomainException('wipe_unsupported', 'Wipe is only available for devices enrolled in Android Management.');
        }
        if (!hash_equals((string) ($d->asset_tag ?? $d->installation_id), $data['confirm_asset'])) {
            throw new DomainException('confirmation_mismatch', 'Type the device asset tag exactly to confirm.');
        }
        $cmd = DeviceCommand::query()->create(['device_id' => $d->id, 'type' => 'amapi_wipe', 'status' => 'sent', 'requested_by' => $r->user()->id, 'payload' => ['reason' => $data['reason']]]);
        Audit::record('device.wipe_requested', $d, ['reason' => $data['reason'], 'command_id' => $cmd->id]);
        $amapi->wipe($d, $data['reason']);
        return response()->json(['ok' => true]);
    }

    public function enrolmentToken(Request $r, AmapiClient $amapi)
    {
        $data = $r->validate(['policy' => 'nullable|string|max:60']);
        return response()->json($amapi->createEnrollmentToken($data['policy'] ?? 'enrolment-default'));
    }
}
