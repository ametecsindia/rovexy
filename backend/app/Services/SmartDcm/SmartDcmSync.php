<?php

declare(strict_types=1);

namespace App\Services\SmartDcm;

use App\Domain\Customers\PhoneNormaliser;
use App\Models\Branch;
use App\Models\C2c\Vmn;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\ExternalRef;
use App\Models\ImportJob;
use App\Models\IntegrationConnection;
use App\Models\Role;
use App\Models\Smart31Number;
use App\Models\Team;
use App\Models\User;
use App\Services\ImportService;
use App\Support\Audit;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pull from SmartDCM (SmartDCM is the master for cases, allocation and users):
 *   telephony (CRM id, gateway secret, company numbers, Smart31 pool) → teams/branches → users → portfolios → cases.
 * Each list is read from the saved cursor, so a run continues where the last one stopped; a run stops after
 * $budgetSeconds and the job dispatches itself again. Nothing done here is sent back to SmartDCM ($syncing).
 */
final class SmartDcmSync
{
    public const SYSTEM = 'smartdcm';

    /** True while a pull runs: outbox observers must not echo SmartDCM's own data back to it. */
    public static bool $syncing = false;

    /** SmartDCM role → ROVEXY role key + team membership. Admins are only linked, never created (no console admin by sync). */
    private const ROLES = ['telecaller' => 'employee', 'fos' => 'employee', 'agent' => 'employee', 'field' => 'employee',
        'team_leader' => 'supervisor', 'tl' => 'supervisor', 'manager' => 'supervisor', 'supervisor' => 'supervisor'];

    private const CLOSED = ['closed', 'paid', 'settled', 'resolved', 'foreclosed', 'surrendered'];
    private const FOLLOW = ['ptp', 'promise', 'callback', 'follow_up', 'followup', 'broken_ptp'];

    private array $log = [];

    /** SmartDCM portfolio id → its ROVEXY portfolio, for this run. */
    private array $jobs = [];

    public function __construct(private readonly IntegrationConnection $conn, private readonly SmartDcmClient $api)
    {
    }

    public static function for(IntegrationConnection $conn): self
    {
        return new self($conn, new SmartDcmClient($conn));
    }

    /** @return array{done: bool, log: list<string>} done = every list is up to date (false: run again to continue) */
    public function run(int $budgetSeconds = 50): array
    {
        $until = microtime(true) + $budgetSeconds;
        self::$syncing = true;
        $done = true;
        try {
            $this->teams();   // first: numbers and Smart31 are given to SmartDCM teams
            if ($this->conn->option('use_calling', true)) {
                try {
                    $this->telephony();
                } catch (\Throwable $e) {   // calling settings must never stop cases and users from syncing
                    $this->log[] = 'calling NOT updated: ' . mb_substr($e->getMessage(), 0, 120);
                }
            }
            $done = $this->users($until) && $this->portfolios($until) && $this->cases($until);
            $this->status('OK — ' . ($done ? 'up to date' : 'part synced, continuing') . ' · ' . implode(' · ', $this->log));
            $this->conn->forceFill(['last_ok_at' => now()])->save();
        } catch (\Throwable $e) {
            $this->status('FAILED — ' . mb_substr($e->getMessage(), 0, 200));
            throw $e;
        } finally {
            self::$syncing = false;
        }
        return ['done' => $done, 'log' => $this->log];
    }

