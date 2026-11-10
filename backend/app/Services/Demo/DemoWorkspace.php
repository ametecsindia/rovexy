<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Domain\DomainException;
use App\Models\Company;
use App\Models\ManagedDevice;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\PlatformAudit;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\Workspaces;
use App\Services\PolicyService;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Demo clients: an ordinary workspace (company) with `companies.demo` set, filled with ~2 months of realistic,
 * city-specific history (people, phones, portfolios, calls with recordings, visits, payments with slips, callbacks,
 * attendance and location). Every console screen, report and export works on it unchanged.
 *
 * The dataset is deterministic per city (same city = same people, customers and story), dated relative to the moment
 * it is built. "Clear Demo Data" wipes that one workspace and builds it again; the client's sign-in is kept.
 * Anything the client adds or changes afterwards is newer than demo.seeded_at, which is how Super Admin tells them apart.
 */
final class DemoWorkspace
{
    /** Tables that hold platform records about a company rather than its own data: never wiped. */
    private const KEEP = ['companies', 'licences', 'sales_orders', 'custom_prices'];

    /** Shared, read-only recordings on the recordings disk (one copy for every demo workspace). */
    public const RECORDINGS = 'demo/recordings';

    /** Outcome → recording script. */
    private const SCRIPT = ['PTP' => 'ptp', 'PAID' => 'paid', 'PART' => 'part', 'CALLBACK' => 'callback', 'DISPUTE' => 'dispute', 'REFUSED' => 'dispute',
        'SETTLE' => 'settlement', 'THIRD' => 'third', 'WRONG' => 'wrong', 'BRANCH' => 'branch', 'CONN' => 'callback'];

    /** Token → outcome code (Outcomes::CODES). */
    private const CODE = ['NA' => 'RINGING_NO_ANSWER', 'SO' => 'SWITCHED_OFF', 'NR' => 'NOT_REACHABLE', 'BUSY' => 'BUSY', 'CONN' => 'CONNECTED',
        'PTP' => 'CONNECTED_PTP', 'PAID' => 'PAID', 'PART' => 'PART_PAID', 'CALLBACK' => 'CALLBACK', 'DISPUTE' => 'DISPUTE', 'REFUSED' => 'REFUSED',
        'SETTLE' => 'SETTLEMENT', 'THIRD' => 'THIRD_PARTY', 'WRONG' => 'WRONG_NUMBER', 'BRANCH' => 'VISIT_BRANCH'];

    /** Each customer follows one story; the list repeats until "now". */
    private const STORIES = [
        ['payer', 26, ['NA?', 'PTP', 'PAID', 'CALLBACK', 'PTP', 'PAID', 'NA', 'PTP', 'PAID']],
        ['part', 12, ['PTP', 'PART', 'CALLBACK', 'PTP', 'PART', 'NA', 'PTP', 'PART']],
        ['breaker', 15, ['PTP', 'NA', 'NA', 'CONN', 'PTP', 'SO', 'BRANCH', 'NA', 'PTP', 'NA']],
        ['callback', 11, ['CALLBACK', 'CALLBACK', 'PTP', 'PAID', 'CALLBACK', 'PTP', 'NA']],
        ['unreachable', 10, ['NA', 'SO', 'NR', 'NA', 'THIRD', 'SO', 'NA', 'NR', 'BUSY', 'NA']],
        ['dispute', 6, ['NA', 'DISPUTE', 'CALLBACK', 'REFUSED', 'DISPUTE', 'NA']],
        ['settlement', 5, ['CONN', 'SETTLE', 'CALLBACK', 'SETTLE', 'PTP', 'PART']],
        ['third', 5, ['THIRD', 'NA', 'PTP', 'PAID', 'CALLBACK']],
        ['wrong', 3, ['WRONG', 'PTP', 'PAID', 'CALLBACK']],
        ['fresh', 7, ['NA', 'BUSY']],
        ['untouched', 3, []],   // allocated, not worked yet
    ];

    private const NOTES = [
        'NA' => ['Ringing, not answered.', null, 'No response.'], 'SO' => ['Switched off.', null], 'NR' => ['Not reachable.', null], 'BUSY' => ['Line busy.', null],
        'CONN' => ['Customer aware of the dues, will confirm a date.', 'Explained overdue and late charges.', 'Customer asked for the EMI schedule.'],
        'PTP' => ['Customer promised to pay on %s.', 'Salary delayed, will pay by %s.', 'Agreed to pay the full overdue on %s.', 'Will arrange funds and pay on %s.'],
        'CALLBACK' => ['Customer busy, asked to call after 6 pm.', 'Driving, call back tomorrow.', 'In a meeting, asked for a call later.', 'Asked to call on the weekend.'],
        'PAID' => ['Paid via UPI, screenshot shared on WhatsApp.', 'Paid through the payment link.', 'NEFT done, UTR shared.'],
        'PAID_FIELD' => ['Cash collected at residence, receipt given.', 'Collected cheque at the shop, deposit tomorrow.', 'Collected at the door, receipt handed over.'],
        'PART' => ['Part payment received, balance next month.', 'Paid one EMI, will clear the rest by month end.'],
        'DISPUTE' => ['Disputes the penalty charges, wants the statement.', 'Says bounce charges are wrong; asked for the statement.'],
        'REFUSED' => ['Refused to pay until charges are waived.', 'Says will pay only after a settlement letter.'],
        'SETTLE' => ['Lost job last year, asking for a settlement.', 'Wants a one-time settlement; escalated to TL.'],
        'THIRD' => ['Spoke to brother, customer out of station.', 'Wife answered, will inform the customer.', 'Father picked up, customer at work.'],
        'WRONG' => ['Number belongs to someone else; trying the alternate number.', 'Wrong number, need updated contact.'],
        'BRANCH' => ['Will pay at the branch on %s.', 'Will deposit cash at the branch on %s.'],
    ];

    private const PHONES = [['Samsung', 'SM-M346B', 'Galaxy M34', '14', 34], ['Xiaomi', '23021RAA2Y', 'Redmi Note 12', '13', 33], ['OPPO', 'CPH2565', 'A78', '14', 34],
        ['vivo', 'V2302', 'Y27', '13', 33], ['realme', 'RMX3780', '11 Pro', '14', 34], ['OnePlus', 'CPH2569', 'Nord CE3 Lite', '14', 34],
        ['Samsung', 'SM-A145F', 'Galaxy A14', '14', 34], ['motorola', 'moto g54 5G', 'g54', '14', 34]];

    private \Random\Randomizer $rnd;
    private int $cid;
    private string $tz = 'Asia/Kolkata';
    private Carbon $now;
    private array $city;
    private array $names = [];
    private array $usedNames = [];
    private array $durations = [];
    private array $buffer = [];

    // ---------------------------------------------------------------- public API

    /** Website "Explore Demo": workspace + the registrant as its administrator + the city's dataset. */
    public static function register(array $d): Company
    {
        set_time_limit(180);
        DemoCities::get($d['city']) ?? throw new DomainException('bad_city', 'Choose a city from the list.');
        $company = DB::transaction(function () use ($d) {
            $c = Workspaces::create(Workspaces::slugFrom($d['business']), $d['business'], $d['email'], $d['name'], $d['password']);
            $c->forceFill(['billing_email' => strtolower($d['email']), 'billing_phone' => $d['mobile'], 'address' => $d['address'],
                'demo' => ['city' => $d['city'], 'contact_name' => $d['name'], 'business' => $d['business'], 'mobile' => $d['mobile'], 'email' => strtolower($d['email']),
                    'address' => $d['address'], 'registered_at' => now()->toAtomString(), 'resets' => 0]])->save();
            app(CurrentCompany::class)->run($c, fn () => User::query()->where('employee_code', 'admin')->update(['phone' => self::e164($d['mobile'])]));
            (new self())->build($c);
            return $c;
        });
        PlatformAudit::record('demo.registered', $company, ['city' => $d['city'], 'email' => $d['email']], 'website');
        return $company;
    }

