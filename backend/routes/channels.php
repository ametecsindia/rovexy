<?php

use App\Models\MediaSession;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Broadcast;

/*
| Channel names embed the company ID. Each authoriser first checks it equals the request's resolved company,
| then the principal. Mobile principals are only ever authorised for their own device channel.
*/
$sameCompany = fn (int $cid) => app(CurrentCompany::class)->id() === $cid;

Broadcast::channel('company.{cid}.admin', function (User $user, int $cid) use ($sameCompany) {
    return $sameCompany($cid) && request()->attributes->get('work_session') === null && $user->company_id === $cid && $user->isAdminUser();
});

Broadcast::channel('company.{cid}.device.{did}', function (User $user, int $cid, int $did) use ($sameCompany) {
    $device = request()->attributes->get('device');
    return $sameCompany($cid) && $device !== null && $device->id === $did && $device->company_id === $cid && $device->assigned_user_id === $user->id;
});

Broadcast::channel('company.{cid}.conversation.{id}', function (User $user, int $cid, int $id) use ($sameCompany) {
    return $sameCompany($cid) && \App\Models\Conversation::query()->whereKey($id)->first()?->hasParticipant($user->id) === true;
});

Broadcast::channel('company.{cid}.media.{uuid}', function (User $user, int $cid, string $uuid) use ($sameCompany) {
    $s = MediaSession::query()->where('uuid', $uuid)->first();
    return $sameCompany($cid) && $s !== null && in_array($user->id, [$s->requested_by, $s->user_id], true) && in_array($s->state, ['accepted', 'active'], true);
});