    /** CRM id, gateway secret, company numbers and the Smart31 pool come from SmartDCM: one telephony account for both systems. */
    public function telephony(): void
    {
        $t = $this->api->get('/telephony');
        $company = app(CurrentCompany::class)->get();
        $teams = ExternalRef::map(self::SYSTEM, 'team');
        $mapTeams = fn ($ids) => array_values(array_filter(array_map(fn ($id) => $teams[(string) $id] ?? null, (array) $ids)));
        DB::transaction(function () use ($t, $company, $mapTeams) {
            $old = (array) ($company->setting('click2call') ?? []);
            $c2c = (array) ($t['c2c'] ?? []);
            $new = array_replace($old, array_filter([
                'crm_id' => filled($t['crm_id'] ?? null) ? (string) $t['crm_id'] : null,
                'secret' => filled($c2c['gateway_secret'] ?? null) ? Crypt::encryptString((string) $c2c['gateway_secret']) : null,
                'lc_secret' => filled($c2c['lc_secret'] ?? null) ? Crypt::encryptString((string) $c2c['lc_secret']) : null,
            ]));
            // Switched on only if the admin never chose a mode here; an admin's own choice (even "off") stays.
            if (!empty($c2c['enabled']) && empty($old['mode'])) {
                $new['mode'] = 'choice';
            }
            $settings = array_replace($company->settings ?? [], ['click2call' => $new]);
            $smart = (array) ($t['smart31'] ?? []);
            if (!empty($smart['enabled']) && data_get($company->settings, 'smart31.enabled') === null) {
                $settings['smart31'] = array_replace((array) ($settings['smart31'] ?? []), ['enabled' => true]);
            }
            $company->forceFill(['settings' => $settings])->save();
            $digits = function ($n): string {
                $num = preg_replace('/\D/', '', (string) $n);
                return strlen($num) === 12 && str_starts_with($num, '91') ? substr($num, 2) : mb_substr($num, 0, 20);
            };
            if (array_key_exists('numbers', $t)) {   // SmartDCM's numbers replace the company numbers (as a voucher does), updated in place
                $keep = [];
                foreach ((array) $t['numbers'] as $n) {
                    $num = $digits($n['number'] ?? '');
                    // ROVEXY offers Click2Call and Smart31 only (27 Nov 2026): WebRTC-only numbers are not taken.
                    if ($num === '' || isset($keep[$num]) || (!($n['click2call'] ?? true) && !($n['obd'] ?? false))) {
                        continue;
                    }
                    // LC control (27 Nov 2026): each number carries its LC voucher, which ROVEXY checks with the LC Manager
                    // itself (LcGuard). A voucher the LC Manager revoked / expired is never brought back by SmartDCM.
                    $code = trim((string) ($n['voucher_code'] ?? $c2c['voucher_code'] ?? ''));
                    $voucher = $code !== '' ? \App\Models\C2c\Voucher::query()->firstOrCreate(['voucher_code' => mb_substr($code, 0, 100)], ['numbers' => 0, 'status' => 'active']) : null;
                    if ($voucher && in_array($voucher->status, ['revoked', 'expired'], true)) {
                        continue;
                    }
                    $keep[$num] = true;
                    Vmn::query()->updateOrCreate(['virtual_number' => $num], ['voucher_id' => $voucher?->id, 'click2call' => (bool) ($n['click2call'] ?? true), 'webrtc' => false,
                        'obd' => (bool) ($n['obd'] ?? false), 'tc_verified' => (bool) ($n['tc_verified'] ?? false), 'team_ids' => $mapTeams($n['team_ids'] ?? []),
                        'expires_at' => $this->date($n['expires_on'] ?? null)]);
                }
                Vmn::query()->whereNotIn('virtual_number', array_keys($keep) ?: [''])->delete();
            }
            if (array_key_exists('numbers', $smart)) {   // SmartDCM's Smart31 numbers, updated in place (rotation and counts kept); hand-added ones stay
                $keep = [];
                foreach ((array) $smart['numbers'] as $n) {
                    $num = $digits($n['number'] ?? '');
                    if ($num === '' || isset($keep[$num]) || (array_key_exists('c2c', $n) && !$n['c2c']) || !empty($n['webrtc'])) {   // WebRTC Smart31 numbers are not taken
                        continue;
                    }
                    $keep[$num] = true;
                    $row = Smart31Number::query()->where('number', $num)->first();
                    if ($row && $row->source !== self::SYSTEM) {
                        continue;   // the admin added this number by hand: leave it as it is
                    }
                    $group = filled($n['group'] ?? null) ? mb_substr((string) $n['group'], 0, 60) : null;
                    $attrs = ['label' => $group, 'group_name' => $group, 'source' => self::SYSTEM, 'team_ids' => $mapTeams($n['team_ids'] ?? []), 'active' => true,
                        'expires_on' => $this->date($n['expires_on'] ?? null)];
                    $row ? $row->fill($attrs)->save() : Smart31Number::query()->create($attrs + ['number' => $num]);
                }
                Smart31Number::query()->where('source', self::SYSTEM)->whereNotIn('number', array_keys($keep) ?: [''])->delete();
            }
        });
        $this->saveState(['crm_id' => $t['crm_id'] ?? null]);
        $this->log[] = 'calling: ' . count((array) ($t['numbers'] ?? [])) . ' numbers, ' . count((array) ($t['smart31']['numbers'] ?? [])) . ' Smart31';
    }

