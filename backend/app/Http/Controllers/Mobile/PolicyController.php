<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Domain\Policy\Capability;
use App\Domain\Policy\PolicyCatalog;
use App\Http\Controllers\Controller;
use App\Services\PolicyService;
use Illuminate\Http\Request;

final class PolicyController extends Controller
{
    public function show(Request $r, PolicyService $svc)
    {
        $d = $this->device($r);
        $snap = $svc->currentSnapshot($d);
        $controls = [];
        foreach (PolicyCatalog::all() as $key => $c) {
            $controls[$key] = ['scope' => $c->scope, 'mandatory' => in_array($key, $snap->values['compliance.mandatory'], true)]
                + Capability::evaluate($c, $d->deviceFacts(), $snap->values[$key]);
        }
        return response()->json([
            'fingerprint' => $snap->fingerprint, 'values' => $snap->values, 'controls' => $controls,
            'management_mode' => $d->management_mode, 'last_good_fingerprint' => $d->last_good_policy_fingerprint,
        ]);
    }

    public function ack(Request $r, PolicyService $svc)
    {
        $data = $r->validate(['fingerprint' => 'required|string|size:16', 'results' => 'required|array', 'results.*.status' => 'required|string', 'results.*.verified' => 'boolean', 'results.*.detail' => 'nullable|string|max:200']);
        $ack = $svc->acknowledge($this->device($r), $data['fingerprint'], $data['results']);
        return response()->json(['state' => $ack->state, 'compliant' => $ack->compliant, 'results' => $ack->results]);
    }
}
