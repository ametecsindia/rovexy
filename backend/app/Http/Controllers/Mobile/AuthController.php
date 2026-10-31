<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\PolicyService;
use App\Services\SessionService;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function login(Request $r, SessionService $svc)
    {
        $data = $r->validate(['employee_code' => 'required|string|max:64', 'password' => 'required|string|max:200',
            'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180', 'accuracy_m' => 'nullable|numeric|min:0']);
        $f = fn (string $k) => isset($data[$k]) ? (float) $data[$k] : null;
        return response()->json($svc->login($this->device($r), $data['employee_code'], $data['password'], $f('lat'), $f('lng'), $f('accuracy_m')));
    }

    public function refresh(Request $r, SessionService $svc)
    {
        $data = $r->validate(['refresh_token' => 'required|string|max:100']);
        return response()->json($svc->refresh($this->device($r), $data['refresh_token']));
    }

    public function logout(Request $r, SessionService $svc)
    {
        $svc->logout($this->workSession($r));
        return response()->json(['ok' => true, 'note' => 'Signed out. Device management restrictions remain in force.']);
    }

    /** Includes the employee-facing monitoring disclosure derived from the effective policy. */
    public function me(Request $r, PolicyService $policies)
    {
        $d = $this->device($r);
        $v = $policies->currentSnapshot($d)->values;
        $u = $r->user();
        return response()->json([
            'employee' => ['id' => $u->id, 'name' => $u->name, 'employee_code' => $u->employee_code],
            'device' => ['id' => $d->id, 'asset_tag' => $d->asset_tag, 'compliant' => $d->compliant, 'policy_state' => $d->policy_state,
                'failing_mandatory' => data_get($d->compliance_detail, 'failing_mandatory', [])],
            'calling_route' => \App\Services\CallService::routes($u)[0] ?? 'sim',   // default (older app builds use only this)
            'calling_routes' => \App\Services\CallService::routes($u),             // options this employee may use; two = they choose
            // Click2Call: company numbers this employee may call from (picked in the call dialog), and their default.
            'click2call' => in_array('server', \App\Services\CallService::routes($u), true) && \App\Telephony\Click2CallSettings::active() ? [
                'numbers' => array_values(array_filter(\App\Telephony\Click2CallSettings::numbersFor($u), fn ($n) => $n['available'])),
                'default' => \App\Telephony\Click2CallAdapter::normalize($u->c2c_default_number) ?: null,
                'smart31' => \App\Telephony\Click2CallSettings::smart31On() && \App\Telephony\Click2CallSettings::allows($u, 'smart31'),
            ] : null,
            'sim_recorder' => config('ammp.telephony.sim_recorder') === 'app' ? 'app' : 'phone',
            'today' => (function () use ($u) {
                // "Today" in the company's time zone (IST), the same day the dashboard and reports use.
                $tz = app(\App\Support\CurrentCompany::class)->get()->timezone ?: config('app.timezone');
                $q = \App\Models\Call::query()->where('is_test', false)->where('provider', '!=', 'manual')->where('user_id', $u->id)->where('initiated_at', '>=', now($tz)->startOfDay()->utc());
                return ['calls' => (clone $q)->count(), 'connected' => (clone $q)->whereNotNull('answered_at')->count(), 'talk_seconds' => (int) (clone $q)->sum('talk_seconds'),
                    'follow_ups_due' => \App\Models\FollowUp::query()->where('user_id', $u->id)->where('status', 'open')->where('due_at', '<=', now($tz)->endOfDay()->utc())->count()];
            })(),
            'offline_lease_expires_at' => $this->workSession($r)->lease_expires_at->toAtomString(),
            'realtime' => config('broadcasting.default') === 'reverb' && config('broadcasting.connections.reverb.key') ? [
                'ws_url' => (config('broadcasting.connections.reverb.options.scheme') === 'https' ? 'wss' : 'ws') . '://' . config('broadcasting.connections.reverb.options.host')
                    . ':' . config('broadcasting.connections.reverb.options.port') . '/app/' . config('broadcasting.connections.reverb.key') . '?protocol=7&client=ammp-android&version=1',
                'channel' => "private-company.{$d->company_id}.device.{$d->id}",
            ] : null,
            'disclosure' => [
                'calls_recorded' => $v['recording.mode'] !== 'off',
                'recording_notice' => $v['recording.notice'],
                'location' => $v['location.enabled'] ? ['interval_seconds' => $v['location.interval_seconds'], 'background' => $v['location.background'], 'when' => 'Only while you are signed in (work session)'] : null,
                'live_view_possible' => $v['live_view.enabled'],
                'camera_sessions_possible' => $v['camera_session.enabled'],
                'statement' => 'Screen and camera sessions always ask you first and show an indicator while active. Nothing is captured in the background.',
            ],
        ]);
    }
}