    public function teams(): void
    {
        $rows = (array) ($this->api->get('/teams')['data'] ?? []);
        $branches = ExternalRef::map(self::SYSTEM, 'branch');
        $teams = ExternalRef::map(self::SYSTEM, 'team');
        foreach ($rows as $t) {
            $branchId = null;
            if (!empty($t['branch']['id'])) {
                $rid = (string) $t['branch']['id'];
                $name = mb_substr(trim((string) ($t['branch']['name'] ?? '')) ?: "Branch {$rid}", 0, 100);
                $b = isset($branches[$rid]) ? Branch::query()->find($branches[$rid]) : null;
                $b ??= Branch::query()->where('name', $name)->first() ?? Branch::query()->create(['name' => $name, 'code' => $this->code(Branch::class, $name, 'BR')]);
                $b->name !== $name && $b->forceFill(['name' => $name])->save();
                ExternalRef::link(self::SYSTEM, 'branch', $rid, $b->id);
                $branches[$rid] = $branchId = $b->id;
            }
            $rid = (string) ($t['id'] ?? '');
            if ($rid === '') {
                continue;
            }
            $name = mb_substr(trim((string) ($t['name'] ?? '')) ?: "Team {$rid}", 0, 100);
            $team = isset($teams[$rid]) ? Team::query()->find($teams[$rid]) : null;
            $team ??= Team::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
                ?? Team::query()->create(['name' => $name, 'code' => $this->code(Team::class, $name, 'GRP'), 'branch_id' => $branchId]);
            $team->fill(['name' => $name, 'branch_id' => $branchId ?? $team->branch_id])->isDirty() && $team->save();
            ExternalRef::link(self::SYSTEM, 'team', $rid, $team->id);
        }
        $this->log[] = 'teams: ' . count($rows);
    }

