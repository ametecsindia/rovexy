<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\DomainException;
use App\Events\DeviceChannelEvent;
use App\Events\MediaSignal;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\LocationEvent;
use App\Models\ManagedDevice;
use App\Models\MediaSession;
use App\Models\User;
use App\Services\ChatService;
use App\Services\MediaSessionService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class SupervisionController extends Controller
{
    /** Status is reported as observed: signed-in ≠ working. "Idle"/"active" are in-app signals only. */
    public function presence(Request $r)
    {
        $teams = $r->user()->supervisedTeamIds();
        return response()->json(ManagedDevice::query()->where('approval_state', 'approved')
            ->when($teams !== null, fn ($q) => $q->whereIn('team_id', $teams))
            ->with('assignedUser:id,name,employee_code')
            ->get(['id', 'asset_tag', 'assigned_user_id', 'team_id', 'presence', 'work_state', 'battery_pct', 'network_type', 'last_contact_at', 'compliant', 'policy_state'])
            // ponytail: "online" = heard from in the last 10 min (a signed-in phone reports every 5 min while signed in); no presence job needed.
            ->map(fn ($d) => tap($d, fn ($d) => $d->presence = $d->last_contact_at?->gt(now()->subMinutes(10)) ? 'online' : 'offline'))
            ->sortByDesc('last_contact_at')->values());
    }

    /**
     * GET live-locations: every employee with an approved phone (in the viewer's groups) and their latest position from
     * the last 24 h, phone status and today's calls / field visits — for the all-employees live map. The console polls
     * with ?refresh=1, which is not audited again (the first load is).
     * ponytail: latest fix = highest id per employee (received order); a fix uploaded late from an offline queue can show
     * briefly as "latest" — order by captured_at per user if that ever matters.
     */
    public function live(Request $r)
    {
        $f = $r->validate(['date' => 'nullable|date_format:Y-m-d', 'team' => 'nullable|integer', 'employee' => 'nullable|integer', 'portfolio' => 'nullable|string|max:20']);
        $tz = app(\App\Support\CurrentCompany::class)->get()->timezone ?: config('app.timezone');
        $dayStart = \Illuminate\Support\Carbon::parse($f['date'] ?? now($tz)->toDateString(), $tz)->startOfDay()->utc();
        $isToday = $dayStart->equalTo(now($tz)->startOfDay()->utc());
        $dayEnd = $dayStart->copy()->addDay();
        $teams = $r->user()->supervisedTeamIds();
        $team = fn (array $ids) => \Illuminate\Support\Facades\DB::table('team_user')->whereIn('team_id', $ids)->pluck('user_id')->all();
        $pf = $f['portfolio'] ?? null;
        $pfCustomers = $pf === null ? null : \App\Models\Customer::query()->when($pf === 'manual', fn ($q) => $q->whereNull('last_import_id'), fn ($q) => $q->where('last_import_id', (int) $pf))->select('id');
        $devices = ManagedDevice::query()->where('approval_state', 'approved')->whereNotNull('assigned_user_id')
            ->when($teams !== null, fn ($q) => $q->whereIn('assigned_user_id', $team($teams)))
            ->when(!empty($f['team']), fn ($q) => $q->whereIn('assigned_user_id', $team([(int) $f['team']])))
            ->when(!empty($f['employee']), fn ($q) => $q->where('assigned_user_id', (int) $f['employee']))
            // portfolio: employees who hold cases from it
            ->when($pfCustomers, fn ($q) => $q->whereIn('assigned_user_id', \App\Models\Customer::query()->whereIn('id', clone $pfCustomers)->whereNotNull('assigned_user_id')->select('assigned_user_id')))
            ->with('assignedUser:id,name,employee_code', 'assignedUser.teams:id,name')
            ->orderByDesc('last_contact_at')->get()->unique('assigned_user_id');   // one row per employee: their most recently heard phone
        $ids = $devices->pluck('assigned_user_id')->all();
        // Today: latest fix in the last 24 h. Past day: last fix of that day.
        $fixes = LocationEvent::query()->whereIn('id', LocationEvent::query()->selectRaw('MAX(id)')->whereIn('user_id', $ids)
                ->where('captured_at', '>=', $isToday ? now()->subDay() : $dayStart)->where('captured_at', '<', $dayEnd)->groupBy('user_id'))
            ->get(['user_id', 'lat', 'lng', 'accuracy_m', 'captured_at', 'battery_pct', 'mock'])->keyBy('user_id');
        // G19: anyone whose phone sent a fake-GPS fix that day
        $mocked = LocationEvent::query()->whereIn('user_id', $ids)->where('mock', true)->where('captured_at', '>=', $dayStart)->where('captured_at', '<', $dayEnd)
            ->distinct()->pluck('user_id')->flip();
        $calls = \App\Models\Call::query()->where('is_test', false)->whereIn('user_id', $ids)->where('initiated_at', '>=', $dayStart)->where('initiated_at', '<', $dayEnd)->where('provider', '!=', 'manual')
            ->when($pfCustomers, fn ($q) => $q->whereIn('customer_id', clone $pfCustomers))
            ->selectRaw('user_id, COUNT(*) AS n, SUM(CASE WHEN answered_at IS NOT NULL THEN 1 ELSE 0 END) AS connected')->groupBy('user_id')->get()->keyBy('user_id');
        $visits = \App\Models\CustomerEvent::query()->whereIn('user_id', $ids)->where('type', 'visit')->where('created_at', '>=', $dayStart)->where('created_at', '<', $dayEnd)
            ->when($pfCustomers, fn ($q) => $q->whereIn('customer_id', clone $pfCustomers))
            ->selectRaw('user_id, COUNT(*) AS n')->groupBy('user_id')->pluck('n', 'user_id');
        if (!$r->boolean('refresh')) Audit::record('location.live_viewed', null, ['employees' => count($ids)] + $f);
        return response()->json(['at' => now()->toAtomString(), 'date' => $dayStart->copy()->setTimezone($tz)->toDateString(), 'today' => $isToday,
            'employees' => $devices->map(function (ManagedDevice $d) use ($fixes, $calls, $visits, $isToday, $mocked) {
            $fx = $fixes[$d->assigned_user_id] ?? null;
            $online = $d->last_contact_at?->gt(now()->subMinutes(10)) ?? false;
            return ['user_id' => $d->assigned_user_id, 'name' => $d->assignedUser?->name, 'employee_code' => $d->assignedUser?->employee_code,
                'groups' => $d->assignedUser?->teams->pluck('name')->values() ?? [], 'device' => $d->asset_tag ?? "Phone #{$d->id}",
                'presence' => $online ? 'online' : 'offline', 'work_state' => $d->work_state, 'battery_pct' => $fx?->battery_pct ?? $d->battery_pct, 'network_type' => $d->network_type,
                'last_contact_at' => $d->last_contact_at?->toAtomString(),
                'location' => $fx ? ['lat' => (float) $fx->lat, 'lng' => (float) $fx->lng, 'accuracy_m' => $fx->accuracy_m, 'captured_at' => $fx->captured_at->toAtomString(), 'mock' => (bool) $fx->mock] : null,
                'fake_gps' => isset($mocked[$d->assigned_user_id]),
                // live = a fix in the last 15 min (today only); stale = an older fix (or a past day's last fix); none = no fix
                'status' => !$fx ? 'none' : ($isToday && $fx->captured_at->gt(now()->subMinutes(15)) ? 'live' : 'stale'),
                'calls_today' => (int) ($calls[$d->assigned_user_id]->n ?? 0), 'connected_today' => (int) ($calls[$d->assigned_user_id]->connected ?? 0),
                'visits_today' => (int) ($visits[$d->assigned_user_id] ?? 0)];
        })->values()]);
    }

    /**
     * GET day-route?user_id=&date=YYYY-MM-DD (company time zone; default today): the employee's trail for the day, the
     * customers they checked in at (in order, with the customer's pin and how far from it they were), and day totals.
     * Distance skips GPS jumps (a point worse than ±100 m, or faster than 150 km/h from the last one).
     */
    public function dayRoute(Request $r)
    {
        $data = $r->validate(['user_id' => 'required|integer', 'date' => 'nullable|date_format:Y-m-d', 'portfolio' => 'nullable|string|max:20']);
        $pf = $data['portfolio'] ?? null;
        $pfCustomers = $pf === null ? null : \App\Models\Customer::query()->when($pf === 'manual', fn ($q) => $q->whereNull('last_import_id'), fn ($q) => $q->where('last_import_id', (int) $pf))->select('id');
        $employee = User::query()->findOrFail($data['user_id']);
        if (!$r->user()->canSeeUser($employee)) {
            throw new DomainException('forbidden');
        }
        $tz = app(\App\Support\CurrentCompany::class)->get()->timezone ?: config('app.timezone');
        $from = \Illuminate\Support\Carbon::parse($data['date'] ?? now($tz)->toDateString(), $tz)->startOfDay()->utc();
        $to = $from->copy()->addDay();
        Audit::record('location.viewed', $employee, ['date' => $from->toDateString(), 'via' => 'day_route']);
        $fixes = LocationEvent::query()->where('user_id', $employee->id)->where('captured_at', '>=', $from)->where('captured_at', '<', $to)
            ->orderBy('captured_at')->limit(5000)->get(['lat', 'lng', 'accuracy_m', 'captured_at']);
        $meters = 0.0;
        $prev = null;
        foreach ($fixes as $f) {
            if ($f->accuracy_m !== null && $f->accuracy_m > 100) continue;
            if ($prev) {
                $d = \App\Services\GoogleMaps::straightMeters([(float) $prev->lat, (float) $prev->lng], [(float) $f->lat, (float) $f->lng]);
                $secs = max(1, $prev->captured_at->diffInSeconds($f->captured_at));
                if ($d / $secs <= 42) $meters += $d;   // 42 m/s ≈ 150 km/h
            }
            $prev = $f;
        }
        $visits = \App\Models\CustomerEvent::query()->where('user_id', $employee->id)->where('type', 'visit')->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->when($pfCustomers, fn ($q) => $q->whereIn('customer_id', clone $pfCustomers))->orderBy('created_at')->get();
        $customers = \App\Models\Customer::query()->whereIn('id', $visits->pluck('customer_id'))->get(['id', 'customer_ref', 'name', 'address', 'area', 'city', 'lat', 'lng', 'status'])->keyBy('id');
        $calls = \App\Models\Call::query()->where('is_test', false)->where('user_id', $employee->id)->where('initiated_at', '>=', $from)->where('initiated_at', '<', $to)->where('provider', '!=', 'manual')
            ->when($pfCustomers, fn ($q) => $q->whereIn('customer_id', clone $pfCustomers));
        return response()->json([
            'date' => $from->copy()->setTimezone($tz)->toDateString(),
            'employee' => ['id' => $employee->id, 'name' => $employee->name, 'employee_code' => $employee->employee_code],
            'fixes' => $fixes->map(fn ($f) => [(float) $f->lat, (float) $f->lng, $f->captured_at->toAtomString(), $f->accuracy_m !== null ? (int) round($f->accuracy_m) : null])->values(),
            'visits' => $visits->values()->map(function ($v, $i) use ($customers) {
                $c = $customers[$v->customer_id] ?? null;
                $pin = $c && \App\Models\Customer::validCoords($c->lat, $c->lng) ? ['lat' => (float) $c->lat, 'lng' => (float) $c->lng] : null;
                return ['n' => $i + 1, 'at' => $v->created_at?->toAtomString(), 'customer_id' => $v->customer_id, 'name' => $c?->name, 'customer_ref' => $c?->customer_ref,
                    'address' => $c ? (implode(', ', array_filter([$c->address, $c->area, $c->city])) ?: null) : null, 'status' => $c?->status, 'pin' => $pin,
                    'checkin' => isset($v->meta['lat'], $v->meta['lng']) ? ['lat' => (float) $v->meta['lat'], 'lng' => (float) $v->meta['lng']] : $pin,
                    'distance_m' => $v->meta['distance_m'] ?? null, 'accuracy_m' => $v->meta['accuracy_m'] ?? null];
            }),
            'summary' => ['distance_m' => (int) round($meters), 'first_at' => $fixes->first()?->captured_at->toAtomString(), 'last_at' => $fixes->last()?->captured_at->toAtomString(),
                'fixes' => $fixes->count(), 'visits' => $visits->count(), 'customers' => $visits->pluck('customer_id')->unique()->count(),
                'calls' => (clone $calls)->count(), 'connected' => (clone $calls)->whereNotNull('answered_at')->count()],
        ]);
    }

    public function locations(Request $r)
    {
        $data = $r->validate(['user_id' => 'required|integer', 'from' => 'nullable|date', 'to' => 'nullable|date']);
        $employee = User::query()->findOrFail($data['user_id']);
        if (!$r->user()->canSeeUser($employee)) {
            throw new DomainException('forbidden');
        }
        Audit::record('location.viewed', $employee, array_intersect_key($data, ['from' => 1, 'to' => 1]));
        return response()->json(LocationEvent::query()->where('user_id', $employee->id)
            ->where('captured_at', '>=', $data['from'] ?? now()->startOfDay())->where('captured_at', '<', $data['to'] ?? now()->addMinute())
            ->orderBy('captured_at')->limit(2000)->get(['lat', 'lng', 'accuracy_m', 'captured_at', 'received_at', 'battery_pct'])
            ->map(fn ($l) => $l->toArray() + ['stale' => $l->captured_at->lt(now()->subMinutes(15))]));
    }

    public function mediaIndex(Request $r)
    {
        abort_unless($r->user()->hasPermission('media.screen') || $r->user()->hasPermission('media.camera') || $r->user()->hasPermission('chat.use') || $r->user()->hasPermission('audit.view'), 403);
        $teams = $r->user()->supervisedTeamIds();
        return response()->json(MediaSession::query()
            ->when($teams !== null, fn ($q) => $q->where(fn ($w) => $w->where('requested_by', $r->user()->id)
                ->orWhereIn('user_id', \Illuminate\Support\Facades\DB::table('team_user')->whereIn('team_id', $teams)->pluck('user_id'))))
            ->latest('id')->paginate(50));
    }

    public function mediaRequest(Request $r, MediaSessionService $svc)
    {
        $data = $r->validate(['device_id' => 'required_without:user_id|integer', 'user_id' => 'required_without:device_id|integer',
            'kind' => 'required|in:screen,camera,voice', 'purpose' => 'required_unless:kind,voice|nullable|string|max:255']);
        // From the Messages page we know the employee, not the phone: use their approved phone heard from most recently.
        $device = isset($data['device_id']) ? ManagedDevice::query()->findOrFail($data['device_id'])
            : ManagedDevice::query()->where('assigned_user_id', $data['user_id'])->where('approval_state', 'approved')->orderByDesc('last_contact_at')->first()
                ?? throw new DomainException('device_unavailable', 'This employee has no approved phone.');
        $s = $svc->request($r->user(), $device, $data['kind'], $data['purpose'] ?? 'Voice call');
        return response()->json($s, 201);
    }

    public function mediaEnd(Request $r, string $uuid, MediaSessionService $svc)
    {
        $s = $this->ownSession($r, $uuid);
        return response()->json($svc->act($s, 'end', 'supervisor_stopped', $r->user()));
    }

    public function mediaSignal(Request $r, string $uuid)
    {
        $s = $this->ownSession($r, $uuid);
        $data = $r->validate(['type' => 'required|in:offer,answer,candidate,bye', 'payload' => 'required|array']);
        abort_unless(in_array($s->state, ['accepted', 'active'], true), 409);
        MediaSignal::dispatchFor($s, 'supervisor', $data['type'], $data['payload']);
        DeviceChannelEvent::dispatchFor(ManagedDevice::query()->findOrFail($s->device_id), 'media.signal', ['session_id' => $s->uuid, 'type' => $data['type'], 'payload' => $data['payload']]);
        return response()->json(['ok' => true]);
    }

    public function mediaSignals(Request $r, string $uuid)
    {
        return response()->json(MediaSignal::inbox($this->ownSession($r, $uuid), 'device', (int) $r->query('after', 0)));
    }

    public function mediaIce(Request $r, string $uuid, MediaSessionService $svc)
    {
        return response()->json(['ice_servers' => $svc->iceServers($this->ownSession($r, $uuid))]);
    }

    /** Screenshot = a frame captured by the supervisor's console from the live, consented stream. */
    public function screenshot(Request $r, string $uuid)
    {
        $s = $this->ownSession($r, $uuid);
        if ($s->kind !== 'screen' || $s->state !== 'active' || !($s->options['screenshots'] ?? false)) {
            throw new DomainException('screenshot_not_permitted', 'Screenshots need an active screen session with screenshots allowed by policy.');
        }
        $r->validate(['image' => 'required|file|mimes:png,jpg,jpeg|max:4096']);
        $key = $r->file('image')->store("companies/{$s->company_id}/screenshots/{$s->uuid}", 'company_files');
        $sha = hash('sha256', (string) Storage::disk('company_files')->get($key));
        \Illuminate\Support\Facades\DB::table('media_screenshots')->insert(['company_id' => $s->company_id, 'media_session_id' => $s->id, 'object_key' => $key, 'sha256' => $sha, 'taken_by' => $r->user()->id, 'created_at' => now()]);
        Audit::record('media.screenshot', $s, ['sha256' => $sha]);
        DeviceChannelEvent::dispatchFor(ManagedDevice::query()->findOrFail($s->device_id), 'media.screenshot_taken', ['session_id' => $s->uuid]);
        return response()->json(['ok' => true], 201);
    }

    public function conversations(Request $r)
    {
        $uid = $r->user()->id;
        $convs = Conversation::query()->whereHas('participants', fn ($q) => $q->where('users.id', $uid))
            ->with('participants:id,name,employee_code')->orderByDesc('last_message_at')->limit(100)->get();
        $ids = $convs->pluck('id');
        $unread = $this->unreadRows($uid)->whereIn('m.conversation_id', $ids)->groupBy('m.conversation_id')
            ->selectRaw('m.conversation_id, count(*) as n')->get()->pluck('n', 'conversation_id');
        // Latest message per conversation, for the list preview.
        $last = \Illuminate\Support\Facades\DB::table('messages')->whereIn('id', \Illuminate\Support\Facades\DB::table('messages')->whereIn('conversation_id', $ids)
            ->whereNull('purged_at')->groupBy('conversation_id')->selectRaw('max(id)'))->get(['conversation_id', 'sender_user_id', 'body', 'attachment_name', 'created_at'])->keyBy('conversation_id');
        return response()->json($convs->map(fn ($c) => $c->toArray() + [
            'unread' => (int) ($unread[$c->id] ?? 0),
            'last' => ($m = $last[$c->id] ?? null) ? ['body' => $m->body ?? ('Attachment: ' . $m->attachment_name), 'mine' => (int) $m->sender_user_id === $uid,
                'at' => \Illuminate\Support\Carbon::parse($m->created_at)->toAtomString()] : null,
        ])->values());
    }

    /** Dashboard notice: messages sent to me that I have not opened yet, newest conversation first. */
    public function unread(Request $r)
    {
        $rows = $this->unreadRows($r->user()->id)->join('users as u', 'u.id', '=', 'm.sender_user_id')
            ->orderByDesc('m.id')->limit(300)->get(['m.conversation_id', 'm.body', 'm.attachment_name', 'm.created_at', 'u.name']);
        $items = $rows->groupBy('conversation_id')->map(fn ($g) => ['conversation_id' => $g[0]->conversation_id, 'from' => $g[0]->name,
            'body' => $g[0]->body ?? ('Attachment: ' . $g[0]->attachment_name), 'at' => \Illuminate\Support\Carbon::parse($g[0]->created_at)->toAtomString(), 'count' => $g->count()]);
        return response()->json(['total' => $rows->count(), 'items' => $items->values()->take(5)]);
    }

    public function readAll(Request $r)
    {
        \Illuminate\Support\Facades\DB::table('message_receipts')->where('user_id', $r->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
        return $this->unread($r);
    }

    private function unreadRows(int $uid): \Illuminate\Database\Query\Builder
    {
        return \Illuminate\Support\Facades\DB::table('message_receipts as r')->join('messages as m', 'm.id', '=', 'r.message_id')
            ->where('r.user_id', $uid)->whereNull('r.read_at')->whereNull('m.purged_at');
    }

    public function startConversation(Request $r, ChatService $chat)
    {
        $data = $r->validate(['user_id' => 'required|integer', 'subject' => 'nullable|string|max:150']);
        return response()->json($chat->start($r->user(), User::query()->findOrFail($data['user_id']), $data['subject'] ?? null), 201);
    }

    public function messages(Request $r, int $id, ChatService $chat)
    {
        return response()->json($chat->page($chat->conversationFor($r->user(), $id), $r->user(), (int) $r->query('after', 0)));
    }

    public function send(Request $r, int $id, ChatService $chat)
    {
        $data = $r->validate(['body' => 'nullable|string|max:4000', 'attachment' => 'nullable|file']);
        $m = $chat->send($chat->conversationFor($r->user(), $id), $r->user(), $data['body'] ?? null, $r->file('attachment'), null,
            ['image/jpeg', 'image/png', 'application/pdf'], 10);
        return response()->json(['id' => $m->id], 201);
    }

    private function ownSession(Request $r, string $uuid): MediaSession
    {
        $s = MediaSession::query()->where('uuid', $uuid)->firstOrFail();
        if ($s->requested_by !== $r->user()->id) {
            throw new DomainException('forbidden', 'Only the requesting supervisor can control this session.');
        }
        return $s;
    }
}
