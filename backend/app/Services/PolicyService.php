<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\DomainException;
use App\Domain\Policy\Capability;
use App\Domain\Policy\ComplianceEvaluator;
use App\Domain\Policy\PolicyCatalog;
use App\Domain\Policy\PolicyConflictDetector;
use App\Domain\Policy\PolicyResolver;
use App\Jobs\SyncAmapiPolicy;
use App\Models\DevicePolicySnapshot;
use App\Models\ManagedDevice;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyAssignment;
use App\Models\PolicyProfile;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

final class PolicyService
{
    public const STANDARD = 'ROVEXY Standard';

    public function __construct(private readonly PushService $push)
    {
    }

    /** Every company has the recommended profile, published and ready to assign (created on first use). */
    public function ensureStandard(User $actor): PolicyProfile
    {
        $p = PolicyProfile::query()->firstOrCreate(['name' => self::STANDARD],
            ['description' => 'Recommended. Calling, optional call recording, work-time location, live screen and camera sessions (always with the employee\'s consent).']);
        if (!$p->versions()->exists()) {   // new, or an earlier attempt stopped half-way
            $this->publish($this->draft($p, PolicyCatalog::standard(), $actor, 'Recommended defaults'), $actor, true);
        }
        return $p;
    }

    /** @return array{company: array, team: array, device: array, refs: array} published override layers */
    public function layersFor(ManagedDevice $device, ?array $candidate = null): array
    {
        $layer = function (string $type, int $id) use ($candidate): array {
            $a = PolicyAssignment::query()->where('target_type', $type)->where('target_id', $id)->first();
            if ($a === null) {
                return [[], null];
            }
            if ($candidate !== null && $candidate['profile_id'] === $a->profile_id) {
                return [$candidate['settings'], ['profile_id' => $a->profile_id, 'version_id' => 'candidate']];
            }
            $v = PolicyVersion::query()->where('profile_id', $a->profile_id)->where('status', 'published')->orderByDesc('version')->first();
            return $v ? [$v->settings, ['profile_id' => $a->profile_id, 'version_id' => $v->id, 'version' => $v->version]] : [[], null];
        };
        [$c, $cr] = $layer('company', 0);
        [$t, $tr] = $device->team_id ? $layer('team', $device->team_id) : [[], null];
        [$d, $dr] = $layer('device', $device->id);
        return ['company' => $c, 'team' => $t, 'device' => $d, 'refs' => array_filter(['company' => $cr, 'team' => $tr, 'device' => $dr])];
    }

    /** Recomputes the device's effective policy, persists a snapshot if it changed, and notifies the device. */
    public function refreshDevice(ManagedDevice $device): DevicePolicySnapshot
    {
        $layers = $this->layersFor($device);
        $eff = PolicyResolver::effective($layers['company'], $layers['team'], $layers['device']);
        $fp = PolicyResolver::fingerprint($eff['values']);
        $snap = DevicePolicySnapshot::query()->firstOrCreate(
            ['device_id' => $device->id, 'fingerprint' => $fp],
            ['values' => $eff['values'], 'sources' => $eff['sources'], 'version_refs' => $layers['refs']],
        );
        if ($device->desired_policy_fingerprint !== $fp) {
            $detail = (array) $device->compliance_detail;
            $detail['offline_access_minutes'] = $eff['values']['offline.access_minutes'];
            $device->forceFill([
                'desired_policy_fingerprint' => $fp,
                'policy_state' => 'pending',
                'compliant' => false,
                'compliance_detail' => $detail,
                'capabilities' => array_merge((array) $device->capabilities, ['matrix' => Capability::matrix($eff['values'], $device->deviceFacts())]),
            ])->save();
            if ($device->management_mode === 'amapi' && $device->amapi_device_name) {
                SyncAmapiPolicy::dispatch($device->company_id, $device->id);
            }
            $this->push->ping($device, 'policy');
        }
        return $snap;
    }

    public function currentSnapshot(ManagedDevice $device): DevicePolicySnapshot
    {
        return DevicePolicySnapshot::query()->where('device_id', $device->id)->where('fingerprint', $device->desired_policy_fingerprint)->first()
            ?? $this->refreshDevice($device);
    }

