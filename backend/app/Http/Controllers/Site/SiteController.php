<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Platform\Lead;
use App\Models\Platform\Plan;
use App\Models\Platform\SalesOrder;
use App\Services\Platform\Licensing;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\Razorpay;
use App\Services\Platform\Pricing;
use App\Services\Platform\Sales;
use App\Services\Platform\Workspaces;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Public website: marketing page with live packages, enquiry form, free trial (optional e-mail code), buy → Razorpay → workspace.
 * Forms are server-rendered with CSRF tokens; every POST is rate-limited and has a honeypot field.
 */
final class SiteController extends Controller
{
    public const STATES = ['01' => 'Jammu & Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh', '05' => 'Uttarakhand', '06' => 'Haryana',
        '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh', '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur',
        '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal', '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh',
        '23' => 'Madhya Pradesh', '24' => 'Gujarat', '26' => 'Dadra & Nagar Haveli and Daman & Diu', '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa',
        '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry', '35' => 'Andaman & Nicobar', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh'];

    public function home()
    {
        $site = fn (string $k) => PlatformSettings::get($k) ?: null;
        return view('site.home', ['plans' => $this->plans(), 'features' => Licensing::FEATURES, 'trialDays' => (int) PlatformSettings::get('trial_days'),
            'trialUsers' => (int) PlatformSettings::get('trial_users'), 'contact' => ['email' => $site('seller_email'), 'phone' => $site('seller_phone')],
            'site' => ['announcement' => $site('site_announcement'), 'hero_title' => $site('site_hero_title'), 'hero_text' => $site('site_hero_text'),
                'meta' => $site('site_meta_description')], 'verify' => PlatformSettings::get('trial_verify_email') === '1', 'shots' => self::screenshots()]);
    }

    /**
     * Screenshots for the website gallery: drop JPG/PNG/WebP files into public/site/screens/web (console) or /app (phone).
     * "03-Payments-and-slips.jpg" shows third, captioned "Payments & slips". No code change needed to add or swap one.
     */
    private static function screenshots(): array
    {
        $out = [];
        foreach (['web', 'app'] as $kind) {
            $files = glob(public_path("site/screens/{$kind}/*.{jpg,jpeg,png,webp}"), GLOB_BRACE) ?: [];
            sort($files, SORT_NATURAL);
            $out[$kind] = array_map(fn ($f) => ['src' => "/site/screens/{$kind}/" . rawurlencode(basename($f)) . '?v=' . filemtime($f),
                'caption' => ucfirst(str_replace(['-', ' and '], [' ', ' & '], preg_replace('/^\d+[-_ ]*/', '', pathinfo($f, PATHINFO_FILENAME))))], $files);
        }
        return $out;
    }

    public function enquiry(Request $r)
    {
        if ($r->filled('website')) {
            return redirect('/thank-you');        // honeypot: say thanks, save nothing
        }
        $d = $r->validate(['name' => 'required|string|max:150', 'company' => 'nullable|string|max:150', 'email' => 'nullable|email|max:190|required_without:phone',
            'phone' => 'nullable|string|max:20|required_without:email', 'users' => 'nullable|integer|min:1|max:100000', 'message' => 'nullable|string|max:2000'],
            ['email.required_without' => 'Share an e-mail or a phone number so we can reach you.', 'phone.required_without' => 'Share an e-mail or a phone number so we can reach you.']);
        $lead = Lead::query()->create($d + ['source' => 'website', 'status' => 'new']);
        if ($to = PlatformSettings::get('seller_email')) {
            Sales::send($to, 'New ROVEXY enquiry — ' . $lead->name, '<p>' . collect($d)->filter()->map(fn ($v, $k) => '<b>' . e($k) . ':</b> ' . e((string) $v))->implode('<br>') . '</p>');
        }
        return redirect('/thank-you');
    }

    public function trial(Request $r)
    {
        if ($r->filled('website')) {
            return redirect('/thank-you');
        }
        $d = $r->validate(['company' => 'required|string|max:150', 'name' => 'required|string|max:150', 'email' => 'required|email|max:190',
            'phone' => 'required|string|max:20', 'workspace' => ['required', 'string', 'max:40', 'regex:' . Workspaces::SLUG], 'password' => 'required|string|min:10|max:200|confirmed',
            'terms' => 'accepted'], ['workspace.regex' => 'Workspace: 3–40 lower-case letters, numbers or dashes.']);
        $d['workspace'] = strtolower($d['workspace']);
        $back = fn (string $msg) => back()->withInput($r->except(['password', 'password_confirmation']))->withErrors(['trial' => $msg])->withFragment('trial');
        if (PlatformSettings::get('trial_verify_email') !== '1') {
            return $this->createTrial($d, $back);
        }
        // E-mail check first: the workspace is created only after the 6-digit code comes back (15 minutes, 5 tries).
        if ($why = Sales::trialProblem($d['workspace'], $d['email'])) {
            return $back($why);
        }
        $token = bin2hex(random_bytes(16));
        $code = (string) random_int(100000, 999999);
        Cache::put("trial:{$token}", ['data' => ['password' => bcrypt($d['password'])] + $d, 'code' => hash('sha256', $code), 'tries' => 0], now()->addMinutes(15));
        Sales::send($d['email'], "{$code} is your ROVEXY code", '<p>Your code to create the ROVEXY workspace <b>' . e($d['workspace']) . '</b> is</p><p style="font-size:26px;font-weight:bold;letter-spacing:4px">'
            . $code . '</p><p>It expires in 15 minutes. If you did not ask for this, ignore this e-mail.</p>');
        return view('site.verify', ['token' => $token, 'email' => $d['email']]);
    }

    public function verifyTrial(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|size:32', 'code' => 'required|digits:6']);
        $key = "trial:{$d['token']}";
        $p = Cache::get($key);
        if (!$p) {
            return view('site.message', ['title' => 'This code has expired', 'lines' => ['Please fill in the trial form again.'], 'action' => ['Back to the form', url('/#trial')]]);
        }
        if (!hash_equals($p['code'], hash('sha256', $d['code']))) {
            $p['tries']++;
            if ($p['tries'] >= 5) {
                Cache::forget($key);
                return view('site.message', ['title' => 'Too many wrong codes', 'lines' => ['For safety this request was cancelled. Please fill in the trial form again.'], 'action' => ['Back to the form', url('/#trial')]]);
            }
            Cache::put($key, $p, now()->addMinutes(15));
            return view('site.verify', ['token' => $d['token'], 'email' => $p['data']['email'], 'error' => 'That code is not right. Check the e-mail and try again.']);
        }
        Cache::forget($key);
        return $this->createTrial($p['data'], fn (string $msg) => view('site.message', ['title' => 'We could not create the workspace', 'lines' => [$msg], 'action' => ['Back to the form', url('/#trial')]]));
    }

    private function createTrial(array $d, callable $fail)
    {
        try {
            $company = Sales::startTrial($d['company'], $d['name'], $d['email'], $d['phone'], $d['workspace'], $d['password']);
        } catch (DomainException $e) {
            return $fail($e->getMessage());
        }
        Sales::send($d['email'], 'Your ROVEXY trial is ready', '<p>Dear ' . e($d['name']) . ',</p><p>Your workspace <b>' . e($company->slug) . '</b> is ready for '
            . PlatformSettings::get('trial_days') . ' days.</p><p>Sign in: <a href="' . e(Workspaces::consoleUrl($company)) . '">' . e(Workspaces::consoleUrl($company)) . '</a> with ' . e($d['email']) . '.</p><p>— Ametecs India</p>');
        return view('site.message', ['title' => 'Your trial is ready', 'lines' => ["Workspace: {$company->slug}", 'Sign in with ' . $d['email'] . ' and the password you chose.',
            'Next: add your team under Groups & people, then approve their phones under Devices.'], 'action' => ['Open my console', Workspaces::consoleUrl($company)]]);
    }

    public function buy(Request $r)
    {
        $plans = $this->plans();
        $plan = $plans->firstWhere('code', $r->query('plan', $r->query('tier'))) ?? $plans->first();
        abort_if($plan === null, 404);
        return view('site.buy', ['plans' => $plans, 'plan' => $plan, 'states' => self::STATES, 'sellerState' => PlatformSettings::get('seller_state'),
            'kind' => $r->query('kind') === 'perpetual' ? 'perpetual' : 'cloud',
            'users' => max($plan->min_users, (int) $r->query('users', $plan->min_users)), 'billing' => array_key_exists((string) $r->query('billing'), Pricing::CYCLES) ? $r->query('billing') : 'annual',
            'gst' => (float) PlatformSettings::get('gst_rate'), 'payments' => Razorpay::configured(), 'trialDays' => (int) PlatformSettings::get('trial_days'),
            'annualPct' => (float) PlatformSettings::get('price_annual_discount_pct'), 'halfPct' => (float) PlatformSettings::get('price_half_yearly_discount_pct'),
            'amcPct' => (float) PlatformSettings::get('amc_pct'),
            'seller' => ['name' => PlatformSettings::get('seller_name'), 'gstin' => PlatformSettings::get('seller_gstin'), 'phone' => PlatformSettings::get('seller_phone')]]);
    }

    public function placeOrder(Request $r)
    {
        if ($r->filled('website')) {
            return redirect('/thank-you');
        }
        $own = $r->input('kind') === 'perpetual';   // on-premises perpetual: a licence key, no cloud workspace
        $d = $r->validate(['plan' => 'required|string', 'kind' => 'nullable|in:cloud,perpetual', 'users' => 'required|integer|min:1|max:100000', 'billing' => ($own ? 'nullable' : 'required') . '|in:annual,half_yearly,quarterly',
            'buyer_company' => 'required|string|max:150', 'buyer_name' => 'required|string|max:150',
            'buyer_email' => 'required|email|max:190', 'buyer_phone' => 'required|string|max:20', 'state_code' => ['required', \Illuminate\Validation\Rule::in(array_keys(self::STATES))],
            'buyer_gstin' => 'nullable|string|max:15', 'coupon' => 'nullable|string|max:30', 'workspace' => ($own ? 'nullable' : 'required') . '|string|max:40',
            'password' => ($own ? 'nullable' : 'required|min:10|confirmed') . '|string|max:200', 'terms' => 'accepted']);
        $plan = Plan::query()->where('code', $d['plan'])->where('active', true)->where('is_public', true)->firstOrFail();
        try {
            $o = Sales::createOrder('new', ['plan_id' => $plan->id, 'users' => $d['users'], 'billing' => $own ? null : $d['billing'], 'coupon' => $d['coupon'] ?? null,
                'deployment' => $own ? 'onprem' : 'cloud', 'licence_kind' => $own ? 'perpetual' : 'subscription'],
                $own ? $d : ['workspace' => strtolower((string) $d['workspace']), 'admin_password' => $d['password']] + $d, null, 'website');
        } catch (DomainException $e) {
            return back()->withInput($r->except(['password', 'password_confirmation']))->withErrors(['order' => $e->getMessage()]);
        }
        return redirect($o->payUrl());
    }

    /** Live check of a discount code on the Buy page: the price the server will charge. */
    /** Live price on the Buy page and the pricing finder: exactly what the server will charge (Pricing::build). */
    public function priceCheck(Request $r)
    {
        $d = $r->validate(['plan' => 'required|string', 'users' => 'required|integer|min:1|max:100000', 'billing' => 'nullable|in:annual,half_yearly,quarterly',
            'deployment' => 'nullable|in:cloud,onprem', 'state_code' => 'nullable|string|size:2', 'code' => 'nullable|string|max:30', 'email' => 'nullable|email|max:190']);
        $plan = Plan::query()->where('code', $d['plan'])->where('active', true)->firstOrFail();
        $onprem = ($d['deployment'] ?? 'cloud') === 'onprem';
        try {
            [, $pr] = Sales::quote('new', ['plan_id' => $plan->id, 'users' => $d['users'], 'billing' => $d['billing'] ?? 'annual', 'coupon' => $d['code'] ?? null,
                'deployment' => $onprem ? 'onprem' : 'cloud', 'licence_kind' => $onprem ? 'perpetual' : 'subscription'],
                ['buyer_email' => $d['email'] ?? null, 'state_code' => $d['state_code'] ?? null]);
        } catch (DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->reason, 'message' => $e->getMessage()], 422);
        }
        return response()->json(['ok' => true] + $pr + ['per_user_month' => !$onprem ? round($pr['list_price'] / $d['users'] / Pricing::CYCLES[$pr['billing']], 2) : null],
            200, [], JSON_PRESERVE_ZERO_FRACTION);   // money stays 5000.0, not 5000
    }

    public function pay(string $number, string $token)
    {
        $o = $this->order($number, $token);
        return view('site.pay', ['o' => $o->load('plan', 'licence'), 'token' => $token, 'payments' => Razorpay::configured(),
            'console' => $o->company_id ? Workspaces::consoleUrl(\App\Models\Company::query()->find($o->company_id)) : null,
            'seller' => ['name' => PlatformSettings::get('seller_name'), 'email' => PlatformSettings::get('seller_email'), 'phone' => PlatformSettings::get('seller_phone')]]);
    }

    /** Razorpay order for the checkout popup (created once per sales order). */
    public function razorpayOrder(string $number, string $token)
    {
        $o = $this->order($number, $token);
        abort_unless($o->status === 'created', 409, 'This order is already paid or cancelled.');
        abort_if($o->expiredQuote(), 409, 'This quotation has expired. Contact us for a fresh one.');
        $o->increment('attempts');
        return response()->json(['key' => Razorpay::keyId(), 'order_id' => Razorpay::orderFor($o), 'amount' => (int) round($o->total * 100), 'currency' => 'INR',
            'name' => PlatformSettings::get('seller_name'), 'description' => 'ROVEXY — ' . $o->plan->name . ' (' . $o->number . ')',
            'prefill' => ['name' => $o->buyer_name, 'email' => $o->buyer_email, 'contact' => $o->buyer_phone]]);
    }

    /** Checkout callback. The webhook does the same if the browser closes first; markPaid() runs once. */
    public function razorpayVerify(Request $r, string $number, string $token)
    {
        $o = $this->order($number, $token);
        $d = $r->validate(['razorpay_order_id' => 'required|string|max:60', 'razorpay_payment_id' => 'required|string|max:60', 'razorpay_signature' => 'required|string|max:200']);
        if ($d['razorpay_order_id'] !== $o->razorpay_order_id || !Razorpay::verifyPayment($d['razorpay_order_id'], $d['razorpay_payment_id'], $d['razorpay_signature'])) {
            return response()->json(['ok' => false, 'message' => 'We could not confirm this payment. If money was deducted, it will be confirmed automatically within a few minutes, or contact us.'], 422);
        }
        // The signature proves the gateway accepted the payment. Ask the gateway too, so an authorised-only payment is captured
        // before we provision; if the gateway can't be reached right now, the signature is enough (reconciliation re-checks).
        if (Razorpay::settle($o, 'razorpay') === false) {
            return response()->json(['ok' => false, 'message' => 'The payment is not complete yet. If money was deducted, it will be confirmed automatically within a few minutes.'], 422);
        }
        Sales::markPaid($o, 'razorpay', $d['razorpay_payment_id'], $d['razorpay_payment_id'], 'razorpay');
        return response()->json(['ok' => true, 'redirect' => $o->payUrl()]);
    }

    /**
     * The checkout told the browser the payment failed. Shown to staff and the buyer only; it never changes what was paid
     * (that is decided by the verified callback / webhook), so an untrusted report here can do no harm.
     */
    public function razorpayFailed(Request $r, string $number, string $token)
    {
        $o = $this->order($number, $token);
        $d = $r->validate(['reason' => 'nullable|string|max:200']);
        Sales::recordFailure($o, (string) ($d['reason'] ?? ''), 'buyer');
        return response()->json(['ok' => true]);
    }

    public function invoice(string $number, string $token)
    {
        $o = $this->order($number, $token);
        abort_unless($o->status === 'paid', 404);
        $seller = collect(PlatformSettings::DEFAULTS)->keys()->mapWithKeys(fn ($k) => [$k => PlatformSettings::get($k)])->all();
        return view('site.invoice', ['o' => $o->load('plan', 'licence'), 'seller' => $seller, 'company' => $o->company_id ? \App\Models\Company::query()->find($o->company_id) : null, 'states' => self::STATES, 'gst' => (float) PlatformSettings::get('gst_rate')]);
    }

    public function page(string $page)
    {
        $set = fn (string $k) => PlatformSettings::get($k) ?: '';
        $seller = $set('seller_name');
        $mail = $set('seller_email') ?: 'us';
        if ($page === 'thank-you') {
            return view('site.message', ['title' => 'Thank you', 'lines' => ['We have your details and will get back to you within one working day.'], 'action' => ['Back to ROVEXY', url('/')]]);
        }
        $updated = '12 October 2026';
        $pages = [
            'privacy' => ['Privacy policy', [
                ['Who we are', "ROVEXY is operated by {$seller}, {$set('seller_address')}. For any privacy question write to {$mail}."],
                ['What we hold', 'For each customer company (our client) we hold the data the company puts into ROVEXY: its employees, its customer portfolios, call records and recordings, field visits, payments and slips, and phone locations during work sessions. We also hold the contact details of people who enquire, start a trial or buy on this website.'],
                ['Why we hold it', 'Only to provide the ROVEXY service to the company, to bill for it, and to send service and renewal e-mails. We do not sell, rent or share this data for anyone else\'s marketing.'],
                ['Employee location', 'Phone location is collected only during a work session, between sign-in and sign-out on the app, and the phone shows a notice the whole time. It is visible only to the employee\'s own company.'],
                ['Who can see the data', 'Each company\'s data is kept separate. Inside a company, access follows the roles and groups its admin sets. Ametecs staff access a workspace only to provide support or when the law requires it.'],
                ['Payments', 'Online payments are processed by Razorpay. We never see or store your card or bank details.'],
                ['How long we keep it', 'Call recordings and data are kept for the retention period the company sets in the console, then deleted. When a company stops using ROVEXY, its data is deleted on request or after the agreed notice period.'],
                ['Your requests', "Employees and customers of a client company should first contact that company, which controls its data. You can also write to {$mail} and we will help."],
            ]],
            'terms' => ['Terms of service', [
                ['The service', "ROVEXY is a cloud service provided by {$seller}. By starting a trial or buying a package, the company agrees to these terms."],
                ['Packages', 'Packages are sold for the period and number of users shown at purchase. Prices are in Indian rupees and exclude GST, which is added on the invoice.'],
                ['Use', 'A workspace may be used only by the company it is issued to, for lawful collection, calling and field work. The company is responsible for its employees\' use and for having the consent and notices the law requires for call recording and location.'],
                ['Renewal and expiry', 'Renewals extend from the current expiry date, so renewing early never loses days. After expiry there are a few days\' grace; after that, phones cannot start work until the package is renewed. Data is not deleted on expiry.'],
                ['Data', 'The company owns its data. We keep it secure, back it up and keep it for the retention period the company sets.'],
                ['Availability and support', 'We work to keep the service available at all times and announce planned maintenance in advance. Support is by e-mail and phone on working days.'],
                ['Liability', 'Our total liability for any claim is limited to the amount paid for the package in the twelve months before the claim.'],
                ['Law', 'These terms are governed by the laws of India, with courts in Hyderabad, Telangana having jurisdiction.'],
            ]],
            'refund' => ['Refund & cancellation policy', [
                ['Free trial first', 'Every company can try ROVEXY free before paying, so you can check it suits your team.'],
                ['Cancellation', "You can cancel an unpaid order at any time. A package can be stopped from renewing by simply not renewing it; write to {$mail} if you would like us to close the workspace."],
                ['Refunds', 'Payments are non-refundable once the workspace has been created or the licence renewed, because the service is delivered immediately. If you were charged twice or charged by mistake, write to us within 7 days and the extra amount is refunded to the original payment method within 5–7 working days.'],
            ]],
            'delivery' => ['Service delivery policy', [
                ['How the service is delivered', 'ROVEXY is software delivered online. Nothing is shipped physically.'],
                ['When', 'A new workspace is created automatically as soon as payment is confirmed, and the sign-in link and details are e-mailed to the buyer. Renewals and upgrades apply to the existing workspace immediately.'],
                ['If something goes wrong', "If you have paid and have not received your workspace e-mail within one hour, write to {$mail} with your order number."],
            ]],
            'contact' => ['Contact us', [
                ['Sales and demos', 'Tell us your team size and we will call you back within one working day.'],
                ['Support', 'Existing customers can reach us by e-mail or phone on working days.'],
            ]],
        ];
        [$title, $sections] = $pages[$page];
        $cards = $page === 'contact' ? array_values(array_filter([
            ['Company', $seller, null], ['Address', $set('seller_address'), null],
            $set('seller_phone') ? ['Phone', $set('seller_phone'), 'tel:' . preg_replace('/[^\d+]/', '', $set('seller_phone'))] : null,
            $set('seller_email') ? ['E-mail', $set('seller_email'), 'mailto:' . $set('seller_email')] : null,
            $set('seller_gstin') ? ['GSTIN', $set('seller_gstin'), null] : null])) : [];
        return view('site.page', ['title' => $title, 'sections' => $sections, 'cards' => $cards, 'updated' => $page === 'contact' ? null : $updated,
            'action' => $page === 'contact' ? ['Send an enquiry', url('/#contact')] : null]);
    }

    private function order(string $number, string $token): SalesOrder
    {
        $o = SalesOrder::query()->where('number', $number)->firstOrFail();
        abort_unless(hash_equals($o->token(), $token), 404);
        return $o;
    }

    private function plans()
    {
        return Plan::query()->with('cloudTiers', 'perpetualBands')->where('active', true)->where('is_public', true)->orderBy('sort')->orderBy('id')->get()
            ->each(function (Plan $p) {   // "from" prices for the pricing cards
                $p->from_rate = $p->cloudTiers->filter(fn ($t) => $t->max_users !== null && $t->rate_inr > 0)->min('rate_inr');
                $p->from_perpetual = Pricing::perpetualPrice($p, $p->min_users);
                $p->cloud_max = $p->cloudTiers->filter(fn ($t) => $t->max_users !== null && $t->rate_inr > 0)->max('max_users');
            });
    }
}
