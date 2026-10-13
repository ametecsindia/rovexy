<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Domain\Devices\ApprovalState;
use App\Http\Controllers\Controller;
use App\Models\DeviceCommand;
use App\Models\PushRegistration;
use App\Services\DeviceRegistrationService;
use App\Services\SessionService;
use Illuminate\Http\Request;

final class DeviceController extends Controller
{
    /** Unauthenticated bootstrap. Returns ONLY the matching code and state — never credentials. */
    public function register(Request $r, DeviceRegistrationService $svc)
    {
        $data = $r->validate([
            'installation_id' => 'required|string|size:36',
            'public_key' => 'required|string|max:400',
            'manufacturer' => 'nullable|string|max:64', 'model' => 'nullable|string|max:64',
            'os_version' => 'nullable|string|max:32', 'sdk_int' => 'nullable|integer|min:1|max:99',
            'app_version' => 'nullable|string|max:32', 'amapi_device_name' => 'nullable|string|max:200',
        ]);
        $d = $svc->register($data);
        return response()->json($this->statusBody($d), $d->wasRecentlyCreated ? 201 : 200);
    }

    /** Signed polling. Backoff hint grows with age; the client adds jitter. */
    public function status(Request $r)
    {
        return response()->json($this->statusBody($this->device($r)));
    }

    public function session(Request $r, SessionService $svc)
    {
        return response()->json($svc->createDeviceSession($this->device($r)));
    }

    public function pushToken(Request $r)
    {
        $data = $r->validate(['token' => 'required|string|min:20|max:4096', 'provider' => 'in:fcm']);
        $device = $this->device($r);
        $hash = hash('sha256', $data['token']);
        // A token belongs to exactly one device: if it moved (reinstall/reassignment), detach it from the old record.
        app(\App\Support\CurrentCompany::class)->withoutScope(fn () => PushRegistration::query()->where('token_hash', $hash)->where('device_id', '!=', $device->id)->update(['status' => 'disabled']));
        PushRegistration::query()->updateOrCreate(['device_id' => $device->id, 'provider' => 'fcm'], ['token' => $data['token'], 'token_hash' => $hash, 'status' => 'active', 'last_error' => null]);
        return response()->json(['ok' => true]);
    }

    public function ackCommand(Request $r, int $id)
    {
        $data = $r->validate(['result' => 'required|in:acknowledged,failed', 'detail' => 'nullable|string|max:200']);
        $cmd = DeviceCommand::query()->where('device_id', $this->device($r)->id)->findOrFail($id);
        $cmd->forceFill(['status' => $data['result'], 'acknowledged_at' => now(), 'result' => $data['detail'] ?? null])->save();
        return response()->json(['ok' => true]);
    }

    private function statusBody($d): array
    {
        $age = ($d->created_at ?? now())->diffInSeconds(now(), true);
        return [
            'state' => $d->approval_state->value,
            'approval_code' => $d->approval_state === ApprovalState::Pending ? $d->approval_code : null,
            'poll_after_seconds' => $d->approval_state === ApprovalState::Pending ? (int) min(60, max(5, $age / 10)) : null,
        ];
    }
}