    /**
     * Keep a demo's data but give its employees a realistic working day: sign-in / sign-out, lunch break, idle spells and
     * a GPS trail (every 5 minutes, tens of km for field agents), for every working day from the first upload until now.
     * Only the demo's own phones and sessions are touched; everything the client added or changed stays as it is.
     * Run again at any time (e.g. a few days later, so "today" has data): it replaces what it made before.
     */
    public static function repairActivity(Company $c): array
    {
        self::demoOnly($c);
        set_time_limit(300);
        $w = new self();
        $w->city = DemoCities::get($c->demo['city']) ?? throw new DomainException('bad_city', 'Unknown demo city.');
        $w->cid = $c->id;
        $w->now = now()->utc()->startOfMinute();
        $w->rnd = new \Random\Randomizer(new \Random\Engine\Mt19937(crc32('rovexy-activity-' . $c->id . '-' . $w->now->toDateString())));
        $out = app(CurrentCompany::class)->run($c, fn () => DB::transaction(fn () => $w->repair()));
        if (Schema::hasTable('daily_activity')) {
            DB::table('daily_activity')->where('company_id', $c->id)->delete();
        }
        for ($d = 0; $d <= 90; $d++) {
            \Illuminate\Support\Facades\Cache::forget("activity-built:{$c->id}:" . $w->now->copy()->setTimezone($w->tz)->subDays($d)->toDateString());
        }
        return $out;
    }

    private function repair(): array
    {
        [, , , , , , $loc] = $this->city;
        // The demo's own people and phones (made by build(): TC2xx tele-callers, FOS3xx field agents).
        $users = DB::table('users')->where('company_id', $this->cid)->where(fn ($q) => $q->where('employee_code', 'like', 'TC2%')->orWhere('employee_code', 'like', 'FOS3%'))
            ->orderBy('employee_code')->get(['id', 'employee_code']);
        $field = $users->filter(fn ($u) => str_starts_with($u->employee_code, 'FOS'))->values();
        $circle = [];
        foreach ($field as $k => $u) {   // same circles as people()
            $circle[$u->id] = array_slice($loc, 1 + ($k * 3) % (count($loc) - 1), 3) ?: [$loc[1]];
        }
        $first = Carbon::parse((string) (DB::table('import_jobs')->where('company_id', $this->cid)->min('created_at') ?? $this->now->copy()->subDays(62)))->setTimezone($this->tz)->startOfDay();
        $today = $this->now->copy()->setTimezone($this->tz)->startOfDay();
        $stats = ['employees' => 0, 'days' => 0, 'events' => 0, 'points' => 0];
        foreach ($users as $u) {
            $dev = (int) DB::table('managed_devices')->where('company_id', $this->cid)->where('assigned_user_id', $u->id)->orderBy('id')->value('id');
            if (!$dev) {
                continue;
            }
            $isField = isset($circle[$u->id]);
            $home = $isField ? $circle[$u->id][0] : $loc[0];
            // Visits on record (demo and client's), as stops of the day.
            $stops = [];
            foreach (DB::table('customer_events')->where('company_id', $this->cid)->where('user_id', $u->id)->where('type', 'visit')->get(['meta', 'created_at']) as $v) {
                $m = json_decode((string) $v->meta, true) ?: [];
                if (isset($m['lat'], $m['lng'])) {
                    $at = Carbon::parse($v->created_at, 'UTC');
                    $stops[$at->copy()->setTimezone($this->tz)->toDateString()][] = [(float) $m['lat'], (float) $m['lng'], $at];
                }
            }
            // Forget what this repair (or the old demo build) made for this phone; real sessions from the client's own use stay.
            $mine = DB::table('work_sessions')->where('company_id', $this->cid)->where('device_id', $dev)->where('access_hash', 'like', 'demo%')->pluck('id');
            $old = DB::table('work_sessions')->where('company_id', $this->cid)->where('device_id', $dev)->where('end_reason', 'signed_out')
                ->where('created_at', '<', (string) ($this->demoSeededAt() ?? $this->now))->pluck('id');
            $drop = $mine->merge($old)->unique()->values();
            foreach ($drop->chunk(500) as $ids) {
                DB::table('location_events')->whereIn('work_session_id', $ids)->delete();
            }
            DB::table('activity_events')->where('company_id', $this->cid)->where('device_id', $dev)->where('meta', 'like', '%"demo":true%')->delete();
            $realDays = DB::table('activity_events')->where('company_id', $this->cid)->where('user_id', $u->id)->where('type', 'signed_in')->pluck('occurred_at')
                ->map(fn ($t) => Carbon::parse($t, 'UTC')->setTimezone($this->tz)->toDateString())->flip();
            foreach ($drop->chunk(500) as $ids) {
                DB::table('work_sessions')->whereIn('id', $ids)->delete();
            }
            [$points, $events, $lastSeen] = [[], [], null];
            for ($day = $first->copy(); $day->lte($today); $day->addDay()) {
                if ($day->isSunday() || isset($realDays[$day->toDateString()])) {
                    continue;   // Monday–Saturday; a day the client really used the phone keeps its real data
                }
                $in = $day->copy()->setTime(9, $this->rnd->getInt(10, 59));
                $outAt = $day->copy()->setTime(18, $this->rnd->getInt(5, 59))->addMinutes($this->rnd->getInt(0, 50));
                if ($in->copy()->utc()->gte($this->now)) {
                    continue;
                }
                $endUtc = $outAt->copy()->utc()->min($this->now->copy()->subMinutes(5));
                $ws = (int) DB::table('work_sessions')->insertGetId(['company_id' => $this->cid, 'device_id' => $dev, 'user_id' => $u->id,
                    'access_hash' => 'demo' . bin2hex(random_bytes(30)), 'refresh_hash' => bin2hex(random_bytes(32)), 'epoch' => 1,
                    'access_expires_at' => $this->ts($in->copy()->utc()->addMinutes(15)), 'refresh_expires_at' => $this->ts($in->copy()->utc()->addHours(12)),
                    'lease_expires_at' => $this->ts($endUtc), 'last_validated_at' => $this->ts($endUtc), 'ended_at' => $this->ts($endUtc), 'end_reason' => 'signed_out',
                    'created_at' => $this->ts($in->copy()->utc())]);
                $this->dayOfWork($u->id, $dev, $isField, $home, $circle[$u->id] ?? [], $day, $in, $outAt, $endUtc, $ws, $stops[$day->toDateString()] ?? [], $points, $events);
                $lastSeen = $endUtc;
                $stats['days']++;
            }
            foreach (array_chunk($points, 500) as $chunk) {
                DB::table('location_events')->insert($chunk);
            }
            foreach (array_chunk($events, 500) as $chunk) {
                DB::table('activity_events')->insert($chunk);
            }
            if ($lastSeen) {
                DB::table('managed_devices')->where('id', $dev)->where(fn ($q) => $q->whereNull('last_contact_at')->orWhere('last_contact_at', '<', $this->ts($lastSeen)))
                    ->update(['last_contact_at' => $this->ts($lastSeen)]);
            }
            $stats['employees']++;
            $stats['events'] += count($events);
            $stats['points'] += count($points);
        }
        return $stats;
    }

    private function demoSeededAt(): ?string
    {
        $demo = json_decode((string) DB::table('companies')->where('id', $this->cid)->value('demo'), true);
        return isset($demo['seeded_at']) ? Carbon::parse($demo['seeded_at'])->utc()->toDateTimeString() : null;
    }

    /** "Clear Demo Data": everything goes, the original dataset comes back; the administrator's sign-in is kept. */
    public static function reset(Company $c): void
    {
        self::demoOnly($c);
        set_time_limit(180);
        $admin = DB::table('users')->where('company_id', $c->id)->where('employee_code', 'admin')->first();
        DB::transaction(function () use ($c, $admin) {
            self::wipe($c);
            self::deleteFiles($c->id);   // before the rebuild: it writes the new slips into the same folder
            app(CurrentCompany::class)->run($c, function () use ($c, $admin) {
                CompanyDefaults::apply($c);
                $demo = $c->demo;
                // Same id, e-mail and password: the client stays signed in and signs in as before.
                $u = (new User())->forceFill(['id' => $admin?->id, 'company_id' => $c->id, 'employee_code' => 'admin', 'email' => $admin->email ?? $demo['email'],
                    'name' => $admin->name ?? $demo['contact_name'], 'phone' => $admin->phone ?? self::e164($demo['mobile']), 'status' => 'active',
                    'password' => $admin->password ?? Hash::make(Str::random(32)), 'last_login_at' => $admin->last_login_at ?? null]);
                $u->save();
                $u->roles()->attach(Role::query()->where('key', 'company_admin')->value('id'), ['company_id' => $c->id]);
            });
            $c->forceFill(['demo' => ['resets' => ($c->demo['resets'] ?? 0) + 1, 'last_reset_at' => now()->toAtomString()] + $c->demo])->save();
            (new self())->build($c->refresh());
        });
        PlatformAudit::record('demo.reset', $c, ['city' => $c->demo['city']]);
    }

