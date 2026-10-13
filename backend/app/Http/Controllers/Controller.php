<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ManagedDevice;
use App\Models\WorkSession;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function device(Request $r): ManagedDevice
    {
        return $r->attributes->get('device') ?? abort(401);
    }

    protected function workSession(Request $r): WorkSession
    {
        return $r->attributes->get('work_session') ?? abort(401);
    }
}