    /**
     * Records a device's apply results. Device-level controls are only accepted as applied if the device is actually
     * managed (AMAPI) and the capability is supported — the app cannot self-certify enforcement it has no power over.
     */
    public function acknowledge(ManagedDevice $device, string $fingerprint, array $results): PolicyAcknowledgement
    {
        if ($fingerprint !== $device->desired_policy_fingerprint) {
            throw new DomainException('stale_policy', 'A newer policy is available. Download it and apply again.');
        }
        $snap = $this->currentSnapshot($device);
        $clean = [];
        foreach (PolicyCatalog::all() as $key => $control) {
            $r = $results[$key] ?? ['status' => 'pending'];
            $status = in_array($r['status'] ?? null, ['applied', 'failed', 'unsupported', 'requires_setup', 'pending'], true) ? $r['status'] : 'failed';
            $cap = Capability::evaluate($control, $device->deviceFacts(), $snap->values[$key] ?? null);
            if ($status === 'applied' && $cap['status'] !== Capability::SUPPORTED) {
                $status = $cap['status'];
            }
            $verified = $status === 'applied' && (bool) ($r['verified'] ?? false);
            // Device-level controls the app cannot observe are verified by Android Management's own compliance report
            // for the CURRENT policy (never by the app's claim alone).
            if ($control->scope === 'device' && $status === 'applied' && !$verified && $this->amapiConfirms($device)) {
                $verified = true;
            }
            if ($control->scope === 'device' && data_get($device->compliance_detail, 'amapi.policy_compliant') === false) {
                $verified = false;   // Google's non-compliance report always wins
            }
            $clean[$key] = ['status' => $status, 'verified' => $verified, 'detail' => mb_substr((string) ($r['detail'] ?? $cap['reason'] ?? ''), 0, 200)];
        }
        $verdict = ComplianceEvaluator::evaluate($snap->values, $clean);
        $ack = PolicyAcknowledgement::query()->create([
            'device_id' => $device->id, 'fingerprint' => $fingerprint, 'source' => 'app', 'results' => $clean,
            'state' => $verdict['state'], 'compliant' => $verdict['compliant'], 'reported_at' => now(),
        ]);
        $detail = (array) $device->compliance_detail;
        $detail['failing_mandatory'] = $verdict['failing_mandatory'];
        $detail['failing_optional'] = $verdict['failing_optional'];
        $device->forceFill([
            'applied_policy_fingerprint' => $fingerprint,
            'policy_state' => $verdict['state'],
            'compliant' => $verdict['compliant'],
            'compliance_detail' => $detail,
            'last_good_policy_fingerprint' => $verdict['compliant'] ? $fingerprint : $device->last_good_policy_fingerprint,
        ])->save();
        return $ack;
    }

    private function amapiConfirms(ManagedDevice $device): bool
    {
        $a = data_get($device->compliance_detail, 'amapi');
        return $device->management_mode === 'amapi'
            && ($a['policy_compliant'] ?? null) === true
            && ($a['non_compliance'] ?? []) === []
            && isset($a['reported_at']) && now()->diffInMinutes($a['reported_at'], true) <= 60
            && ($a['synced_fingerprint'] ?? null) === $device->desired_policy_fingerprint;
    }

    public function draft(PolicyProfile $profile, array $settings, User $actor, ?string $notes = null): PolicyVersion
    {
        $clean = [];
        foreach ($settings as $k => $v) {
            $clean[$k] = PolicyCatalog::validate((string) $k, $v);
        }
        $next = (int) PolicyVersion::query()->where('profile_id', $profile->id)->max('version') + 1;
        $v = PolicyVersion::query()->create([
            'profile_id' => $profile->id, 'version' => $next, 'settings' => $clean, 'status' => 'draft', 'notes' => $notes, 'created_by' => $actor->id,
            'conflicts' => $this->impact($profile, $clean)['conflicts'],
        ]);
        Audit::record('policy.drafted', $v, ['profile_id' => $profile->id, 'version' => $next]);
        return $v;
    }