    /** Permanently removes a demo workspace and everything in it. */
    public static function delete(Company $c): void
    {
        self::demoOnly($c);
        DB::transaction(function () use ($c) {
            self::wipe($c);
            $c->delete();
        });
        self::deleteFiles($c->id);
        PlatformAudit::record('demo.deleted', null, ['company_id' => $c->id, 'slug' => $c->slug, 'name' => $c->name, 'email' => $c->demo['email'] ?? null]);
    }

    public static function isDemo(?Company $c): bool
    {
        return $c !== null && !empty($c->demo);
    }

    /** Shared demo recordings must never be deleted through one workspace. */
    public static function sharedKey(?string $key): bool
    {
        return $key !== null && str_starts_with($key, self::RECORDINGS . '/');
    }

    // ---------------------------------------------------------------- wipe

    private static function demoOnly(Company $c): void
    {
        self::isDemo($c) || throw new DomainException('not_demo', 'This is a real client workspace, not a demo.');
    }

    /** Deletes every row the workspace owns, children before parents (no foreign-key switch needed). */
    private static function wipe(Company $c): void
    {
        $tables = collect(Schema::getTables())->pluck('name')
            ->reject(fn ($t) => in_array($t, self::KEEP, true) || str_starts_with($t, 'sqlite_'))
            ->filter(fn ($t) => Schema::hasColumn($t, 'company_id'))->values();
        $refs = $tables->mapWithKeys(fn ($t) => [$t => collect(Schema::getForeignKeys($t))->pluck('foreign_table')->intersect($tables)->reject(fn ($f) => $f === $t)->values()]);
        $order = [];
        $left = $tables->all();
        while ($left) {
            $free = array_filter($left, fn ($t) => !collect($left)->contains(fn ($o) => $o !== $t && $refs[$o]->contains($t)));
            $free = $free ?: [reset($left)];   // ponytail: a reference cycle would stop here; none exists today
            array_push($order, ...$free);
            $left = array_values(array_diff($left, $free));
        }
        DB::table('permission_role')->whereIn('role_id', DB::table('roles')->where('company_id', $c->id)->select('id'))->delete();
        foreach ($order as $t) {
            DB::table($t)->where('company_id', $c->id)->delete();
        }
    }

    private static function deleteFiles(int $id): void
    {
        foreach ([config('ammp.recordings.disk'), 'company_files'] as $disk) {
            rescue(fn () => Storage::disk($disk)->deleteDirectory("companies/{$id}"), null, false);
        }
    }

    // ---------------------------------------------------------------- build

    private function build(Company $c): void
    {
        $this->city = DemoCities::get($c->demo['city']) ?? throw new DomainException('bad_city', 'Unknown demo city.');
        $this->cid = $c->id;
        $this->now = now()->utc()->startOfMinute();
        $this->rnd = new \Random\Randomizer(new \Random\Engine\Mt19937(crc32('rovexy-demo-' . $this->city[0])));
        [, , , , $region] = $this->city;
        $this->names = DemoCities::NAMES[$region];
        $this->durations = json_decode((string) file_get_contents(resource_path('demo/recordings/durations.json')), true);
        $this->shareRecordings();
        app(CurrentCompany::class)->run($c, function () use ($c) {
            $org = $this->people($c);
            $this->portfolios($org);
            $this->copyCalling();
        });
        // Employee activity report: forget days worked out from the previous data, so they are built again from this one.
        if (Schema::hasTable('daily_activity')) {
            DB::table('daily_activity')->where('company_id', $c->id)->delete();
        }
        for ($d = 0; $d <= 75; $d++) {
            \Illuminate\Support\Facades\Cache::forget("activity-built:{$c->id}:" . $this->now->copy()->setTimezone($this->tz)->subDays($d)->toDateString());
        }
        $c->forceFill(['demo' => ['seeded_at' => now()->addSecond()->toAtomString()] + $c->demo])->save();
    }

    /** Branch, two teams, two team leaders, four tele-callers, four field agents, their approved phones. */
    private function people(Company $c): array
    {
        [, $cityName, , $code, , , $loc] = $this->city;
        $made = $this->day(-75, 10, 30);
        $admin = DB::table('users')->where('company_id', $this->cid)->where('employee_code', 'admin')->value('id');
        $branch = $this->ins('branches', ['code' => $code, 'name' => $cityName, 'created_at' => $made, 'updated_at' => $made]);
        $teams = ['tele' => $this->ins('teams', ['branch_id' => $branch, 'code' => "{$code}-TC", 'name' => "{$cityName} Tele-calling", 'created_at' => $made, 'updated_at' => $made]),
            'field' => $this->ins('teams', ['branch_id' => $branch, 'code' => "{$code}-FD", 'name' => "{$cityName} Field", 'created_at' => $made, 'updated_at' => $made])];
        $roles = DB::table('roles')->where('company_id', $this->cid)->pluck('id', 'key');
        $nums = self::numbers();
        $pw = Hash::make(Str::random(24));
        $plan = [['TL101', 'tele', 'supervisor'], ['TL102', 'field', 'supervisor'], ['TC201', 'tele', 'employee'], ['TC202', 'tele', 'employee'], ['TC203', 'tele', 'employee'],
            ['TC204', 'tele', 'employee'], ['FOS301', 'field', 'employee'], ['FOS302', 'field', 'employee'], ['FOS303', 'field', 'employee'], ['FOS304', 'field', 'employee']];
        $org = ['admin' => $admin, 'teams' => $teams, 'lead' => [], 'agents' => ['tele' => [], 'field' => []], 'devices' => [], 'names' => []];
        foreach ($plan as $i => [$emp, $team, $role]) {
            $name = $this->person($i % 3 === 2 ? 'f' : 'm');
            $id = $this->ins('users', ['employee_code' => $emp, 'name' => $name, 'phone' => '+91' . $nums[100 + $i], 'password' => $pw, 'status' => 'active',
                'last_login_at' => $this->day(-1, 9, 40), 'created_at' => $made, 'updated_at' => $made]);
            DB::table('role_user')->insert(['role_id' => $roles[$role], 'user_id' => $id, 'company_id' => $this->cid]);
            DB::table('team_user')->insert(['team_id' => $teams[$team], 'user_id' => $id, 'company_id' => $this->cid, 'membership' => $role === 'supervisor' ? 'supervisor' : 'member']);
            $org['names'][$id] = [$name, $emp];
            if ($role === 'supervisor') {
                $org['lead'][$team] = $id;
                continue;
            }
            $org['agents'][$team][] = $id;
            [$make, $model, , $os, $sdk] = self::PHONES[count($org['devices'])];
            $dev = $this->ins('managed_devices', ['asset_tag' => sprintf('%s-PH-%03d', $code, 11 + count($org['devices'])), 'installation_id' => $this->uuid(),
                'manufacturer' => $make, 'model' => $model, 'os_version' => $os, 'sdk_int' => $sdk, 'app_version' => '1.0.12', 'approval_state' => 'approved',
                'approval_code' => (string) random_int(100000000, 999999999), 'management_mode' => 'app_only', 'management_status' => 'unenrolled', 'credential_epoch' => 1,
                'assigned_user_id' => $id, 'team_id' => $teams[$team], 'policy_state' => 'applied', 'compliant' => true, 'presence' => 'offline', 'work_state' => 'signed_out',
                'battery_pct' => $this->rnd->getInt(38, 96), 'network_type' => $this->pick(['wifi', 'cellular', 'cellular']), 'recording_verified_at' => $this->day(-70, 11, 5),
                'created_at' => $this->day(-71, 11, 0), 'updated_at' => $this->day(-71, 11, 0)]);
            DB::table('device_assignments')->insert(['company_id' => $this->cid, 'device_id' => $dev, 'user_id' => $id, 'team_id' => $teams[$team], 'assigned_by' => $admin, 'assigned_at' => $this->day(-71, 11, 2)]);
            DB::table('device_approval_events')->insert(['company_id' => $this->cid, 'device_id' => $dev, 'action' => 'approved', 'actor_user_id' => $admin, 'created_at' => $this->day(-71, 11, 2)]);
            $org['devices'][$id] = $dev;
        }
        // Each field agent works a circle of three neighbouring localities; the office is the first locality.
        foreach ($org['agents']['field'] as $k => $uid) {
            $mine = array_slice($loc, 1 + ($k * 3) % (count($loc) - 1), 3) ?: [$loc[1]];
            $org['circle'][$uid] = $mine;
            $lat = array_sum(array_column($mine, 2)) / count($mine);
            $lng = array_sum(array_column($mine, 3)) / count($mine);
            $this->ins('field_circles', ['user_id' => $uid, 'name' => $mine[0][0] . ' area', 'lat' => round($lat, 7), 'lng' => round($lng, 7), 'radius_m' => $this->rnd->getInt(28, 45) * 100,
                'updated_by' => $admin, 'created_at' => $made, 'updated_at' => $made]);
        }
        return $org;
    }

