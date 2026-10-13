<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DispositionCode;
use App\Services\CaseService;
use Illuminate\Http\Request;

/**
 * The employee's assigned cases. `visible_ids` is authoritative: the app deletes any cached customer not in it
 * (withdrawn/reassigned). Customer data stays in the app's encrypted store, never device contacts.
 * Every endpoint here is limited to Customer::visibleTo(the signed-in employee).
 */
final class CustomerController extends Controller
{
    private const LIST = ['id', 'customer_ref', 'name', 'phone', 'alt_phone', 'campaign', 'status', 'notes', 'follow_up_on', 'updated_at',
        'last_import_id', 'assigned_user_id', 'assigned_team_id', 'city', 'area', 'pincode', 'address', 'state', 'lat', 'lng',
        'outstanding', 'overdue_amount', 'emi_amount', 'due_date', 'oldest_due_date', 'balance_as_of'];

    public function index(Request $r, CaseService $cases)
    {
        $since = $r->query('since') ? new \DateTimeImmutable((string) $r->query('since')) : null;
        // Taken BEFORE the query: a case saved while this request runs is sent again next time instead of being missed
        // for good. `>=` because updated_at has 1 s resolution; a re-sent case just replaces itself in the phone's cache.
        $serverTime = now()->toAtomString();
        $base = Customer::query()->visibleTo($r->user());
        $changed = (clone $base)->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            // No cap: a cap silently dropped cases past it for good (server_time moves on). ponytail: one response
            // for the whole delta; page it if an employee ever holds tens of thousands of cases.
            ->orderBy('id')->get(self::LIST);
        $act = $cases->activity($changed->pluck('id'));
        $portfolios = \App\Models\ImportJob::query()->whereIn('id', $changed->pluck('last_import_id')->filter()->unique())->get(['id', 'portfolio', 'original_name'])
            ->mapWithKeys(fn ($j) => [$j->id => $j->portfolio ?: pathinfo($j->original_name, PATHINFO_FILENAME)]);
        $teams = \App\Models\Team::query()->pluck('name', 'id');
        $items = $changed->map(function (Customer $c) use ($act, $portfolios, $teams, $cases) {
            $d = $cases->dues($c, 0.0);   // the list shows dues as uploaded; the profile also subtracts verified payments
            return [
                'id' => $c->id, 'customer_ref' => $c->customer_ref, 'name' => $c->name, 'phone' => $c->phone, 'alt_phone' => $c->alt_phone,
                'campaign' => $c->campaign, 'status' => $c->status, 'notes' => $c->notes, 'follow_up_on' => $c->follow_up_on?->format('Y-m-d'),
                'updated_at' => $c->updated_at?->toAtomString(), 'portfolio' => $c->last_import_id ? ($portfolios[$c->last_import_id] ?? null) : null,
                'team' => $c->assigned_team_id ? ($teams[$c->assigned_team_id] ?? null) : null, 'pool' => $c->assigned_user_id === null,
                'city' => $c->city, 'area' => $c->area, 'pincode' => $c->pincode, 'has_location' => $c->lat !== null || $c->address !== null,
                // Field map: only a usable pin is sent; the reason tells the phone to list the case under "Location unavailable".
                'lat' => ($ok = Customer::validCoords($c->lat, $c->lng)) ? $c->lat : null, 'lng' => $ok ? $c->lng : null,
                'location_issue' => $ok ? null : ($c->lat === null && $c->lng === null ? 'missing' : 'invalid'), 'address' => $c->address, 'state' => $c->state,
                'outstanding' => $d['outstanding'], 'overdue_amount' => $d['overdue_amount'], 'emi_amount' => $d['emi_amount'], 'due_date' => $d['due_date'],
                'oldest_due_date' => $d['oldest_due_date'],
            ] + $act[$c->id];
        });
        return response()->json([
            'server_time' => $serverTime,
            'visible_ids' => $base->pluck('id'),
            'items' => $items,
        ]);
    }

    /** Full case profile (online). */
    public function show(Request $r, int $id, CaseService $cases)
    {
        $c = Customer::query()->visibleTo($r->user())->findOrFail($id);
        \App\Support\Audit::record('customer.profile_viewed', $c, ['via' => 'phone']);
        return response()->json($cases->profile($c, $r->user(), true));
    }

    /** Promise or payment recorded from the profile without a call (multipart when a slip is attached). */
    public function entry(Request $r, int $id, CaseService $cases)
    {
        $c = Customer::query()->visibleTo($r->user())->findOrFail($id);
        $data = $r->validate(['code' => 'required|string|max:32', 'notes' => 'nullable|string|max:2000', 'follow_up_at' => 'nullable|date',
            'amount' => 'nullable|numeric|min:0|max:99999999', 'payment_mode' => 'nullable|string|max:20', 'payment_ref' => 'nullable|string|max:64',
            'paid_on' => 'nullable|date', 'slip' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,webp,heic,pdf']);
        $code = DispositionCode::query()->where('code', $data['code'])->where('active', true)->first();
        // Only promise / payment outcomes can be recorded without a call; call results need a call.
        abort_unless($code && in_array($code->captures, ['promise', 'payment'], true), 422, 'Choose a promise or payment outcome.');
        $d = $cases->recordEntry($c, $r->user(), $this->device($r)->id, $code->code, $data['notes'] ?? null, $data['follow_up_at'] ?? null,
            array_intersect_key($data, array_flip(['amount', 'payment_mode', 'payment_ref', 'paid_on'])), $r->file('slip'));
        return response()->json(['ok' => true, 'disposition_id' => $d->id], 201);
    }

    public function dispositions()
    {
        // Older app versions read a plain list; the current app asks ?full=1 to also get the payment modes.
        $codes = DispositionCode::query()->where('active', true)->orderBy('label')->get(['code', 'label', 'requires_follow_up', 'captures']);
        return response()->json(request()->boolean('full') ? ['codes' => $codes, 'payment_modes' => \App\Support\Outcomes::PAYMENT_MODES] : $codes);
    }
}
