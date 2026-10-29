<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Customers\PhoneNormaliser;
use App\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class PeopleController extends Controller
{
    public function employees(Request $r)
    {
        $teams = $r->user()->supervisedTeamIds();
        return response()->json(User::query()
            ->when($teams !== null, fn ($q) => $q->whereHas('teams', fn ($t) => $t->whereIn('teams.id', $teams)))
            ->when($r->query('role'), fn ($q, $k) => $q->whereHas('roles', fn ($w) => $w->where('key', $k)))
            ->when($r->query('team_id'), fn ($q, $t) => $q->whereHas('teams', fn ($w) => $w->where('teams.id', $t)))
            ->with(['teams:id,code,name', 'roles:id,key,name'])->orderBy('name')
            // ponytail: per_page up to 1000 so pickers (device approval) list every employee, not just the first 100.
            ->paginate(min(1000, max(1, (int) $r->query('per_page', 100))), ['id', 'employee_code', 'name', 'email', 'phone', 'status', 'last_login_at', 'mfa_enabled_at']));
    }

    public function createEmployee(Request $r, CurrentCompany $current)
    {
        $cid = $current->id();
        $data = $r->validate([
            'employee_code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users')->where('company_id', $cid)],
            'name' => 'required|string|max:150',
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users')->where('company_id', $cid)],
            'phone' => 'nullable|string|max:20',
            'password' => ['nullable', Password::defaults()],
            'roles' => 'required|array|min:1', 'roles.*' => 'string',
            'team_ids' => 'array', 'team_ids.*' => 'integer',
            'teams' => 'array', 'teams.*.id' => 'required|integer', 'teams.*.membership' => 'required|in:member,supervisor',
        ]);
        $this->guardRoleGrant($r->user(), $data['roles']);
        $this->requireEmailForConsole($data['roles'], $data['email'] ?? null);
        $generated = empty($data['password']) ? Str::password(10, true, true, false) : null;
        $data['password'] = $data['password'] ?? $generated;
        $teamRows = collect($data['teams'] ?? array_map(fn ($id) => ['id' => $id, 'membership' => 'member'], $data['team_ids'] ?? []));
        app(\App\Services\Platform\Licensing::class)->assertCanAddUsers();   // plan user limit
        $user = DB::transaction(function () use ($data, $cid, $teamRows) {
            $u = User::query()->create([
                'employee_code' => $data['employee_code'], 'name' => $data['name'], 'email' => isset($data['email']) ? strtolower($data['email']) : null,
                'phone' => !empty($data['phone']) ? PhoneNormaliser::normalise($data['phone'], app(CurrentCompany::class)->get()->default_country) : null,
                'password' => $data['password'], 'status' => 'active',
            ]);
            $u->roles()->attach(Role::query()->whereIn('key', $data['roles'])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['company_id' => $cid]])->all());
            $valid = Team::query()->whereIn('id', $teamRows->pluck('id'))->pluck('id')->all();
            $u->teams()->attach($teamRows->whereIn('id', $valid)->mapWithKeys(fn ($t) => [$t['id'] => ['company_id' => $cid, 'membership' => $t['membership']]])->all());
            return $u;
        });
        Audit::record('employee.created', $user, ['roles' => $data['roles']]);
        return response()->json(['id' => $user->id, 'employee_code' => $user->employee_code, 'email' => $user->email, 'password' => $generated], 201);
    }

    public function updateEmployee(Request $r, int $id)
    {
        $user = User::query()->findOrFail($id);
        $data = $r->validate(['name' => 'string|max:150', 'phone' => 'nullable|string|max:20', 'status' => 'in:active,suspended,left',
            'roles' => 'array', 'roles.*' => 'string', 'password' => ['nullable', Password::defaults()],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users')->where('company_id', $user->company_id)->ignore($user->id)],
            'teams' => 'array', 'teams.*.id' => 'required|integer', 'teams.*.membership' => 'required|in:member,supervisor']);
        if (array_key_exists('email', $data)) {
            $user->email = $data['email'] ? strtolower($data['email']) : null;
        }
        if (isset($data['teams'])) {
            $valid = Team::query()->whereIn('id', array_column($data['teams'], 'id'))->pluck('id')->all();
            $user->teams()->sync(collect($data['teams'])->whereIn('id', $valid)->mapWithKeys(fn ($t) => [$t['id'] => ['company_id' => $user->company_id, 'membership' => $t['membership']]])->all());
        }
        if (isset($data['roles'])) {
            $this->requireEmailForConsole($data['roles'], array_key_exists('email', $data) ? $data['email'] : $user->email);
        }
        if (isset($data['roles'])) {
            $this->guardRoleGrant($r->user(), $data['roles']);
            $user->roles()->sync(Role::query()->whereIn('key', $data['roles'])->pluck('id')->mapWithKeys(fn ($rid) => [$rid => ['company_id' => $user->company_id]])->all());
        }
        if (array_key_exists('phone', $data)) {
            $data['phone'] = $data['phone'] ? PhoneNormaliser::normalise($data['phone'], app(CurrentCompany::class)->get()->default_country) : null;
        }
        if (($data['status'] ?? null) === 'active' && $user->status !== 'active') {
            app(\App\Services\Platform\Licensing::class)->assertCanAddUsers();   // re-activating counts against the plan
        }
        $user->fill(array_filter(array_intersect_key($data, array_flip(['name', 'phone', 'status', 'password'])), fn ($v) => $v !== null))->save();
        if (($data['status'] ?? 'active') !== 'active') {
            \App\Models\WorkSession::query()->where('user_id', $user->id)->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'employee_' . $data['status']]);
        }
        Audit::record('employee.updated', $user, array_diff_key($data, ['password' => 1]) + ['password_changed' => isset($data['password'])]);
        return response()->json(['ok' => true]);
    }

    /** Groups with their manager(s). A manager sees only the groups they manage. */
    public function teams(Request $r)
    {
        $scope = $r->user()->supervisedTeamIds();
        return response()->json(Team::query()->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))->withCount('users')
            ->with(['users' => fn ($q) => $q->wherePivot('membership', 'supervisor')->select('users.id', 'users.name', 'users.employee_code')])
            ->orderBy('name')->get()->map(fn (Team $t) => $t->toArray() + ['managers' => $t->users->map->only(['id', 'name', 'employee_code'])->values()])
            ->map(fn ($t) => array_diff_key($t, ['users' => 1])));
    }

    /**
     * Bulk upload of telecallers and managers (SmartDCM-style). Columns: name, group, role (telecaller|manager), employee_code, phone, email.
     * Groups that do not exist yet are created. Missing User IDs, e-mails and passwords are generated. All-or-nothing: any bad row → nothing saved.
     * The generated passwords are returned once (only hashes are stored).
     */
    public function bulkUpload(Request $r, CurrentCompany $current)
    {
        $data = $r->validate(['file' => 'required|file|max:5120', 'email_domain' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9.-]+\\.[a-z]{2,}$/i']]);
        $this->guardRoleGrant($r->user(), ['employee', 'supervisor']);
        $cid = $current->id();
        $domain = strtolower($data['email_domain']);
        $rows = $this->readSheet($data['file']);
        $head = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-'], '_', (string) $h))), array_shift($rows) ?? []);
        $col = fn (string ...$names) => collect($names)->map(fn ($n) => array_search($n, $head, true))->first(fn ($i) => $i !== false);
        $ix = ['name' => $col('name', 'employee_name', 'telecaller_name'), 'group' => $col('group', 'group_name', 'team', 'branch'),
            'role' => $col('role', 'type'), 'code' => $col('employee_code', 'user_id', 'code', 'emp_code'), 'phone' => $col('phone', 'mobile', 'phone_number'), 'email' => $col('email', 'email_id')];
        if ($ix['name'] === null || $ix['group'] === null) {
            throw new DomainException('bad_template', 'The file needs at least the columns "name" and "group". Download the template.');
        }
        $existingCodes = User::query()->pluck('employee_code')->map(fn ($c) => strtolower($c))->flip();
        $existingEmails = User::query()->whereNotNull('email')->pluck('email')->map(fn ($e) => strtolower($e))->flip();
        $people = [];
        $errors = [];
        $seenCodes = [];
        $seenEmails = [];
        foreach ($rows as $n => $row) {
            $v = fn (string $k) => $ix[$k] === null ? '' : trim((string) ($row[$ix[$k]] ?? ''));
            if (implode('', array_map('strval', $row)) === '') {
                continue;                                                    // blank line
            }
            $line = $n + 2;
            $role = strtolower($v('role')) ?: 'telecaller';
            $role = in_array($role, ['manager', 'supervisor', 'team_leader', 'tl'], true) ? 'manager' : ($role === 'telecaller' || $role === 'employee' || $role === 'agent' ? 'telecaller' : $role);
            $p = ['line' => $line, 'name' => mb_substr($v('name'), 0, 150), 'group' => mb_substr($v('group'), 0, 100), 'role' => $role,
                'code' => $v('code'), 'phone' => $v('phone'), 'email' => strtolower($v('email'))];
            $err = [];
            if ($p['name'] === '') { $err[] = 'name is empty'; }
            if ($p['group'] === '') { $err[] = 'group is empty'; }
            if (!in_array($role, ['manager', 'telecaller'], true)) { $err[] = "role must be telecaller or manager (got \"{$role}\")"; }
            if ($p['code'] !== '' && !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $p['code'])) { $err[] = 'employee_code may contain only letters, digits, . _ -'; }
            if ($p['code'] !== '' && (isset($existingCodes[strtolower($p['code'])]) || isset($seenCodes[strtolower($p['code'])]))) { $err[] = "employee_code {$p['code']} already exists"; }
            if ($p['email'] !== '' && !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) { $err[] = 'email is not valid'; }
            if ($p['email'] !== '' && (isset($existingEmails[$p['email']]) || isset($seenEmails[$p['email']]))) { $err[] = "email {$p['email']} already exists"; }
            if ($p['phone'] !== '') {
                try { $p['phone'] = PhoneNormaliser::normalise($p['phone'], $current->get()->default_country); } catch (\Throwable) { $err[] = 'phone is not valid'; }
            }
            $p['code'] !== '' && $seenCodes[strtolower($p['code'])] = true;
            $p['email'] !== '' && $seenEmails[$p['email']] = true;
            $err ? $errors[] = ['line' => $line, 'name' => $p['name'], 'errors' => $err] : $people[] = $p;
        }
        if ($errors || !$people) {
            return response()->json(['error' => 'invalid_rows', 'message' => $people || $errors ? 'Fix the listed rows and upload again. Nothing was saved.' : 'The file has no rows.', 'rows' => $errors], 422);
        }
        if (count($people) > 2000) {
            throw new DomainException('too_many_rows', 'Upload at most 2,000 people at a time.');
        }
        usort($people, fn ($a, $b) => ($a['role'] === 'manager' ? 0 : 1) <=> ($b['role'] === 'manager' ? 0 : 1));   // managers first
        app(\App\Services\Platform\Licensing::class)->assertCanAddUsers(count($people));   // plan user limit
        $created = DB::transaction(function () use ($people, $cid, $domain, &$existingCodes, &$existingEmails) {
            $roleIds = Role::query()->whereIn('key', ['employee', 'supervisor'])->pluck('id', 'key');
            $teams = Team::query()->get()->keyBy(fn ($t) => strtolower($t->name));
            $out = [];
            foreach ($people as $p) {
                $team = $teams[strtolower($p['group'])] ??= Team::query()->create(['name' => $p['group'], 'code' => $this->teamCode($p['group'])]);
                $code = $p['code'] !== '' ? $p['code'] : $this->nextCode($p['role'] === 'manager' ? 'MGR' : 'TC', $existingCodes);
                $existingCodes[strtolower($code)] = true;
                $email = $p['email'] !== '' ? $p['email'] : strtolower(preg_replace('/[^A-Za-z0-9._-]/', '', $code)) . '@' . $domain;
                if (isset($existingEmails[$email]) && $p['email'] === '') {
                    $email = strtolower($code) . '.' . Str::lower(Str::random(4)) . '@' . $domain;
                }
                $existingEmails[$email] = true;
                $password = Str::password(10, true, true, false);
                $u = User::query()->create(['employee_code' => $code, 'name' => $p['name'], 'email' => $email, 'phone' => $p['phone'] ?: null, 'password' => $password, 'status' => 'active']);
                $u->roles()->attach($roleIds[$p['role'] === 'manager' ? 'supervisor' : 'employee'], ['company_id' => $cid]);
                $u->teams()->attach($team->id, ['company_id' => $cid, 'membership' => $p['role'] === 'manager' ? 'supervisor' : 'member']);
                $out[] = ['employee_code' => $code, 'name' => $p['name'], 'email' => $email, 'password' => $password, 'group' => $team->name, 'role' => $p['role'], 'team_id' => $team->id];
            }
            return $out;
        });
        // Manager mapping: every person gets the manager(s) of their group (including managers that existed before this upload).
        $managers = DB::table('team_user')->join('users', 'users.id', '=', 'team_user.user_id')->where('membership', 'supervisor')
            ->whereIn('team_id', array_unique(array_column($created, 'team_id')))->get(['team_id', 'users.name'])->groupBy('team_id')
            ->map(fn ($g) => $g->pluck('name')->implode(', '));
        $created = array_map(fn ($c) => array_diff_key($c + ['manager' => $c['role'] === 'manager' ? '—' : ($managers[$c['team_id']] ?? '')], ['team_id' => 1]), $created);
        Audit::record('employees.bulk_uploaded', null, ['count' => count($created)]);
        return response()->json(['created' => $created, 'groups_without_manager' => array_values(array_unique(array_column(array_filter($created, fn ($c) => $c['manager'] === ''), 'group')))], 201);
    }

    private function teamCode(string $name): string
    {
        $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'GRP', 0, 24));
        $code = $base;
        for ($i = 2; Team::query()->where('code', $code)->exists(); $i++) {
            $code = $base . $i;
        }
        return $code;
    }

    /** Next free User ID like TC0001 / MGR0001 (case-insensitive check against existing codes). */
    private function nextCode(string $prefix, $existing): string
    {
        for ($i = 1; ; $i++) {
            $code = sprintf('%s%04d', $prefix, $i);
            if (!isset($existing[strtolower($code)])) {
                return $code;
            }
        }
    }

    /** @return list<list<string>> first sheet of a .csv / .xlsx */
    private function readSheet(\Illuminate\Http\UploadedFile $f): array
    {
        $ext = strtolower($f->getClientOriginalExtension());
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            throw new DomainException('invalid_upload', 'Upload a .csv or .xlsx file.');
        }
        $reader = $ext === 'xlsx' ? new \OpenSpout\Reader\XLSX\Reader() : new \OpenSpout\Reader\CSV\Reader();
        $reader->open($f->getRealPath());
        $out = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $out[] = array_map(static fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string) $v, $row->toArray());
                    if (count($out) > 2001) {
                        throw new DomainException('too_many_rows', 'Upload at most 2,000 people at a time.');
                    }
                }
                break;
            }
        } finally {
            $reader->close();
        }
        if (isset($out[0][0])) {
            $out[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $out[0][0]);   // Excel CSV byte-order mark
        }
        return $out;
    }

    public function createTeam(Request $r, CurrentCompany $current)
    {
        $data = $r->validate(['code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('teams')->where('company_id', $current->id())],
            'name' => ['required', 'string', 'max:100', Rule::unique('teams')->where('company_id', $current->id())], 'manager_ids' => 'array', 'manager_ids.*' => 'integer']);
        $t = Team::query()->create(['name' => $data['name'], 'code' => $data['code'] ?? $this->teamCode($data['name'])]);
        $managers = User::query()->whereIn('id', $data['manager_ids'] ?? [])->pluck('id');
        $t->users()->attach($managers->mapWithKeys(fn ($id) => [$id => ['company_id' => $t->company_id, 'membership' => 'supervisor']])->all());
        Audit::record('team.created', $t);
        return response()->json(['id' => $t->id], 201);
    }

    public function renameTeam(Request $r, CurrentCompany $current, int $id)
    {
        $team = Team::query()->findOrFail($id);
        $data = $r->validate(['name' => ['required', 'string', 'max:100', Rule::unique('teams')->where('company_id', $current->id())->ignore($team->id)]]);
        $team->forceFill(['name' => trim($data['name'])])->save();
        Audit::record('team.renamed', $team);
        return response()->json(['ok' => true]);
    }

    public function setMembers(Request $r, int $id)
    {
        $team = Team::query()->findOrFail($id);
        $data = $r->validate(['members' => 'array', 'members.*.user_id' => 'required|integer', 'members.*.membership' => 'required|in:member,supervisor']);
        $ids = User::query()->whereIn('id', array_column($data['members'] ?? [], 'user_id'))->pluck('id')->all(); // same-company users only
        $sync = [];
        foreach ($data['members'] ?? [] as $m) {
            if (in_array($m['user_id'], $ids, true)) {
                $sync[$m['user_id']] = ['membership' => $m['membership'], 'company_id' => $team->company_id];
            }
        }
        $team->users()->sync($sync);
        Audit::record('team.members_set', $team, ['count' => count($sync)]);
        return response()->json(['ok' => true]);
    }

    public function roles()
    {
        return response()->json(['roles' => Role::query()->with('permissions:id,key')->withCount('users')->orderByDesc('system')->orderBy('name')->get(['id', 'key', 'name', 'system']),
            'permissions' => collect(\App\Support\Permissions::ALL)->map(fn ($d, $k) => ['key' => $k, 'description' => $d])->values()]);
    }

    /** Custom roles: any mix of permissions (you can grant only permissions you hold). System roles stay fixed. */
    public function saveRole(Request $r, CurrentCompany $current, ?int $id = null)
    {
        $data = $r->validate(['name' => 'required|string|max:100', 'permissions' => 'required|array|min:1', 'permissions.*' => ['string', Rule::in(array_keys(\App\Support\Permissions::ALL))]]);
        $missing = array_diff($data['permissions'], $r->user()->permissionKeys(), ['mobile.work', 'calls.make', 'calls.console']);
        if ($missing) {
            throw new DomainException('forbidden', 'You cannot grant permissions you do not hold: ' . implode(', ', $missing));
        }
        $role = $id ? Role::query()->findOrFail($id) : Role::query()->create(['key' => 'custom_' . Str::lower(Str::random(8)), 'name' => $data['name'], 'system' => false]);
        if ($role->system) {
            throw new DomainException('system_role', 'Built-in roles cannot be changed. Create a custom role instead.');
        }
        $role->forceFill(['name' => $data['name']])->save();
        $role->permissions()->sync(\App\Models\Permission::query()->whereIn('key', $data['permissions'])->pluck('id'));
        Audit::record($id ? 'role.updated' : 'role.created', $role, ['permissions' => $data['permissions']]);
        return response()->json(['id' => $role->id], $id ? 200 : 201);
    }

    public function deleteRole(int $id)
    {
        $role = Role::query()->withCount('users')->findOrFail($id);
        if ($role->system || $role->users_count > 0) {
            throw new DomainException('role_in_use', $role->system ? 'Built-in roles cannot be deleted.' : 'Move its people to another role first.');
        }
        Audit::record('role.deleted', $role);
        $role->delete();
        return response()->json(['ok' => true]);
    }

    /** Console roles sign in with e-mail; phone-only roles (telecaller) do not need one. */
    private function requireEmailForConsole(array $roleKeys, ?string $email): void
    {
        $console = DB::table('roles')->join('permission_role', 'permission_role.role_id', '=', 'roles.id')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('roles.company_id', app(CurrentCompany::class)->id())->whereIn('roles.key', $roleKeys)
            ->whereNotIn('permissions.key', ['mobile.work', 'calls.make', 'chat.use'])->exists();
        if ($console && !$email) {
            throw new DomainException('email_required', 'This role signs in to the admin console — enter an e-mail.');
        }
    }

    /** Prevent privilege escalation: you cannot grant a role holding permissions you do not have. */
    private function guardRoleGrant(User $actor, array $roleKeys): void
    {
        $perms = DB::table('roles')->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('roles.company_id', $actor->company_id)->whereIn('roles.key', $roleKeys)->pluck('permissions.key')->unique();
        $missing = $perms->diff($actor->permissionKeys())->diff(['mobile.work', 'calls.make', 'calls.console']);   // employee-only rights: admins hand them out without holding them
        if ($missing->isNotEmpty()) {
            throw new DomainException('forbidden', 'You cannot grant permissions you do not hold.');
        }
    }
}