    /** Three portfolios uploaded at different dates, 100 customers, and two months of work on them. */
    private function portfolios(array $org): void
    {
        [, $cityName, $state, $code, $region, , $loc] = $this->city;
        $nums = self::numbers();
        $month = fn (int $d) => Carbon::parse($this->day($d, 12, 0))->setTimezone($this->tz)->format('M');
        $books = [['Personal Loan – Bucket 1', 'PL', 40, -56, [150000, 500000], [4200, 14800]],
            ['Two-Wheeler Loan – Bucket 2', 'TW', 35, -47, [60000, 145000], [2400, 5200]],
            ['Consumer Durable – X Bucket', 'CD', 25, -19, [18000, 85000], [1500, 4100]]];
        $c = 0;
        $customers = [];
        foreach ($books as [$book, $prefix, $count, $at, $loanRange, $emiRange]) {
            $when = $this->day($at, 11, 20);
            $job = $this->ins('import_jobs', ['uploaded_by' => $org['admin'], 'original_name' => "{$prefix}_Allocation_{$cityName}_{$month($at)}.xlsx",
                'stored_key' => "companies/{$this->cid}/imports/" . $this->uuid() . '.xlsx', 'format' => 'xlsx', 'size_bytes' => $this->rnd->getInt(38000, 96000),
                'sha256' => bin2hex($this->rnd->getBytes(32)), 'status' => 'completed', 'total_rows' => $count, 'valid_rows' => $count, 'created_count' => $count,
                'portfolio' => $book, 'started_at' => $when, 'finished_at' => $when, 'created_at' => $when, 'updated_at' => $when]);
            for ($k = 0; $k < $count; $k++, $c++) {
                $team = $c % 2 ? 'field' : 'tele';
                $agents = $org['agents'][$team];
                $uid = $agents[intdiv($c, 2) % count($agents)];
                [$area, $pin, $lat, $lng] = $team === 'field' ? $this->pick($org['circle'][$uid]) : $this->pick(array_slice($loc, 1));
                $emi = $this->rnd->getInt($emiRange[0] / 100, $emiRange[1] / 100) * 100;
                $loan = (int) (round($this->rnd->getInt($loanRange[0], $loanRange[1]) / 1000) * 1000);
                $inst = $this->pick([1, 1, 2, 2, 2, 3, 3, 4, 5, 6]);
                $charges = $inst * $this->pick([350, 500, 590]);
                $overdue = $inst * $emi + $charges;
                $outstanding = max($overdue, (int) round($loan * $this->rnd->getInt(35, 85) / 100));
                $upload = Carbon::parse($when)->setTimezone($this->tz);
                $gender = $this->rnd->getInt(1, 10) <= 7 ? 'm' : 'f';
                $hasAlt = $this->rnd->getInt(1, 10) <= 3;
                $customers[] = [
                    'row' => ['customer_ref' => $prefix . $this->rnd->getInt(1000000000, 9999999999),
                        'name' => $this->person($gender, false), 'phone' => '+91' . $nums[$c], 'alt_phone' => $hasAlt ? '+91' . $nums[120 + $c] : null, 'campaign' => $book,
                        'status' => 'new', 'assigned_user_id' => $uid, 'assigned_team_id' => $org['teams'][$team], 'last_import_id' => $job,
                        'city' => $cityName, 'area' => $area, 'pincode' => $pin, 'state' => $state, 'address' => $this->address($region, $area, $cityName, $pin),
                        'lat' => round($lat + $this->rnd->getInt(-55, 55) / 10000, 7), 'lng' => round($lng + $this->rnd->getInt(-55, 55) / 10000, 7),
                        'geo_status' => 'google', 'geo_precision' => $this->pick(['ROOFTOP', 'ROOFTOP', 'RANGE_INTERPOLATED']),
                        'preferred_time' => $this->pick([null, null, 'After 6 pm', 'Morning 9–11', 'Lunch time', 'Weekends']),
                        'loan_amount' => $loan, 'outstanding' => $outstanding, 'principal_os' => $outstanding - $charges - ($interest = (int) round(($outstanding - $charges) * 0.1)), 'interest_os' => $interest,
                        'charges' => $charges, 'emi_amount' => $emi, 'overdue_amount' => $overdue, 'overdue_installments' => $inst,
                        'oldest_due_date' => $upload->copy()->subMonthsNoOverflow($inst)->day($this->pick([5, 7, 10, 15]))->toDateString(),
                        'due_date' => $upload->copy()->addMonthNoOverflow()->day($this->pick([5, 7, 10, 15]))->toDateString(),
                        'last_paid_on' => $upload->copy()->subMonthsNoOverflow($inst + 1)->day($this->pick([5, 7, 10, 15]))->toDateString(), 'last_paid_amount' => $emi,
                        'balance_as_of' => $upload->toDateString(), 'created_at' => $when, 'updated_at' => $when],
                    'team' => $team, 'upload' => $when, 'job' => $job];
            }
        }
        foreach ($customers as &$cu) {
            $cu['id'] = $this->ins('customers', $cu['row']);
            DB::table('customer_assignments')->insert(['company_id' => $this->cid, 'customer_id' => $cu['id'], 'user_id' => $cu['row']['assigned_user_id'],
                'team_id' => $cu['row']['assigned_team_id'], 'action' => 'assigned', 'actor_user_id' => $org['admin'], 'import_job_id' => $cu['job'], 'created_at' => $cu['upload']]);
        }
        unset($cu);
        $days = [];   // [user][Y-m-d] => [[lat,lng,ts]] field stops, for the location trail
        foreach ($customers as $cu) {
            $this->story($cu, $org, $days);
        }
        $this->flush();
        $this->attendance($org, $days);
        $this->chat($org);
    }

