<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use BelongsToCompany;

    protected $fillable = ['employee_code', 'email', 'name', 'phone', 'password', 'status', 'totp_secret', 'totp_last_step', 'mfa_enabled_at', 'last_login_at', 'click_agent_number', 'c2c_default_number'];

    protected $hidden = ['password', 'remember_token', 'totp_secret', 'totp_last_step'];

    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'totp_secret' => 'encrypted',
            'mfa_enabled_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('company_id');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('membership', 'company_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        return $this->permissionCache ??= DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('role_user.user_id', $this->id)
            ->where('roles.company_id', $this->company_id)   // a role from another company never applies
            ->distinct()->pluck('permissions.key')->all();
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permissionKeys(), true);
    }

    public function isAdminUser(): bool
    {
        // Any role with a console permission (system or custom) may sign in to the admin console.
        return array_diff($this->permissionKeys(), ['mobile.work', 'calls.make', 'chat.use']) !== [];
    }

    /**
     * Team IDs this user may supervise. Company-wide roles (company_admin / it_admin / auditor) see all teams;
     * supervisors see only teams where they are a supervisor member.
     * @return list<int>|null null = unrestricted within the company
     */
    public function supervisedTeamIds(): ?array
    {
        if ($this->hasPermission('scope.all_teams')) {
            return null;
        }
        return $this->teams()->wherePivot('membership', 'supervisor')->pluck('teams.id')->map(fn ($i) => (int) $i)->all();
    }

    public function canSeeUser(User $other): bool
    {
        if ($other->company_id !== $this->company_id) {
            return false;
        }
        $teams = $this->supervisedTeamIds();
        if ($teams === null || $other->id === $this->id) {
            return true;
        }
        return $other->teams()->whereIn('teams.id', $teams)->exists();
    }
}
