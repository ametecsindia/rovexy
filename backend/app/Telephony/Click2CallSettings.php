<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Models\CallingAccess;
use App\Models\Company;
use App\Models\Smart31Number;
use App\Models\User;
use App\Services\Platform\Licensing;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Calling Configuration (admin console), three layers — a call may use a feature only if all three say yes:
 *  1. Plan (LC): the licence's plan includes the feature (Licensing::FEATURES 'click2call' / 'smart31'; Super Admin plans).
 *  2. Company: Click2Call mode off | only | choice (companies.settings.click2call, secret encrypted); Smart31 on/off.
 *  3. People: per feature 'everyone' or 'selected' (companies.settings.calling_access); selected = calling_access rows
 *     naming a group (team) or a person.
 * Smart31: Click2Call calls of allowed people go out from the company's Smart31 pool, round-robin (least recently used
 * number first) among numbers open to the caller's groups; otherwise from the Click2Call company number (VMN).
 * Click2Call not set up in the console (mode null) = server .env decides, as before.
 */
final class Click2CallSettings
{
    public const MODES = ['off', 'only', 'choice'];
    public const FEATURES = ['sim', 'click2call', 'smart31'];

    private static function company(): ?Company
    {
        $ctx = app(CurrentCompany::class);
        return $ctx->bound() ? $ctx->get() : null;
    }

    public static function stored(?Company $c = null): array
    {
        $c ??= self::company();
        return (array) ($c?->setting('click2call') ?? []);
    }

    public static function mode(?Company $c = null): ?string
    {
        $m = self::stored($c)['mode'] ?? null;
        return in_array($m, self::MODES, true) ? $m : null;
    }

    public static function inPlan(string $feature): bool
    {
        return app(Licensing::class)->has($feature === 'sim' ? 'calling' : $feature);   // SIM calling = the plan's calling feature
    }

    /** SIM calling switched on for the company (default on). */
    public static function simOn(?Company $c = null): bool
    {
        return (($c ?? self::company())?->setting('sim.enabled')) !== false;
    }

    /** The phone the provider rings first: Click2Call agent number, else the employee's work phone (kit: click_agent_number, else mobile). */
    public static function agentNumber(User $u): ?string
    {
        $n = Click2CallAdapter::normalize($u->click_agent_number ?: $u->phone);
        return $n !== '' ? $n : null;
    }

    /**
     * Company numbers this person may call from (kit VmnService::listFor): voucher numbers flagged Click2Call whose
     * groups include one of the person's groups. With no voucher numbers at all, the company number (Click2Call page, else .env).
     * @return list<array{number:string, label:string, available:bool, expires:?string}>
     */
    public static function numbersFor(User $u): array
    {
        $teams = $u->teams()->pluck('teams.id')->map(fn ($i) => (int) $i)->all();
        $all = \App\Models\C2c\Vmn::query()->where('click2call', true)->orderBy('virtual_number')->get();
        if ($all->isEmpty()) {
            $did = Click2CallAdapter::normalize((self::stored()['did'] ?? null) ?: config('ammp.telephony.click2call.did'));   // console, else .env C2C_DID
            return $did !== '' ? [['number' => $did, 'label' => '+91' . $did, 'available' => true, 'expires' => null]] : [];
        }
        return $all->filter(fn ($v) => array_intersect(array_map('intval', $v->team_ids ?? []), $teams))
            ->map(fn ($v) => ['number' => $v->virtual_number, 'label' => '+91' . $v->virtual_number . ($v->isAvailable() ? '' : ' (expired)'),
                'available' => $v->isAvailable(), 'expires' => $v->expires_at?->toDateString()])->values()->all();
    }