    /** One customer's two months: calls, visits, promises, payments with slips, callbacks. */
    private function story(array $cu, array $org, array &$days): void
    {
        $story = $this->weighted(self::STORIES);
        $uid = $cu['row']['assigned_user_id'];
        $field = $cu['team'] === 'field';
        $lead = $org['lead'][$cu['team']];
        $overdueLeft = (float) $cu['row']['overdue_amount'];
        $emi = (float) $cu['row']['emi_amount'];
        $t = $this->workTime(Carbon::parse($cu['upload'])->setTimezone($this->tz)->addDays($this->rnd->getInt(1, 3)));
        $which = 'primary';
        $status = 'new';
        $openFollow = null;
        $last = $cu['upload'];
        $promise = null;
        $paidAt = null;
        $once = in_array($story[0], ['fresh', 'untouched'], true);
        $plan = $story[2];
        // The story repeats until today, with unanswered attempts in between (real connect rates are ~50–60%).
        for ($i = 0, $n = 0, $prev = 'NA'; $n < 70 && ($once ? $i < count($plan) : $plan); $n++) {
            $miss = !$once && !in_array($prev, ['NA', 'SO', 'NR', 'BUSY'], true) && $this->rnd->getInt(1, 100) <= 38;
            $tok = $miss ? $this->pick(['NA', 'NA', 'NA', 'SO', 'BUSY', 'NR']) : $plan[$i++ % count($plan)];
            if ($tok === 'NA?') {
                $tok = $this->rnd->getInt(0, 1) ? 'CONN' : 'NA';
            }
            if ($t->copy()->utc()->gte($this->now)) {
                break;
            }
            // A customer pays about once a month: a second payment / promise soon after one becomes a callback.
            $sincePaid = $paidAt ? (int) $paidAt->diffInDays($t, true) : 99;
            if (in_array($tok, ['PAID', 'PART'], true) && $sincePaid < 24) {
                $tok = 'CALLBACK';
            } elseif (in_array($tok, ['PTP', 'BRANCH'], true) && $sincePaid < 16) {
                $tok = 'CONN';
            }
            $prev = $tok;
            if ($tok === 'PTP' && ($promise === null || $promise->lte($t))) {
                $promise = $t->copy()->addDays($this->rnd->getInt(3, 8));
            }
            if ($tok === 'BRANCH') {
                $promise = $t->copy()->addDays($this->rnd->getInt(2, 5));
            }
            $answered = !in_array($tok, ['NA', 'SO', 'NR', 'BUSY'], true);
            $atDoor = $field && (in_array($tok, ['PAID', 'PART'], true) || ($tok === 'PTP' && $this->rnd->getInt(1, 10) <= 4));
            $doorLocked = $field && !$answered && $this->rnd->getInt(1, 10) <= 3;
            $ts = $t->copy()->utc();

            if ($doorLocked) {   // a field visit instead of a call: nobody home
                $this->visit($cu, $uid, $ts, $days);
                $this->ins('customer_events', ['customer_id' => $cu['id'], 'user_id' => $uid, 'type' => 'note',
                    'body' => $this->pick(['Door locked, neighbour says the customer is at work.', 'House locked, will revisit in the evening.', 'Customer not at home, met the mother; asked her to inform.']),
                    'created_at' => $this->ts($ts->copy()->addMinutes(3))], false);
                $last = $this->ts($ts);
                $t = $this->workTime($t->copy()->addDays($this->rnd->getInt(2, 4)));
                continue;
            }

            if ($atDoor) {
                $this->visit($cu, $uid, $ts, $days);
                $call = $this->ins('calls', ['uuid' => $this->uuid(), 'provider' => 'manual', 'user_id' => $uid, 'device_id' => $org['devices'][$uid], 'customer_id' => $cu['id'],
                    'destination' => $cu['row']['phone'], 'which_number' => 'primary', 'calling_mode' => 'field_entry', 'agent_leg' => 'none', 'state' => 'completed',
                    'initiated_at' => $this->ts($ts->copy()->addMinutes(6)), 'ended_at' => $this->ts($ts->copy()->addMinutes(6)), 'talk_seconds' => 0,
                    'recording_required' => false, 'recording_status' => 'not_required', 'disposition_status' => 'submitted', 'is_test' => false,
                    'created_at' => $this->ts($ts->copy()->addMinutes(6)), 'updated_at' => $this->ts($ts->copy()->addMinutes(8))]);
                $end = $ts->copy()->addMinutes(6);
            } else {
                $ring = $answered ? $this->rnd->getInt(4, 22) : $this->rnd->getInt(25, 45);
                $file = $answered ? $this->recording($tok) : null;
                $talk = $file ? $this->durations[$file] + $this->rnd->getInt(1, 4) : 0;
                $end = $ts->copy()->addSeconds($ring + $talk + 2);
                $call = $this->ins('calls', ['uuid' => $uuid = $this->uuid(), 'provider' => 'sim', 'user_id' => $uid, 'device_id' => $org['devices'][$uid], 'customer_id' => $cu['id'],
                    'destination' => $which === 'alt' && $cu['row']['alt_phone'] ? $cu['row']['alt_phone'] : $cu['row']['phone'], 'which_number' => $which === 'alt' && $cu['row']['alt_phone'] ? 'alt' : 'primary',
                    'calling_mode' => 'sim', 'agent_leg' => 'sim', 'state' => $answered ? 'completed' : 'no_answer', 'hangup_cause' => $answered ? 'SIM_COMPLETED' : 'SIM_NO_ANSWER',
                    'initiated_at' => $this->ts($ts), 'ringing_at' => $this->ts($ts->copy()->addSeconds(2)), 'answered_at' => $answered ? $this->ts($ts->copy()->addSeconds($ring + 2)) : null,
                    'ended_at' => $this->ts($end), 'ring_seconds' => $ring, 'talk_seconds' => $talk, 'total_seconds' => $ring + $talk + 2, 'recording_required' => true,
                    'recording_status' => $file ? 'available' : 'not_required', 'disposition_status' => 'submitted', 'is_test' => false,
                    'created_at' => $this->ts($ts), 'updated_at' => $this->ts($end->copy()->addMinutes(2))]);
                $this->ins('call_events', ['call_id' => $call, 'provider_event_id' => 'sim-result', 'type' => $answered ? 'hangup' : 'failed', 'occurred_at' => $this->ts($end),
                    'payload' => json_encode(['talk' => $talk, 'ring' => $ring, 'source' => 'phone_call_log']), 'created_at' => $this->ts($end)], false);
                if ($file) {
                    $bytes = $this->durations['_bytes'][$file];
                    $this->ins('recordings', ['call_id' => $call, 'status' => 'available', 'source_path' => 'phone:Call_' . $ts->copy()->setTimezone($this->tz)->format('Ymd_His') . '.mp3',
                        'object_key' => self::RECORDINGS . "/{$file}.mp3", 'format' => 'mp3', 'mime' => 'audio/mpeg', 'size_bytes' => $bytes[0], 'sha256' => $bytes[1],
                        'duration_seconds' => $this->durations[$file], 'attempts' => 1, 'legal_hold' => false, 'available_at' => $this->ts($end->copy()->addSeconds(40)),
                        'created_at' => $this->ts($end), 'updated_at' => $this->ts($end->copy()->addSeconds(40))], false);
                }
                if ($tok === 'WRONG') {
                    $which = 'alt';
                }
            }

            // The outcome the employee recorded.
            $code = self::CODE[$tok];
            $follow = match ($tok) {
                'PTP', 'BRANCH' => $promise->copy()->setTime(11, 0),
                'CALLBACK' => $this->workTime($t->copy()->addDays($this->rnd->getInt(1, 2)))->setTime($this->pick([11, 15, 18]), $this->pick([0, 30])),
                'SETTLE' => $this->workTime($t->copy()->addDays($this->rnd->getInt(3, 5))),
                default => null,
            };
            $pay = [];
            if (in_array($tok, ['PTP', 'BRANCH'], true)) {
                $pay = ['amount' => $overdueLeft > 0 ? $overdueLeft : $emi];
            } elseif (in_array($tok, ['PAID', 'PART'], true)) {
                $amount = $tok === 'PART' ? $emi : ($overdueLeft > 0 ? $overdueLeft : $emi);
                $pay = $this->payment($cu, $uid, $lead, $field, $amount, $t, $code, $org);
                if ($pay['review_status'] !== 'rejected') {
                    $overdueLeft = max(0, $overdueLeft - $amount);
                }
                $paidAt = $t->copy();
            }
            $note = $this->pick(self::NOTES[$tok === 'PAID' && $field ? 'PAID_FIELD' : $tok]);
            if ($note && $follow && str_contains($note, '%s')) {
                $note = sprintf($note, $follow->format('d M'));
            }
            $submitted = $end->copy()->addSeconds($this->rnd->getInt(40, 200));
            $this->ins('call_dispositions', ['call_id' => $call, 'user_id' => $uid, 'code' => $code, 'notes' => $note, 'follow_up_at' => $follow ? $this->ts($follow->copy()->utc()) : null,
                'submitted_at' => $this->ts($submitted)] + $pay, false);

            // Callbacks: new feedback settles the earlier one (as CallService::dispose).
            if ($openFollow) {
                DB::table('follow_ups')->where('id', $openFollow)->update(['status' => 'done', 'updated_at' => $this->ts($submitted)]);
                $openFollow = null;
            }
            if ($follow) {
                $openFollow = $this->ins('follow_ups', ['customer_id' => $cu['id'], 'user_id' => $uid, 'call_id' => $call, 'due_at' => $this->ts($follow->copy()->utc()),
                    'status' => 'open', 'notes' => $note, 'created_at' => $this->ts($submitted), 'updated_at' => $this->ts($submitted)]);
                $status = 'follow_up';
                DB::table('customers')->where('id', $cu['id'])->update(['follow_up_on' => $follow->toDateString()]);
            } elseif ($status === 'new') {
                $status = 'in_progress';
            }
            $last = $this->ts($submitted);

            // Next attempt: on the promise date after a promise, otherwise a few working days later.
            $gap = match ($tok) {
                'PTP', 'BRANCH' => max(1, (int) $t->diffInDays($promise)), 'CALLBACK', 'NA', 'SO', 'NR', 'BUSY' => $this->rnd->getInt(1, 2), default => $this->rnd->getInt(2, 5),
            };
            if (in_array($tok, ['PAID', 'PART', 'NA', 'SO'], true)) {
                $promise = null;
            }
            $t = $this->workTime($t->copy()->addDays($gap));
        }
        if ($status === 'follow_up' && $openFollow === null) {
            $status = 'in_progress';
        }
        DB::table('customers')->where('id', $cu['id'])->update(['status' => $status, 'updated_at' => $last] + ($openFollow ? [] : ['follow_up_on' => null]));
    }