    /** Conflicts across every device the profile would affect (or the company baseline if none). */
    public function impact(PolicyProfile $profile, array $settings): array
    {
        $pkg = (string) config('ammp.employee_app_package');
        $assignments = PolicyAssignment::query()->where('profile_id', $profile->id)->get();
        $devices = collect();
        foreach ($assignments as $a) {
            $q = ManagedDevice::query()->where('approval_state', 'approved');
            $devices = $devices->merge(match ($a->target_type) {
                'company' => $q->get(),
                'team' => $q->where('team_id', $a->target_id)->get(),
                'device' => $q->whereKey($a->target_id)->get(),
            });
        }
        $conflicts = [];
        if ($devices->isEmpty()) {
            foreach (PolicyConflictDetector::detect(PolicyResolver::effective($settings)['values'], $pkg) as $c) {
                $conflicts[$c['code']] = $c + ['devices' => 0];
            }
        }
        foreach ($devices->unique('id') as $device) {
            $l = $this->layersFor($device, ['profile_id' => $profile->id, 'settings' => $settings]);
            foreach (PolicyConflictDetector::detect(PolicyResolver::effective($l['company'], $l['team'], $l['device'])['values'], $pkg) as $c) {
                $conflicts[$c['code']] ??= $c + ['devices' => 0];
                $conflicts[$c['code']]['devices']++;
            }
        }
        return ['devices' => $devices->unique('id')->count(), 'conflicts' => array_values($conflicts)];
    }

    public function publish(PolicyVersion $version, User $actor, bool $acceptWarnings): PolicyVersion
    {
        if ($version->status !== 'draft') {
            throw new DomainException('not_draft', 'Only drafts can be published.');
        }
        $profile = PolicyProfile::query()->findOrFail($version->profile_id);
        $impact = $this->impact($profile, $version->settings);
        if (PolicyConflictDetector::hasErrors($impact['conflicts'])) {
            $version->forceFill(['conflicts' => $impact['conflicts']])->save();
            throw new DomainException('policy_conflicts', 'Resolve policy conflicts before publishing.');
        }
        if ($impact['conflicts'] !== [] && !$acceptWarnings) {
            throw new DomainException('policy_warnings', 'Review and accept the warnings to publish.');
        }
        DB::transaction(function () use ($version, $actor, $impact) {
            PolicyVersion::query()->where('profile_id', $version->profile_id)->where('status', 'published')->update(['status' => 'superseded']);
            $version->forceFill(['status' => 'published', 'published_by' => $actor->id, 'published_at' => now(), 'conflicts' => $impact['conflicts']])->save();
            Audit::record('policy.published', $version, ['profile_id' => $version->profile_id, 'version' => $version->version, 'devices' => $impact['devices'], 'warnings' => count($impact['conflicts'])]);
        });
        $this->refreshAffected($profile);
        return $version;
    }

    /** Controlled rollback: re-publishes the previous good settings as a NEW version (history is never rewritten). */
    public function rollback(PolicyProfile $profile, User $actor): PolicyVersion
    {
        $prev = PolicyVersion::query()->where('profile_id', $profile->id)->where('status', 'superseded')->orderByDesc('version')->first()
            ?? throw new DomainException('nothing_to_roll_back');
        $v = $this->draft($profile, $prev->settings, $actor, "Rollback to v{$prev->version}");
        $v->forceFill(['rolled_back_from_version_id' => $prev->id])->save();
        Audit::record('policy.rollback', $v, ['to_version' => $prev->version]);
        return $this->publish($v, $actor, true);
    }

    public function refreshAffected(PolicyProfile $profile): void
    {
        foreach (PolicyAssignment::query()->where('profile_id', $profile->id)->get() as $a) {
            $q = ManagedDevice::query()->where('approval_state', 'approved');
            $q = match ($a->target_type) {
                'company' => $q,
                'team' => $q->where('team_id', $a->target_id),
                'device' => $q->whereKey($a->target_id),
            };
            $q->each(fn (ManagedDevice $d) => $this->refreshDevice($d));
        }
    }
}