    public function users(float $until): bool
    {
        $teams = ExternalRef::map(self::SYSTEM, 'team');
        $linked = ExternalRef::map(self::SYSTEM, 'user');
        $roleIds = Role::query()->whereIn('key', ['employee', 'supervisor'])->pluck('id', 'key');
        $cid = app(CurrentCompany::class)->id();
        $country = app(CurrentCompany::class)->get()->default_country ?: 'IN';
        $stats = ['linked' => 0, 'created' => 0, 'updated' => 0, 'not_created' => 0];
        $unmatched = (array) ($this->conn->state['unmatched_users'] ?? []);
        $done = $this->pages('users', function (array $rows) use (&$teams, &$linked, $roleIds, $cid, $country, &$stats, &$unmatched) {
            foreach ($rows as $r) {
                $rid = (string) ($r['id'] ?? '');
                if ($rid === '') {
                    continue;
                }
                $phone = null;
                try { $phone = filled($r['mobile'] ?? null) ? PhoneNormaliser::normalise((string) $r['mobile'], $country) : null; } catch (\Throwable) { }
                $code = mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($r['employee_code'] ?? '')), 0, 64);
                $email = filter_var(mb_strtolower(trim((string) ($r['email'] ?? ''))), FILTER_VALIDATE_EMAIL) ?: null;
                $u = isset($linked[$rid]) ? User::query()->find($linked[$rid]) : null;
                // Match an existing ROVEXY person: employee code, then e-mail, then mobile.
                $u ??= ($code !== '' ? User::query()->whereRaw('lower(employee_code) = ?', [mb_strtolower($code)])->first() : null)
                    ?? ($email ? User::query()->whereRaw('lower(email) = ?', [$email])->first() : null)
                    ?? ($phone ? User::query()->where('phone', $phone)->first() : null);
                $role = self::ROLES[mb_strtolower((string) ($r['role'] ?? ''))] ?? null;
                $active = ($r['active'] ?? true) !== false;
                if ($u === null) {
                    if (!$active || $role === null || !$this->conn->option('auto_create_users', true)) {
                        $role === null && $active && $unmatched[$rid] = ['name' => (string) ($r['name'] ?? ''), 'role' => (string) ($r['role'] ?? ''), 'reason' => 'role not created automatically'];
                        continue;
                    }
                    try {
                        app(\App\Services\Platform\Licensing::class)->assertCanAddUsers(1);
                    } catch (\Throwable $e) {
                        $stats['not_created']++;
                        $unmatched[$rid] = ['name' => (string) ($r['name'] ?? ''), 'role' => (string) ($r['role'] ?? ''), 'reason' => 'plan user limit reached'];
                        continue;
                    }
                    $code = $code !== '' && !User::query()->whereRaw('lower(employee_code) = ?', [mb_strtolower($code)])->exists() ? $code : 'DCM' . $rid;
                    $email = $email && !User::query()->whereRaw('lower(email) = ?', [$email])->exists() ? $email : mb_strtolower($code) . '@smartdcm.local';
                    $u = User::query()->create(['employee_code' => $code, 'email' => $email, 'name' => mb_substr((string) ($r['name'] ?? $code), 0, 150),
                        'phone' => $phone, 'password' => Str::password(16), 'status' => 'active']);
                    $u->roles()->attach($roleIds[$role], ['company_id' => $cid]);
                    $stats['created']++;
                } elseif ($u->roles()->whereNotIn('key', ['employee', 'supervisor', 'telecaller'])->exists()) {
                    // A ROVEXY admin (or any other role): linked only — SmartDCM never changes their password, name or status.
                    $stats['linked']++;
                    unset($unmatched[$rid]);
                    ExternalRef::link(self::SYSTEM, 'user', $rid, $u->id);
                    $linked[$rid] = $u->id;
                    continue;
                } else {
                    $u->fill(array_filter(['name' => filled($r['name'] ?? null) ? mb_substr((string) $r['name'], 0, 150) : null, 'phone' => $phone], fn ($v) => $v !== null));
                    // Sign-in e-mail follows SmartDCM, so people use the same e-mail + password in ROVEXY (unless another person has it).
                    if ($email && mb_strtolower((string) $u->email) !== $email && !User::query()->whereRaw('lower(email) = ?', [$email])->whereKeyNot($u->id)->exists()) {
                        $u->email = $email;
                    }
                    if (!$active && $u->status === 'active') {
                        $u->status = 'suspended';   // left SmartDCM → can't sign in to ROVEXY either
                    }
                    $u->isDirty() && $u->save() && $stats['updated']++;
                    $stats['linked']++;
                }
                unset($unmatched[$rid]);
                ExternalRef::link(self::SYSTEM, 'user', $rid, $u->id);
                $linked[$rid] = $u->id;
                if (filled($r['c2c_agent_number'] ?? null)) {
                    $u->forceFill(['click_agent_number' => preg_replace('/\D/', '', (string) $r['c2c_agent_number'])])->saveQuietly();
                }
                // Same password as SmartDCM (both store bcrypt): written as it is, never hashed again.
                if ($this->conn->option('share_passwords', true) && is_string($r['password_hash'] ?? null) && preg_match('/^\$2[aby]\$\d\d\$[.\/A-Za-z0-9]{53}$/', $r['password_hash'])) {
                    DB::table('users')->where('id', $u->id)->update(['password' => $r['password_hash']]);
                }
                // Team membership follows SmartDCM (only for teams SmartDCM told us about; other ROVEXY teams stay).
                $want = array_values(array_filter(array_map(fn ($t) => $teams[(string) $t] ?? null, (array) ($r['team_ids'] ?? []))));
                $membership = $role === 'supervisor' ? 'supervisor' : 'member';
                $known = array_values($teams);
                $have = DB::table('team_user')->where('user_id', $u->id)->whereIn('team_id', $known)->pluck('team_id')->map(fn ($i) => (int) $i)->all();
                foreach (array_diff($have, $want) as $gone) {
                    $u->teams()->detach($gone);
                }
                foreach (array_diff($want, $have) as $add) {
                    $u->teams()->attach($add, ['company_id' => $cid, 'membership' => $membership]);
                }
            }
        }, $until);
        $this->saveState(['unmatched_users' => $unmatched]);
        $this->log[] = "users: {$stats['created']} new, {$stats['linked']} linked" . ($stats['not_created'] ? ", {$stats['not_created']} over the plan limit" : '');
        return $done;
    }

    public function portfolios(float $until): bool
    {
        $n = 0;
        $done = $this->pages('portfolios', function (array $rows) use (&$n) {
            foreach ($rows as $p) {
                $rid = (string) ($p['id'] ?? '');
                if ($rid !== '') {
                    $this->portfolioJob($rid, $p);
                    $n++;
                }
            }
        }, $until);
        $this->log[] = "portfolios: {$n}";
        return $done;
    }

    public function cases(float $until): bool
    {
        $users = ExternalRef::map(self::SYSTEM, 'user');
        $teams = ExternalRef::map(self::SYSTEM, 'team');
        $country = app(CurrentCompany::class)->get()->default_country ?: 'IN';
        $s = ['created' => 0, 'updated' => 0, 'withdrawn' => 0, 'skipped' => 0];
        $done = $this->pages('cases', function (array $rows) use ($users, $teams, $country, &$s) {
            Customer::changedBy('smartdcm', null, function () use ($rows, $users, $teams, $country, &$s) {
                foreach ($rows as $r) {
                    try {
                        DB::transaction(fn () => $this->upsertCase($r, $users, $teams, $country, $s));
                    } catch (\Throwable $e) {   // one bad row is skipped (and logged); it never blocks the rest
                        $s['skipped']++;
                        \Illuminate\Support\Facades\Log::warning('smartdcm.case_skipped', ['case' => $r['id'] ?? null, 'error' => mb_substr($e->getMessage(), 0, 200)]);
                    }
                }
            }, 'From SmartDCM.');
        }, $until);
        $this->log[] = "cases: {$s['created']} new, {$s['updated']} updated" . ($s['withdrawn'] ? ", {$s['withdrawn']} withdrawn" : '') . ($s['skipped'] ? ", {$s['skipped']} skipped (no account no. / mobile)" : '');
        if ($s['created'] + $s['updated'] > 0 && app(\App\Services\GoogleMaps::class)->configured()) {
            \App\Jobs\GeocodePending::dispatch(app(CurrentCompany::class)->id());   // pins for new addresses
        }
        return $done;
    }

    private function upsertCase(array $r, array $users, array $teams, string $country, array &$s): void
    {
        $rid = (string) ($r['id'] ?? '');
        $ref = mb_substr(trim((string) ($r['account_no'] ?? '')), 0, 64);
        $local = ExternalRef::query()->where('system', self::SYSTEM)->where('entity', 'case')->where('remote_id', $rid)->value('local_id');
        $c = $local ? Customer::query()->lockForUpdate()->find($local) : ($ref !== '' ? Customer::query()->where('customer_ref', $ref)->lockForUpdate()->first() : null);
        if (!empty($r['withdrawn'])) {
            if ($c && $c->withdrawn_at === null) {
                $c->forceFill(['withdrawn_at' => now()])->save();
                CustomerAssignment::query()->create(['customer_id' => $c->id, 'user_id' => null, 'team_id' => null, 'action' => 'withdrawn', 'actor_user_id' => null]);
                $s['withdrawn']++;
            }
            return;
        }
        $phone = null;
        try { $phone = filled($r['mobile'] ?? null) ? PhoneNormaliser::normalise((string) $r['mobile'], $country) : null; } catch (\Throwable) { }
        if ($rid === '' || $ref === '' || ($phone === null && $c === null)) {
            $s['skipped']++;
            return;
        }
        $alt = null;
        try { $alt = filled($r['alt_mobile'] ?? null) ? PhoneNormaliser::normalise((string) $r['alt_mobile'], $country) : null; } catch (\Throwable) { }
        $job = !empty($r['portfolio_id']) ? $this->portfolioJob((string) $r['portfolio_id'], null) : null;
        $userId = isset($r['assigned_user_id']) ? ($users[(string) $r['assigned_user_id']] ?? null) : null;
        $teamId = isset($r['team_id']) ? ($teams[(string) $r['team_id']] ?? null) : null;
        if ($teamId === null && $userId !== null) {
            $teamId = (int) (DB::table('team_user')->where('user_id', $userId)->whereIn('team_id', array_values($teams))->value('team_id') ?? 0) ?: null;
        }
        // 25 Nov 2026: SmartDCM allocates nobody (unallocated there, or its agent isn't linked) → keep ROVEXY's assignment.
        // Before, such a row un-assigned a case ROVEXY had given an agent (same account no.), so it vanished from the app.
        if ($userId === null && $teamId === null && $c) {
            [$userId, $teamId] = [$c->assigned_user_id, $c->assigned_team_id];
        }
        $num = fn ($k) => isset($r[$k]) && is_numeric($r[$k]) ? round((float) $r[$k], 2) : null;
        $date = fn ($k) => $this->date($r[$k] ?? null);
        $cut = fn ($k, int $n) => filled($r[$k] ?? null) && is_scalar($r[$k]) ? mb_substr(trim((string) $r[$k]), 0, $n) : null;
        $email = filter_var($r['email'] ?? null, FILTER_VALIDATE_EMAIL);
        $account = array_filter(['email' => $email && strlen($email) <= 150 ? $email : null, 'address' => $cut('address', 500), 'state' => $cut('state', 100), 'loan_amount' => $num('loan_amount'), 'outstanding' => $num('pos'), 'principal_os' => $num('principal_os'), 'interest_os' => $num('interest_os'),
            'charges' => $num('charges'), 'overdue_amount' => $num('overdue_amount'), 'emi_amount' => $num('emi_amount'), 'due_date' => $date('due_date'),
            'dpd' => isset($r['dpd']) && is_numeric($r['dpd']) ? (int) $r['dpd'] : null, 'last_paid_on' => $date('last_paid_on'), 'last_paid_amount' => $num('last_paid_amount')],
            fn ($v) => $v !== null && $v !== '');
        $details = [];
        foreach ((array) ($r['extra'] ?? []) as $k => $v) {   // the bank file's other columns, shown like an upload's kept columns
            if (is_scalar($v) && (string) $v !== '' && count($details) < 100) {
                $details['x:' . mb_substr((string) $k, 0, 60)] = mb_substr((string) $v, 0, 500);
            }
        }
        foreach (['bucket' => 'Bucket', 'product' => 'Product', 'bank' => 'Bank / client'] as $k => $label) {
            filled($r[$k] ?? null) && $details["x:{$label}"] = mb_substr((string) $r[$k], 0, 200);
        }
        $remoteStatus = mb_strtolower((string) ($r['status'] ?? ''));
        $status = in_array($remoteStatus, self::CLOSED, true) ? 'closed' : (in_array($remoteStatus, ['dnc', 'do_not_call'], true) ? 'do_not_call'
            : (in_array($remoteStatus, self::FOLLOW, true) ? 'follow_up' : ($c?->status ?? 'new')));
        $attrs = array_filter(['name' => $cut('customer_name', 150), 'phone' => $phone, 'alt_phone' => $alt,
            'city' => $cut('city', 100), 'pincode' => $cut('pincode', 12), 'campaign' => $cut('product', 100) ?? $cut('bank', 100)], fn ($v) => $v !== null && $v !== '')
            + ['status' => $status, 'portfolio_type' => 'collections', 'last_import_id' => $job?->id ?? $c?->last_import_id]
            + ImportService::accountAttrs($account);
        $attrs['details'] = array_filter(array_merge((array) ($c?->details ?? []), $details)) ?: null;
        $changed = !$c || $c->assigned_user_id !== $userId || $c->assigned_team_id !== $teamId || $c->withdrawn_at !== null;
        if ($c) {
            $c->fill($attrs + ['assigned_user_id' => $userId, 'assigned_team_id' => $teamId, 'withdrawn_at' => null]);
            if ($c->isDirty(\App\Models\CustomerAddressChange::ADDRESS_FIELDS)) {
                $c->fill(['lat' => null, 'lng' => null, 'geo_status' => null, 'geo_precision' => null]);   // new address → fresh pin
            }
            $c->isDirty() && $c->save() && $s['updated']++;
        } else {
            $c = Customer::query()->create($attrs + ['customer_ref' => $ref, 'name' => $attrs['name'] ?? $ref, 'assigned_user_id' => $userId, 'assigned_team_id' => $teamId]);
            $s['created']++;
        }
        ExternalRef::link(self::SYSTEM, 'case', $rid, $c->id);
        if ($changed) {
            CustomerAssignment::query()->create(['customer_id' => $c->id, 'user_id' => $userId, 'team_id' => $teamId, 'action' => 'assigned', 'actor_user_id' => null, 'import_job_id' => $job?->id]);
        }
    }

    /** Y-m-d, or null when SmartDCM sent something that is not a date. */
    private function date(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    /** A SmartDCM portfolio is shown in ROVEXY as a portfolio (an import job with format "smartdcm"). */
    private function portfolioJob(string $rid, ?array $p): ImportJob
    {
        if ($p === null && isset($this->jobs[$rid])) {
            return $this->jobs[$rid];
        }
        $local = ExternalRef::query()->where('system', self::SYSTEM)->where('entity', 'portfolio')->where('remote_id', $rid)->value('local_id');
        $job = $local ? ImportJob::query()->find($local) : null;
        $name = mb_substr(trim((string) ($p['name'] ?? '')) ?: ($job?->portfolio ?? "SmartDCM portfolio {$rid}"), 0, 150);
        if (!$job) {
            $job = ImportJob::query()->create(['uploaded_by' => null, 'original_name' => $name, 'stored_key' => 'smartdcm:portfolio:' . $rid, 'format' => 'smartdcm',
                'size_bytes' => 0, 'sha256' => str_repeat('0', 64), 'status' => 'completed', 'portfolio' => $name, 'portfolio_type' => 'collections',
                'options' => ['source' => 'smartdcm', 'remote_id' => $rid], 'finished_at' => now()]);
            ExternalRef::link(self::SYSTEM, 'portfolio', $rid, $job->id);
        } elseif ($p !== null && $job->portfolio !== $name) {
            $job->forceFill(['portfolio' => $name, 'original_name' => $name])->save();
        }
        return $this->jobs[$rid] = $job;
    }

    /** Read a cursor-paged list from where the last run stopped. @return bool true = reached the end */
    private function pages(string $list, callable $each, float $until): bool
    {
        $cursor = $this->conn->state['cursors'][$list] ?? null;
        do {
            [$rows, $next] = $this->api->page('/' . $list, $cursor, $list === 'cases' ? 500 : 200);
            $rows !== [] && $each($rows);
            if ($next !== null) {
                $cursor = $next;
            } elseif ($rows !== []) {
                // End reached: next run asks only for what changed since the newest row seen (+ that row's id, so nothing repeats).
                $last = end($rows);
                $cursor = array_filter(['updated_since' => $last['updated_at'] ?? ($cursor['updated_since'] ?? null), 'after_id' => $last['id'] ?? null]);
            }
            $this->saveState(['cursors' => array_replace((array) ($this->conn->state['cursors'] ?? []), [$list => $cursor])]);
        } while ($next !== null && microtime(true) < $until);
        return $next === null;
    }

    private function saveState(array $patch): void
    {
        $this->conn->forceFill(['state' => array_replace((array) $this->conn->state, $patch, ['synced_at' => now()->toAtomString()])])->save();
    }

    private function status(string $s): void
    {
        $this->conn->forceFill(['last_status' => mb_substr($s, 0, 255)])->save();
        Audit::record('smartdcm.sync', $this->conn, ['status' => mb_substr($s, 0, 200)]);
    }

    private function code(string $model, string $name, string $fallback): string
    {
        $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: $fallback, 0, 24));
        $code = $base;
        for ($i = 2; $model::query()->where('code', $code)->exists(); $i++) {
            $code = $base . $i;
        }
        return $code;
    }
}