    /**
     * The company number for this Click2Call call (kit VmnService::check + LeadGER order): Smart31 rotation when it
     * applies; else the number the employee picked, else their default, else their first usable number.
     */
    public static function callerNumberFor(User $u, ?string $requested): string
    {
        if ($n = self::nextSmart31($u)) {
            return $n;
        }
        $mine = collect(self::numbersFor($u));
        if ($mine->isEmpty()) {
            throw new \App\Domain\DomainException('c2c_no_number', 'No Click2Call number is assigned to your group. Ask your administrator (Calling configuration → Numbers & vouchers).');
        }
        $want = Click2CallAdapter::normalize($requested);
        if ($want !== '') {
            $row = $mine->firstWhere('number', $want);
            if (!$row) {
                throw new \App\Domain\DomainException('c2c_number_not_yours', "+91{$want} is not configured for Click2Call for your account. Choose a number from your list.");
            }
            if (!$row['available']) {
                throw new \App\Domain\DomainException('c2c_number_expired', "Click2Call number +91{$want} expired on {$row['expires']}. Choose another number.");
            }
            return $want;
        }
        $default = Click2CallAdapter::normalize($u->c2c_default_number);
        $row = ($default !== '' ? $mine->where('available', true)->firstWhere('number', $default) : null) ?? $mine->firstWhere('available', true);
        if (!$row) {
            throw new \App\Domain\DomainException('c2c_number_expired', 'Your Click2Call numbers have expired. Ask your administrator to add a new voucher.');
        }
        return $row['number'];
    }

