<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallDisposition;
use App\Support\Audit;
use App\Support\Outcomes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** Payments reported by employees from the call feedback: slip view, verify / reject, e-mail slip + details. */
final class PaymentController extends Controller
{
    private function scoped(Request $r)
    {
        $teams = $r->user()->supervisedTeamIds();
        return CallDisposition::query()->whereNotNull('payment_mode')
            ->when($teams !== null, fn ($q) => $q->whereIn('user_id', DB::table('team_user')->whereIn('team_id', $teams)->pluck('user_id')));
    }

    public function index(Request $r)
    {
        $base = $this->scoped($r)
            ->when($r->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($r->query('mode'), fn ($q, $v) => $q->where('payment_mode', $v))
            ->when($r->query('from'), fn ($q, $v) => $q->where('paid_on', '>=', $v))
            ->when($r->query('to'), fn ($q, $v) => $q->where('paid_on', '<=', $v));
        $totals = (clone $base)->selectRaw('review_status, COUNT(*) n, COALESCE(SUM(amount),0) total')->groupBy('review_status')->get()
            ->mapWithKeys(fn ($t) => [$t->review_status ?? 'pending' => ['count' => (int) $t->n, 'amount' => (float) $t->total]]);
        $page = $base->when($r->query('status'), fn ($q, $v) => $q->where('review_status', $v))
            ->with(['user:id,name,employee_code', 'call:id,uuid,customer_id,initiated_at,talk_seconds', 'call.customer:id,customer_ref,name', 'reviewer:id,name'])
            ->orderByDesc('submitted_at')->paginate(100);
        return response()->json(['totals' => $totals, 'modes' => Outcomes::PAYMENT_MODES] + $page->toArray());
    }

    /** The slip itself (image or PDF), served inline to the signed-in admin only. */
    public function slip(Request $r, int $id)
    {
        $d = $this->scoped($r)->whereNotNull('slip_key')->findOrFail($id);
        Audit::record('payment.slip_viewed', $d, ['call_id' => $d->call_id]);
        return Storage::disk('company_files')->response($d->slip_key, $d->slip_name ?: basename($d->slip_key),
            ['Content-Type' => $d->slip_mime ?: 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
            $r->boolean('dl') ? 'attachment' : 'inline');
    }

    public function review(Request $r, int $id)
    {
        $data = $r->validate(['status' => 'required|in:verified,rejected,pending', 'note' => 'nullable|string|max:1000']);
        $d = $this->scoped($r)->findOrFail($id);
        $d->forceFill(['review_status' => $data['status'], 'review_note' => $data['note'] ?? null,
            'reviewed_by' => $data['status'] === 'pending' ? null : $r->user()->id, 'reviewed_at' => $data['status'] === 'pending' ? null : now()])->save();
        Audit::record('payment.' . $data['status'], $d, ['call_id' => $d->call_id, 'amount' => $d->amount]);
        \App\Models\Customer::query()->whereKey(\App\Models\Call::query()->whereKey($d->call_id)->value('customer_id'))->first()?->touch();   // phones see the new balance
        return response()->json(['ok' => true]);
    }

    /** E-mails the payment details with the slip attached. Uses the MAIL_* settings in backend/.env. */
    public function email(Request $r, int $id)
    {
        $data = $r->validate(['to' => 'required|array|min:1|max:10', 'to.*' => 'required|email:rfc', 'cc' => 'nullable|array|max:10', 'cc.*' => 'email:rfc',
            'subject' => 'nullable|string|max:200', 'message' => 'nullable|string|max:4000']);
        $d = $this->scoped($r)->with(['user:id,name,employee_code', 'call:id,uuid,customer_id,initiated_at', 'call.customer:id,customer_ref,name'])->findOrFail($id);
        $cust = $d->call?->customer;
        $tz = app(\App\Support\CurrentCompany::class)->get()->timezone ?: config('app.timezone');
        $rows = [
            'Customer' => $cust ? "{$cust->name} ({$cust->customer_ref})" : '—',
            'Amount' => '₹ ' . number_format((float) $d->amount, 2),
            'Payment mode' => Outcomes::PAYMENT_MODES[$d->payment_mode] ?? $d->payment_mode,
            'Reference / UTR' => $d->payment_ref ?: '—',
            'Paid on' => $d->paid_on?->format('d M Y') ?? '—',
            'Outcome' => $d->code,
            'Collected by' => $d->user ? "{$d->user->name} ({$d->user->employee_code})" : '—',
            'Reported at' => $d->submitted_at?->setTimezone($tz)->format('d M Y, h:i A') ?? '—',
            'Status' => ucfirst($d->review_status ?? 'pending'),
            'Employee notes' => $d->notes ?: '—',
        ];
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1b2430">'
            . (!empty($data['message']) ? '<p>' . nl2br($e($data['message'])) . '</p>' : '')
            . '<table cellpadding="6" style="border-collapse:collapse;border:1px solid #d9dee5">'
            . implode('', array_map(fn ($k, $v) => '<tr><td style="background:#f3f5f8;border:1px solid #d9dee5"><b>' . $e($k) . '</b></td><td style="border:1px solid #d9dee5">' . $e($v) . '</td></tr>', array_keys($rows), $rows))
            . '</table>' . ($d->slip_key ? '<p>The payment slip is attached.</p>' : '<p><i>No slip was uploaded for this payment.</i></p>') . '</div>';
        $subject = $data['subject'] ?? ('Payment received — ' . ($cust?->name ?? 'customer') . ' — ₹ ' . number_format((float) $d->amount, 2));
        try {
            Mail::html($html, function ($m) use ($data, $subject, $d) {
                $m->to($data['to'])->subject($subject);
                if (!empty($data['cc'])) {
                    $m->cc($data['cc']);
                }
                if ($d->slip_key && Storage::disk('company_files')->exists($d->slip_key)) {
                    $m->attachData(Storage::disk('company_files')->get($d->slip_key), $d->slip_name ?: basename($d->slip_key), ['mime' => $d->slip_mime]);
                }
            });
        } catch (\Throwable $ex) {
            return response()->json(['error' => 'mail_failed', 'message' => 'E-mail could not be sent: ' . mb_substr($ex->getMessage(), 0, 300)], 502);
        }
        $d->forceFill(['emailed_to' => mb_substr(implode(', ', $data['to']), 0, 255), 'emailed_at' => now()])->save();
        Audit::record('payment.emailed', $d, ['call_id' => $d->call_id, 'to' => $data['to']]);
        return response()->json(['ok' => true, 'mailer' => config('mail.default')]);
    }
}
