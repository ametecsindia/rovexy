<?php

declare(strict_types=1);

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Management\AmapiClient;
use App\Models\Company;
use App\Models\ManagedDevice;
use App\Support\Audit;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

/**
 * Pub/Sub push for AMAPI STATUS_REPORT / ENROLLMENT notifications. The company is derived from the device's
 * enterprise name — never guessed. Device-level compliance is merged from AMAPI's own report.
 */
final class AmapiNotificationController extends Controller
{
    public function store(Request $r, CurrentCompany $current)
    {
        if (!AmapiClient::verifyPubSubJwt((string) $r->bearerToken())) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        $msg = json_decode((string) base64_decode((string) data_get($r->json()->all(), 'message.data', '')), true) ?: [];
        $name = (string) ($msg['name'] ?? '');
        if (!preg_match('#^(enterprises/[\w-]+)/devices/[\w-]+$#', $name, $m)) {
            return response()->json(['ok' => true]); // ack & ignore
        }
        $company = Company::query()->where('amapi_enterprise', $m[1])->first();
        if ($company === null) {
            return response()->json(['ok' => true]);
        }
        $current->run($company, function () use ($msg, $name) {
            $device = ManagedDevice::query()->where('amapi_device_name', $name)->latest('id')->first();
            if ($device === null) {
                return;
            }
            $detail = (array) $device->compliance_detail;
            $detail['amapi'] = [
                'synced_fingerprint' => data_get($detail, 'amapi.synced_fingerprint'),
                'state' => $msg['state'] ?? null, 'policy_compliant' => $msg['policyCompliant'] ?? null,
                'applied_policy_version' => $msg['appliedPolicyVersion'] ?? null,
                'non_compliance' => array_slice($msg['nonComplianceDetails'] ?? [], 0, 50), 'reported_at' => now()->toAtomString(),
            ];
            $fill = ['compliance_detail' => $detail, 'management_status' => strtolower((string) ($msg['state'] ?? $device->management_status))];
            if (($msg['policyCompliant'] ?? true) === false) {
                $fill['compliant'] = false;    // AMAPI saying non-compliant always wins over the app's own report
                $fill['policy_state'] = 'partially_applied';
            }
            $device->forceFill($fill)->save();
            // Re-evaluate the latest app report for the current policy with Google's verification now available.
            $ack = \App\Models\PolicyAcknowledgement::query()->where('device_id', $device->id)
                ->where('fingerprint', $device->desired_policy_fingerprint)->latest('id')->first();
            if ($ack !== null) {
                app(\App\Services\PolicyService::class)->acknowledge($device, $ack->fingerprint, $ack->results);
            }
            Audit::record('amapi.status_report', $device, ['state' => $msg['state'] ?? null, 'compliant' => $msg['policyCompliant'] ?? null], ['type' => 'system', 'id' => null]);
        });
        return response()->json(['ok' => true]);
    }
}