    /** Click2Call attempts per customer number per day (kit CallingRules, LeadGER default 100). Counts atomically. */
    public static function reserveAttempt(User $u, string $customer): void
    {
        $limit = max(1, (int) (self::stored()['daily_limit'] ?? config('ammp.telephony.click2call.daily_limit')));
        $phone = Click2CallAdapter::normalize($customer);
        $cid = app(CurrentCompany::class)->id();
        $date = now(app(CurrentCompany::class)->get()->timezone ?: 'Asia/Kolkata')->toDateString();
        $ok = DB::transaction(function () use ($cid, $phone, $date, $limit) {
            DB::table('c2c_daily_attempts')->insertOrIgnore(['company_id' => $cid, 'phone' => $phone, 'call_date' => $date, 'attempt_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('c2c_daily_attempts')->where(['company_id' => $cid, 'phone' => $phone, 'call_date' => $date])->lockForUpdate()->first();
            if ((int) $row->attempt_count >= $limit) {
                return false;
            }
            DB::table('c2c_daily_attempts')->where('id', $row->id)->increment('attempt_count');
            return true;
        });
        if (!$ok) {
            \App\Support\Audit::record('call.blocked', $u, ['reason' => 'retry_limit', 'limit' => $limit, 'phone_last4' => substr($phone, -4)]);
            throw new \App\Domain\DomainException('retry_limit', "Maximum call attempts reached for this number today ({$limit}).");
        }
    }

    /** Give the attempt back when the provider could not start the call (as LeadGER). */
    public static function releaseAttempt(string $customer): void
    {
        DB::table('c2c_daily_attempts')->where('company_id', app(CurrentCompany::class)->id())->where('phone', Click2CallAdapter::normalize($customer))
            ->where('call_date', now(app(CurrentCompany::class)->get()->timezone ?: 'Asia/Kolkata')->toDateString())->where('attempt_count', '>', 0)->decrement('attempt_count');
    }

    /** Licensed Click2Call users (C2C_MAX_USERS, 0 = no limit) and how many active people are allowed now. */
    public static function userLimit(): array
    {
        $users = User::query()->where('status', 'active')->get()->filter(fn (User $u) => $u->hasPermission('calls.make'));   // people who place calls
        return ['limit' => (int) config('ammp.telephony.click2call.max_users'), 'in_use' => $users->filter(fn ($u) => self::allows($u, 'click2call'))->count()];
    }

    /** Adapter config when the company has Click2Call on; null = use the server's TELEPHONY_DRIVER. */
    public static function effective(?Company $c = null): ?array
    {
        $s = self::stored($c);
        if (!in_array($s['mode'] ?? null, ['only', 'choice'], true)) {
            return null;
        }
        $secret = null;
        if (!empty($s['secret'])) {
            try {
                $secret = Crypt::decryptString($s['secret']);
            } catch (\Throwable) {
                $secret = null;   // APP_KEY changed: the admin re-enters it
            }
        }
        // With voucher numbers the company number field may be blank: the first voucher number stands in for health checks.
        $did = ($s['did'] ?? null) ?: \App\Models\C2c\Vmn::query()->where('click2call', true)->orderBy('virtual_number')->value('virtual_number');
        return ['did' => $did, 'agent_caller_id' => $s['agent_caller_id'] ?? null, 'gateway_secret' => $secret,
            'crm_id' => ($s['crm_id'] ?? null) ?: config('ammp.telephony.click2call.crm_id')] + config('ammp.telephony.click2call');
    }

    /** Is Click2Call the server route right now (company setting, or the .env driver)? */
    public static function active(?Company $c = null): bool
    {
        $mode = self::mode($c);
        return $mode === null ? config('ammp.telephony.driver') === 'click2call' : $mode !== 'off';
    }

    /** 'everyone' | 'selected' for a feature. */
    public static function accessMode(string $feature, ?Company $c = null): string
    {
        $c ??= self::company();
        return ($c?->setting("calling_access.$feature")) === 'selected' ? 'selected' : 'everyone';
    }

    /** May this person use the feature? Plan + people layers (the company on/off layer is checked by the caller). */
    public static function allows(User $u, string $feature): bool
    {
        if (!self::inPlan($feature)) {
            return false;
        }
        if (self::accessMode($feature) === 'everyone') {
            return true;
        }
        $teams = $u->teams()->pluck('teams.id');
        return CallingAccess::query()->where('feature', $feature)
            ->where(fn ($q) => $q->where('user_id', $u->id)->orWhereIn('team_id', $teams))->exists();
    }

    public static function smart31On(?Company $c = null): bool
    {
        return (bool) (($c ?? self::company())?->setting('smart31.enabled')) && self::inPlan('smart31');
    }

    /**
     * Next Smart31 number for this person (round-robin over the pool open to their groups), marked used in the same
     * transaction so two calls never take the same "next" number. Null = not allowed / pool empty → company VMN.
     */
    public static function nextSmart31(User $u): ?string
    {
        if (!self::smart31On() || !self::allows($u, 'smart31')) {
            return null;
        }
        $teams = $u->teams()->pluck('teams.id')->map(fn ($i) => (int) $i)->all();
        return DB::transaction(function () use ($teams) {
            $n = Smart31Number::query()->where('active', true)
                ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', now()->toDateString()))
                ->orderByRaw('last_used_at IS NOT NULL')->orderBy('last_used_at')->orderBy('id')->lockForUpdate()->get()
                // ponytail: group filter in PHP (team_ids is JSON; pools are tens of numbers). Move to a pivot if pools grow to thousands.
                ->first(fn ($n) => empty($n->team_ids) || array_intersect(array_map('intval', $n->team_ids), $teams));
            if (!$n) {
                return null;
            }
            $n->forceFill(['last_used_at' => now(), 'calls' => $n->calls + 1])->save();
            return $n->number;
        });
    }

    /** Calling routes offered to this person (default first). */
    public static function routes(?User $u = null): array
    {
        $routes = match (self::mode()) {
            'only' => ['server'],
            'choice' => ['sim', 'server'],
            'off' => config('ammp.telephony.driver') === 'click2call' ? ['sim'] : self::envRoutes(),
            default => self::envRoutes(),
        };
        if (!self::simOn()) {
            $routes = array_values(array_diff($routes, ['sim']));
        }
        if ($u) {
            if (self::active() && !(self::allows($u, 'click2call') && self::agentNumber($u))) {
                $routes = array_values(array_diff($routes, ['server']));   // not allowed, or no phone to ring
            }
            if (!self::allows($u, 'sim')) {
                $routes = array_values(array_diff($routes, ['sim']));
            }
        }
        return $routes;   // may be empty: this person has no calling option (the app says so)
    }

    private static function envRoutes(): array
    {
        return config('ammp.telephony.routes') ?: [config('ammp.telephony.route') === 'sim' ? 'sim' : 'server'];
    }
}