    /** "I've reached" at the door: a check-in a few metres from the pin, and a stop on that day's location trail. */
    private function visit(array $cu, int $uid, Carbon $ts, array &$days): void
    {
        $m = $this->rnd->getInt(6, 72);
        $acc = $this->rnd->getInt(5, 24);
        $lat = round($cu['row']['lat'] + $this->rnd->getInt(-4, 4) / 10000, 7);
        $lng = round($cu['row']['lng'] + $this->rnd->getInt(-4, 4) / 10000, 7);
        $this->ins('customer_events', ['customer_id' => $cu['id'], 'user_id' => $uid, 'type' => 'visit', 'body' => "Reached the customer ({$m} m from the pin, ±{$acc} m GPS).",
            'meta' => json_encode(['lat' => $lat, 'lng' => $lng, 'distance_m' => $m, 'accuracy_m' => $acc]), 'created_at' => $this->ts($ts)], false);
        $days[$uid][$ts->copy()->setTimezone($this->tz)->toDateString()][] = [$lat, $lng, $ts->copy()];
    }

    /** Payment fields + review + a slip (receipt photo or payment confirmation) as the employee would upload. */
    private function payment(array $cu, int $uid, int $lead, bool $field, float $amount, Carbon $t, string $code, array $org): array
    {
        $mode = $field ? $this->pick(['CASH', 'CASH', 'CASH', 'CHEQUE', 'UPI']) : $this->pick(['UPI', 'UPI', 'NEFT_IMPS', 'ONLINE_LINK', 'BRANCH']);
        $ref = match ($mode) {
            'UPI' => (string) $this->rnd->getInt(300000000000, 699999999999),
            'NEFT_IMPS' => 'N' . $this->rnd->getInt(100000000, 999999999) . $this->rnd->getInt(10000, 99999),
            'CHEQUE' => str_pad((string) $this->rnd->getInt(1, 999999), 6, '0', STR_PAD_LEFT),
            'ONLINE_LINK' => 'pay_' . bin2hex($this->rnd->getBytes(7)),
            default => sprintf('RCPT/%s/%05d', $this->city[3], $this->rnd->getInt(1000, 99999)),
        };
        $paidOn = $t->copy()->setTimezone($this->tz);
        $age = (int) $paidOn->diffInDays($this->now->copy()->setTimezone($this->tz), true);
        $review = $age < 3 ? 'pending' : ($this->rnd->getInt(1, 100) <= 6 ? 'rejected' : 'verified');   // the office checks slips within a day or two
        $reviewedAt = $review === 'pending' ? null : $this->workTime($paidOn->copy()->addDays($this->rnd->getInt(1, 2)));
        if ($reviewedAt && $reviewedAt->copy()->utc()->gt($this->now)) {
            [$review, $reviewedAt] = ['pending', null];
        }
        $out = ['amount' => $amount, 'payment_mode' => $mode, 'payment_ref' => $mode === 'BRANCH' ? null : $ref, 'paid_on' => $paidOn->toDateString(), 'review_status' => $review,
            'reviewed_by' => $review === 'pending' ? null : $this->pick([$lead, $lead, $org['admin']]), 'reviewed_at' => $reviewedAt ? $this->ts($reviewedAt->copy()->utc()) : null,
            'review_note' => $review === 'rejected' ? $this->pick(['Amount on the slip does not match the entry.', 'Slip not clear, please upload again.', 'Reference not found in the bank statement.']) : null];
        if ($mode !== 'BRANCH' || $this->rnd->getInt(0, 1)) {
            $key = "companies/{$this->cid}/payment-slips/" . $this->uuid() . '.jpg';
            Storage::disk('company_files')->put($key, DemoSlip::render($mode, [
                'company' => DB::table('companies')->where('id', $this->cid)->value('name'), 'customer' => $cu['row']['name'], 'account' => $cu['row']['customer_ref'],
                'amount' => $amount, 'ref' => $ref, 'when' => $paidOn, 'agent' => $org['names'][$uid][0], 'emp' => $org['names'][$uid][1], 'city' => $this->city[1],
            ], $this->rnd));
            $out += ['slip_key' => $key, 'slip_mime' => 'image/jpeg', 'slip_name' => ($mode === 'CASH' || $mode === 'CHEQUE' ? 'IMG_' : 'Screenshot_') . $paidOn->format('Ymd_His') . '.jpg'];
        }
        return $out;
    }

    /** Sign-in / sign-out every working day (attendance), and the location trail for the last three weeks. */
    private function attendance(array $org, array $days): void
    {
        [, , , , , , $loc] = $this->city;
        $today = $this->now->copy()->setTimezone($this->tz)->startOfDay();
        foreach ($org['devices'] as $uid => $dev) {
            $field = in_array($uid, $org['agents']['field'], true);
            $home = $field ? $org['circle'][$uid][0] : $loc[0];
            $points = [];
            $events = [];
            $lastSeen = null;
            for ($d = 62; $d >= 0; $d--) {
                $day = $today->copy()->subDays($d);
                if ($day->isSunday()) {
                    continue;   // calls and visits are Monday–Saturday too
                }
                $in = $day->copy()->setTime(9, $this->rnd->getInt(10, 59));
                $out = $day->copy()->setTime(18, $this->rnd->getInt(5, 59))->addMinutes($this->rnd->getInt(0, 50));
                if ($in->copy()->utc()->gte($this->now)) {
                    continue;
                }
                $endUtc = $out->copy()->utc()->min($this->now->copy()->subMinutes(5));
                $ws = $this->ins('work_sessions', ['device_id' => $dev, 'user_id' => $uid, 'access_hash' => 'demo' . bin2hex(random_bytes(30)), 'refresh_hash' => bin2hex(random_bytes(32)),
                    'epoch' => 1, 'access_expires_at' => $this->ts($in->copy()->utc()->addMinutes(15)), 'refresh_expires_at' => $this->ts($in->copy()->utc()->addHours(12)),
                    'lease_expires_at' => $this->ts($endUtc), 'last_validated_at' => $this->ts($endUtc), 'ended_at' => $this->ts($endUtc), 'end_reason' => 'signed_out',
                    'created_at' => $this->ts($in->copy()->utc())]);
                $lastSeen = $endUtc;
                $this->dayOfWork($uid, $dev, $field, $home, $org['circle'][$uid] ?? [], $day, $in, $out, $endUtc, $ws, $days[$uid][$day->toDateString()] ?? [], $points, $events);
            }
            foreach (array_chunk($points, 500) as $chunk) {
                DB::table('location_events')->insert($chunk);
            }
            usort($events, fn ($a, $b) => strcmp($a['occurred_at'], $b['occurred_at']));
            foreach (array_chunk($events, 500) as $chunk) {
                DB::table('activity_events')->insert($chunk);
            }
            DB::table('managed_devices')->where('id', $dev)->update(['last_contact_at' => $lastSeen ? $this->ts($lastSeen) : null, 'last_ip' => '49.36.' . $this->rnd->getInt(1, 250) . '.' . $this->rnd->getInt(1, 250)]);
        }
        // Policy: each phone has the company baseline and has applied it.
        foreach (ManagedDevice::query()->whereIn('id', array_values($org['devices']))->get() as $device) {
            app(PolicyService::class)->refreshDevice($device);
            $device->refresh()->forceFill(['applied_policy_fingerprint' => $device->desired_policy_fingerprint, 'last_good_policy_fingerprint' => $device->desired_policy_fingerprint,
                'policy_state' => 'applied', 'compliant' => true])->saveQuietly();
        }
    }

