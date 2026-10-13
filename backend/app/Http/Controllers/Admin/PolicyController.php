<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\DomainException;
use App\Domain\Policy\Capability;
use App\Domain\Policy\PolicyCatalog;
use App\Domain\Policy\PolicyResolver;
use App\Http\Controllers\Controller;
use App\Models\ManagedDevice;
use App\Models\PolicyAssignment;
use App\Models\PolicyProfile;
use App\Models\PolicyVersion;
use App\Models\Team;
use App\Services\PolicyService;
use App\Support\Audit;
use Illuminate\Http\Request;

final class PolicyController extends Controller
{
    public function catalog()
    {
        return response()->json(array_values(array_map(fn ($c) => [
            'key' => $c->key, 'type' => $c->type, 'default' => $c->default, 'scope' => $c->scope, 'label' => $c->label,
            'enum' => $c->enum, 'min' => $c->min, 'max' => $c->max, 'min_sdk' => $c->minSdk, 'setup_note' => $c->setupNote,
        ], PolicyCatalog::all())));
    }

    public function index()
    {
        return response()->json(PolicyProfile::query()->with(['versions' => fn ($q) => $q->orderByDesc('version')->limit(10)])->get()->map(fn ($p) => $p->toArray() + [
            'assignments' => PolicyAssignment::query()->where('profile_id', $p->id)->get(['target_type', 'target_id']),
        ]));
    }

    public function createProfile(Request $r)
    {
        $p = PolicyProfile::query()->create($r->validate(['name' => 'required|string|max:100', 'description' => 'nullable|string|max:255']));
        Audit::record('policy.profile_created', $p);
        return response()->json(['id' => $p->id], 201);
    }

    public function draft(Request $r, int $id, PolicyService $svc)
    {
        $data = $r->validate(['settings' => 'present|array', 'notes' => 'nullable|string|max:255']);
        $v = $svc->draft(PolicyProfile::query()->findOrFail($id), $data['settings'], $r->user(), $data['notes'] ?? null);
        return response()->json($v, 201);
    }

    public function publish(Request $r, int $id, int $versionId, PolicyService $svc)
    {
        $v = PolicyVersion::query()->where('profile_id', $id)->findOrFail($versionId);
        return response()->json($svc->publish($v, $r->user(), $r->boolean('accept_warnings')));
    }

    public function rollback(Request $r, int $id, PolicyService $svc)
    {
        return response()->json($svc->rollback(PolicyProfile::query()->findOrFail($id), $r->user()));
    }

    public function assign(Request $r, PolicyService $svc)
    {
        $data = $r->validate(['profile_id' => 'nullable|integer', 'target_type' => 'required|in:company,team,device', 'target_id' => 'nullable|integer']);
        $tid = $data['target_type'] === 'company' ? 0 : (int) ($data['target_id'] ?? 0);
        match ($data['target_type']) {
            'team' => Team::query()->findOrFail($tid),
            'device' => ManagedDevice::query()->findOrFail($tid),
            default => null,
        };
        if (($data['profile_id'] ?? null) === null) {
            PolicyAssignment::query()->where('target_type', $data['target_type'])->where('target_id', $tid)->delete();
        } else {
            $profile = PolicyProfile::query()->findOrFail($data['profile_id']);
            if ($profile->published() === null) {
                throw new DomainException('profile_unpublished', 'Publish the profile before assigning it.');
            }
            PolicyAssignment::query()->updateOrCreate(['target_type' => $data['target_type'], 'target_id' => $tid], ['profile_id' => $profile->id]);
        }
        Audit::record('policy.assigned', null, $data);
        $q = ManagedDevice::query()->where('approval_state', 'approved');
        $q = match ($data['target_type']) {
            'company' => $q,
            'team' => $q->where('team_id', $tid),
            'device' => $q->whereKey($tid),
        };
        $q->each(fn ($d) => $svc->refreshDevice($d));
        return response()->json(['ok' => true]);
    }

    public function effective(Request $r, int $id, PolicyService $svc)
    {
        $d = DeviceController::scopedDevice($r, $id);
        $l = $svc->layersFor($d);
        $eff = PolicyResolver::effective($l['company'], $l['team'], $l['device']);
        return response()->json([
            'values' => $eff['values'], 'sources' => $eff['sources'], 'refs' => $l['refs'],
            'capabilities' => Capability::matrix($eff['values'], $d->deviceFacts()),
            'state' => $d->policy_state, 'compliant' => $d->compliant, 'detail' => $d->compliance_detail,
            'desired' => $d->desired_policy_fingerprint, 'applied' => $d->applied_policy_fingerprint, 'last_good' => $d->last_good_policy_fingerprint,
        ]);
    }
}
