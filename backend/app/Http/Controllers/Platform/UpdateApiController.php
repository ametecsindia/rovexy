<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Platform\Licence;
use App\Models\Platform\ProductUpdate;
use App\Services\Platform\PlatformAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Update API for on-premises installs. Pull only: an install asks, we answer. The licence key + the bound server ID are the
 * credential (read-only on the licence: nothing is bound or moved here). Updates need an active, unexpired licence and,
 * for perpetual licences, active AMC. Downloads use a 60-minute token tied to that server — the key never goes in a URL.
 */
final class UpdateApiController extends Controller
{
    public function check(Request $r)
    {
        $d = $r->validate(['key' => 'required|string|max:40', 'fingerprint' => 'required|string|regex:/^[a-f0-9]{40,64}$/',
            'current_version' => ['required', 'regex:/^\d+(\.\d+){0,3}$/'], 'channel' => 'nullable|in:stable,beta']);
        $l = Licence::query()->where('key', strtoupper(trim($d['key'])))->where('deployment', 'onprem')->first();
        $no = fn (string $reason, string $msg) => response()->json(['ok' => false, 'reason' => $reason, 'message' => $msg], 403);
        if (!$l) {
            return $no('unknown_key', 'This licence key is not recognised by the ROVEXY licence server.');
        }
        if ($l->server_fingerprint && !hash_equals($l->server_fingerprint, $d['fingerprint'])) {
            return $no('server_mismatch', 'This licence is registered to another server.');
        }
        if ($l->status !== 'active') {
            return $no('licence_' . $l->status, 'Updates need an active licence. Contact Ametecs.');
        }
        if ($l->expired()) {
            return $no('licence_expired', 'The licence has expired. Renew it (Administration → Plan & licence) to get updates.');
        }
        if ($l->kind === 'perpetual' && (!$l->amc_expires_at || $l->amc_expires_at->isPast())) {
            return $no('amc_expired', 'Your updates & support (AMC) has ended, so new versions are not included. Renew AMC under Administration → Plan & licence.');
        }
        $channel = $d['channel'] ?? 'stable';
        $u = ProductUpdate::latestFor($d['current_version'], $channel);
        if (!$u) {
            $blocked = ProductUpdate::blockedFor($d['current_version'], $channel);
            return response()->json(['ok' => true, 'update_available' => false, 'message' => $blocked
                ? "Version {$blocked->version} needs version {$blocked->min_version} or later first. Contact Ametecs for the intermediate update."
                : 'This server is on the latest published version.']);
        }
        $token = Str::random(48);
        Cache::put("rovexy_update_dl:{$token}", ['update' => $u->id, 'licence' => $l->id, 'fingerprint' => $d['fingerprint']], now()->addHour());
        PlatformAudit::record('update.offered', $l, ['version' => $u->version, 'from' => $d['current_version']], 'licence-server');
        return response()->json(['ok' => true, 'update_available' => true, 'version' => $u->version, 'minimum_version' => $u->min_version,
            'title' => $u->title, 'notes' => $u->notes, 'size_bytes' => $u->size_bytes, 'package_hash' => $u->sha256, 'signature' => $u->signature,
            'download_url' => url("/api/v1/updates/download/{$token}"), 'token_expires_in' => 3600, 'released_at' => $u->released_at?->toAtomString()]);
    }

    public function download(Request $r, string $token)
    {
        $t = Cache::get("rovexy_update_dl:{$token}");
        if (!$t) {
            return response()->json(['ok' => false, 'reason' => 'token_expired', 'message' => 'The download link has expired. Check for updates again.'], 410);
        }
        if (!hash_equals((string) $t['fingerprint'], (string) $r->header('X-Rovexy-Server'))) {
            return response()->json(['ok' => false, 'reason' => 'wrong_server', 'message' => 'This download link belongs to another server.'], 403);
        }
        $u = ProductUpdate::query()->find($t['update']);
        if (!$u || !$u->is_published || !is_file($u->path())) {
            return response()->json(['ok' => false, 'reason' => 'gone', 'message' => 'This update is no longer available.'], 404);
        }
        $u->increment('downloads');
        PlatformAudit::record('update.downloaded', Licence::query()->find($t['licence']), ['version' => $u->version], 'licence-server');
        return response()->download($u->path(), $u->filename, ['Content-Type' => 'application/zip', 'X-Rovexy-Version' => $u->version, 'X-Rovexy-Sha256' => $u->sha256]);
    }
}