    /** A few messages in each team's group chat. */
    private function chat(array $org): void
    {
        $lines = [['lead', 'Good morning team. Please clear today\'s callbacks before 1 pm.'], ['agent', 'Ok sir.'], ['agent', 'Noted.'],
            ['lead', 'Upload slips for every payment. Payments without a slip will be sent back.'], ['agent', 'Done sir, two slips uploaded.'],
            ['lead', 'Good work yesterday. Promise-to-pay follow-ups are the priority this week.'], ['agent', 'Sure.']];
        foreach (['tele', 'field'] as $team) {
            $members = [$org['lead'][$team], ...$org['agents'][$team]];
            $start = Carbon::parse($this->day(-3, 9, 35));
            $conv = $this->ins('conversations', ['team_id' => $org['teams'][$team], 'subject' => DB::table('teams')->where('id', $org['teams'][$team])->value('name'),
                'created_by' => $org['lead'][$team], 'created_at' => $this->day(-60, 10, 0), 'updated_at' => $this->ts($start)]);
            foreach ($members as $m) {
                DB::table('conversation_participants')->insert(['conversation_id' => $conv, 'user_id' => $m, 'company_id' => $this->cid]);
            }
            foreach ($lines as $i => [$who, $body]) {
                $at = $start->copy()->addDays(intdiv($i, 3))->addMinutes(($i % 3) * 7);
                if ($at->gt($this->now)) {
                    break;
                }
                $from = $who === 'lead' ? $org['lead'][$team] : $this->pick($org['agents'][$team]);
                $msg = $this->ins('messages', ['conversation_id' => $conv, 'sender_user_id' => $from, 'body' => $body, 'client_message_id' => $this->uuid(), 'created_at' => $this->ts($at)]);
                foreach (array_diff($members, [$from]) as $m) {
                    DB::table('message_receipts')->insert(['message_id' => $msg, 'user_id' => $m, 'company_id' => $this->cid, 'delivered_at' => $this->ts($at->copy()->addSeconds(20)),
                        'read_at' => $this->ts($at->copy()->addMinutes($this->rnd->getInt(1, 40))->min($this->now))]);
                }
                DB::table('conversations')->where('id', $conv)->update(['last_message_at' => $this->ts($at)]);
            }
        }
    }

    /**
     * Real calls from the demo (Ejaz: demo calls are real): when Super Admin names a "calling source" workspace, its
     * Click2Call / Smart31 settings and numbers are copied in, so console and app calls go out like a paying client's.
     */
    private function copyCalling(): void
    {
        $src = Company::query()->where('slug', (string) PlatformSettings::get('demo_calling_from'))->whereNull('demo')->first();
        if (!$src) {
            return;
        }
        $keep = array_intersect_key((array) $src->settings, array_flip(['click2call', 'smart31', 'sim']));
        DB::table('companies')->where('id', $this->cid)->update(['settings' => json_encode($keep + (array) json_decode((string) DB::table('companies')->where('id', $this->cid)->value('settings'), true))]);
        $vouchers = [];
        foreach (DB::table('c2c_vouchers')->where('company_id', $src->id)->get() as $v) {
            $vouchers[$v->id] = $this->ins('c2c_vouchers', ['voucher_code' => $v->voucher_code, 'numbers' => $v->numbers, 'smart31_numbers' => $v->smart31_numbers,
                'expires_at' => $v->expires_at, 'created_at' => $this->day(-70, 12, 0), 'updated_at' => $this->day(-70, 12, 0)]);
        }
        foreach (DB::table('c2c_vmns')->where('company_id', $src->id)->get() as $v) {
            $this->ins('c2c_vmns', ['virtual_number' => $v->virtual_number, 'click2call' => $v->click2call, 'webrtc' => $v->webrtc, 'obd' => $v->obd, 'tc_verified' => $v->tc_verified,
                'expires_at' => $v->expires_at, 'voucher_id' => $vouchers[$v->voucher_id] ?? null, 'created_at' => $this->day(-70, 12, 0), 'updated_at' => $this->day(-70, 12, 0)]);
        }
        foreach (DB::table('smart31_numbers')->where('company_id', $src->id)->get() as $n) {
            $this->ins('smart31_numbers', ['number' => $n->number, 'label' => $n->label, 'active' => $n->active, 'expires_on' => $n->expires_on, 'calls' => 0, 'source' => $n->source,
                'type' => $n->type, 'group_name' => $n->group_name, 'created_at' => $this->day(-70, 12, 0), 'updated_at' => $this->day(-70, 12, 0)]);
        }
    }

    // ---------------------------------------------------------------- helpers

    /** The 256 customer numbers Ametecs supplied (resources/demo/customer-numbers.txt; they ring Ametecs' own phones). */
    public static function numbers(): array
    {
        static $n;
        return $n ??= array_values(array_filter(array_map('trim', file(resource_path('demo/customer-numbers.txt')))));
    }

    /** Copies the recordings to the shared folder once, and remembers each file's size and checksum. */
    private function shareRecordings(): void
    {
        $disk = Storage::disk(config('ammp.recordings.disk'));
        foreach (array_keys($this->durations) as $name) {
            $bytes = (string) file_get_contents(resource_path("demo/recordings/{$name}.mp3"));
            $key = self::RECORDINGS . "/{$name}.mp3";
            if (!$disk->exists($key)) {
                $disk->put($key, $bytes, ['visibility' => 'private']);
            }
            $this->durations['_bytes'][$name] = [strlen($bytes), hash('sha256', $bytes)];
        }
    }

    /** A recording in one of the city's languages for this outcome (agents switch languages like real callers). */
    private function recording(string $tok): ?string
    {
        $script = self::SCRIPT[$tok] ?? null;
        if (!$script) {
            return null;
        }
        $langs = $this->city[5];
        $first = $this->rnd->getInt(1, 10) <= 7 ? $langs[0] : $this->pick($langs);
        foreach ([$first, ...$langs, 'hi', 'en'] as $l) {
            if (isset($this->durations["{$l}-{$script}"])) {
                return "{$l}-{$script}";
            }
        }
        return null;
    }

    private function person(string $g, bool $unique = true): string
    {
        [$m, $f, $s] = $this->names;
        for ($i = 0; $i < 20; $i++) {
            $n = $this->pick($g === 'f' ? $f : $m) . ' ' . $this->pick($s);
            if (!$unique || !isset($this->usedNames[$n])) {
                return $this->usedNames[$n] = $n;
            }
        }
        return $n;
    }

