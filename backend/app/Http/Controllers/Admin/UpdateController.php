<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Licensing\LicenceClient;
use App\Services\Updates\UpdateClient;
use App\Support\Audit;

/** Licence page → Check for update (on-premises installs only). Four calls: status, check, download, install. */
final class UpdateController extends Controller
{
    public function __construct()
    {
        abort_unless(LicenceClient::isClient(), 404);
    }

    public function show(UpdateClient $u)
    {
        return response()->json($u->status());
    }

    public function check(UpdateClient $u)
    {
        $u->check();
        Audit::record('update.checked', null, []);
        return response()->json($u->status());
    }

    public function download(UpdateClient $u)
    {
        $u->download();
        Audit::record('update.downloaded', null, ['version' => $u->state()['available']['version'] ?? null]);
        return response()->json($u->status());
    }

    public function install(UpdateClient $u)
    {
        $r = $u->install();
        Audit::record('update.install_started', null, ['version' => $r['available']['version'] ?? null]);
        return response()->json($u->status() + ['poll_url' => $r['poll_url'] ?? null]);
    }
}