    private function address(string $region, string $area, string $city, string $pin): string
    {
        [$buildings, $streets] = DemoCities::PLACES[$region];
        $fill = fn (string $s) => preg_replace_callback('/%[ds]/', fn ($m) => $m[0] === '%d' ? (string) $this->rnd->getInt(1, 18) : $this->pick(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H']), $s);
        $house = $this->pick(['Flat %d0%d, ', 'H.No. %d-%d, ', 'Plot %d%d, ', '#%d%d, ', 'Flat %d0%d, ']);
        $house = preg_replace_callback('/%d/', fn () => (string) $this->rnd->getInt(1, 9), $house);
        return "{$house}{$fill($this->pick($buildings))}, {$fill($this->pick($streets))}, {$area}, {$city} - {$pin}";
    }

    /** The next working moment (Mon–Sat, 10:00–17:50 IST) on or after $t's date. */
    /** One working day of a demo employee: what the phone reports (sign-in/out, ROVEXY open or in the background,
     *  lunch break, idle spells) and the GPS trail, a fix every 5 minutes. */
    private function dayOfWork(int $uid, int $dev, bool $field, array $home, array $circle, Carbon $day, Carbon $in, Carbon $out, Carbon $endUtc, int $ws,
        array $stops, array &$points, array &$events): void
    {
        // What the phone reports during the day (the Employee activity report is built from these):
        // sign-in / sign-out, ROVEXY open or in the background, a lunch break and a few idle spells.
        $ev = function (string $type, Carbon $at) use (&$events, $dev, $uid): void {
            if ($at->copy()->utc()->lte($this->now)) {   // nothing in the future
                $events[] = ['company_id' => $this->cid, 'device_id' => $dev, 'user_id' => $uid, 'type' => $type, 'occurred_at' => $this->ts($at->copy()->utc()), 'meta' => '{"demo":true}'];
            }
        };
        $ev('signed_in', $in);
        $ev('app_foreground', $in->copy()->addMinutes(1));
        $lunch = $day->copy()->setTime(13, $this->rnd->getInt(5, 45));
        $back = $lunch->copy()->addMinutes($this->rnd->getInt(28, 45));
        $ev('app_background', $lunch);
        $ev('break_start', $lunch);
        $ev('break_end', $back);
        $ev('app_foreground', $back);
        for ($k = $this->rnd->getInt(0, 3); $k > 0; $k--) {   // phone pocketed for a while: idle
            $from = $day->copy()->setTime($this->pick([10, 11, 15, 16, 17]), $this->rnd->getInt(0, 40));
            $ev('app_background', $from);
            $ev('app_foreground', $from->copy()->addMinutes($this->rnd->getInt(12, 30)));
        }
        if ($out->copy()->utc()->lte($this->now)) {
            $ev('app_background', $out->copy()->subMinutes(1));
            $ev('signed_out', $out);
        }
        // Trail: a fix every 5 minutes. Field agents wait at a place and ride (~22 km/h) to the next
        // visit just in time for it, then back to their base area at sign-out — a real day is tens of km,
        // not the hundreds a random jump every few minutes adds up to. Office staff stay at the office.
        $anchors = [[$home[2], $home[3], $in->copy()->utc()]];
        if ($field) {
            // Besides the recorded visits, a field agent tours his circle (addresses checked, doors
            // found locked, follow-ups): 3–6 more stops a day, mostly in one area of it, so a day is ~15–50 km.
            $a = $this->pick($circle);   // today's area of the circle
            for ($k = $this->rnd->getInt(3, 6); $k > 0; $k--) {
                $at = $day->copy()->setTime($this->pick([10, 11, 12, 14, 15, 16, 17]), $this->rnd->getInt(0, 59))->utc();
                if ($at->lt($endUtc)) {
                    $stops[] = [$a[2] + $this->rnd->getInt(-80, 80) / 10000, $a[3] + $this->rnd->getInt(-80, 80) / 10000, $at];
                }
            }
            usort($stops, fn ($a, $b) => $a[2] <=> $b[2]);
            foreach ($stops as $s) {
                $anchors[] = [$s[0], $s[1], $s[2]->copy()->utc()];
            }
            $anchors[] = [$home[2], $home[3], $endUtc->copy()];
        }
        $battery = $this->rnd->getInt(82, 100);
        for ($p = $in->copy()->utc(); $p->lte($endUtc); $p->addMinutes(5)) {
            [$lat, $lng] = $this->trailAt($anchors, $p);
            [$lat, $lng] = [$lat + $this->rnd->getInt(-5, 5) / 100000, $lng + $this->rnd->getInt(-5, 5) / 100000];   // GPS wobble, ±5 m
            $battery = max(9, $battery - $this->rnd->getInt(0, 2));
            $points[] = ['company_id' => $this->cid, 'device_id' => $dev, 'user_id' => $uid, 'work_session_id' => $ws, 'lat' => round($lat, 7), 'lng' => round($lng, 7),
                'accuracy_m' => $this->rnd->getInt(4, 28), 'provider' => 'fused', 'battery_pct' => $battery, 'network_type' => $field ? 'cellular' : 'wifi',
                'captured_at' => $this->ts($p), 'received_at' => $this->ts($p->copy()->addSeconds($this->rnd->getInt(2, 40))), 'mock' => false];
        }
    }

    /** Position at time $t on a day of [lat, lng, time] anchors: wait at the last place, leave just in time to reach the next. */
    private function trailAt(array $anchors, Carbon $t): array
    {
        [$a, $b] = [$anchors[0], null];
        foreach ($anchors as $x) {
            if ($x[2]->lte($t)) {
                $a = $x;
            } else {
                $b = $x;
                break;
            }
        }
        if (!$b) {
            return [$a[0], $a[1]];
        }
        $km = 6371 * 2 * asin(sqrt(sin(deg2rad($b[0] - $a[0]) / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin(deg2rad($b[1] - $a[1]) / 2) ** 2));
        $ride = min(max(1, $a[2]->diffInSeconds($b[2])), (int) ($km * 1.3 / 22 * 3600));   // road ≈ 1.3 × straight line, ~22 km/h
        $leave = $b[2]->copy()->subSeconds($ride);
        if ($t->lt($leave) || $ride === 0) {
            return [$a[0], $a[1]];
        }
        $f = $leave->diffInSeconds($t) / max(1, $ride);
        return [$a[0] + ($b[0] - $a[0]) * $f, $a[1] + ($b[1] - $a[1]) * $f];
    }

    private function workTime(Carbon $t): Carbon
    {
        $t = $t->copy()->setTimezone($this->tz);
        if ($t->isSunday()) {
            $t->addDay();
        }
        return $t->setTime($this->rnd->getInt(10, 17), $this->rnd->getInt(0, 50), $this->rnd->getInt(0, 59));
    }

    /** IST day offset from today at a time, as a UTC database timestamp. */
    private function day(int $offset, int $h, int $m): string
    {
        return $this->ts($this->now->copy()->setTimezone($this->tz)->addDays($offset)->setTime($h, $m)->utc());
    }

    private function ts(Carbon $c): string
    {
        return $c->copy()->utc()->format('Y-m-d H:i:s');
    }

    /** Insert a row; rows nobody needs the id of wait in a buffer and go in batches (a demo is ~6,000 rows: one web request). */
    private function ins(string $table, array $row, bool $id = true): int
    {
        $row['company_id'] = $this->cid;
        if ($id) {
            return (int) DB::table($table)->insertGetId($row);
        }
        $this->buffer[$table][implode(',', array_keys($row))][] = $row;
        return 0;
    }

    private function flush(): void
    {
        foreach ($this->buffer as $table => $shapes) {
            foreach ($shapes as $rows) {
                foreach (array_chunk($rows, 300) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        }
        $this->buffer = [];
    }

    private function pick(array $a): mixed
    {
        return $a[$this->rnd->getInt(0, count($a) - 1)];
    }

    private function weighted(array $rows): array
    {
        $n = $this->rnd->getInt(1, array_sum(array_column($rows, 1)));
        foreach ($rows as $r) {
            if (($n -= $r[1]) <= 0) {
                return $r;
            }
        }
        return $rows[0];
    }

    /** Random (not seeded): call and phone ids must be unique across every demo of the same city. */
    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    private static function e164(string $mobile): string
    {
        $d = preg_replace('/\D/', '', $mobile);
        return strlen($d) === 10 ? '+91' . $d : '+' . ltrim($d, '+');
    }
}
