<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Company;
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
 * Public website: marketing page with live packages, enquiry form, free trial and buy (both after an e-mail code) → Razorpay → workspace.
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
        return view('site.home', ['plans' => $this->plans()->reject(fn (Plan $p) => $p->quarterlyCloud())->values(), 'cloud' => \App\Services\Platform\CloudPlans::forSite(),
            'gstRate' => (float) PlatformSettings::get('gst_rate'), 'features' => Licensing::FEATURES, 'trialDays' => (int) PlatformSettings::get('trial_days'),
            'trialUsers' => (int) PlatformSettings::get('trial_users'), 'contact' => ['email' => $site('seller_email'), 'phone' => $site('seller_phone')],
            'site' => ['announcement' => $site('site_announcement'), 'hero_title' => $site('site_hero_title'), 'hero_text' => $site('site_hero_text'),
                'meta' => $site('site_meta_description')], 'shots' => self::screenshots()]);
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
        if ($r->filled('hp_x9')) {   // hidden robot trap; not called "website": Chrome autofill fills that for real people
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
        if ($r->filled('hp_x9')) {   // hidden robot trap; not called "website": Chrome autofill fills that for real people
            return redirect('/thank-you');
        }
        $d = $r->validate(['company' => 'required|string|max:150', 'name' => 'required|string|max:150', 'email' => 'required|email|max:190',
            'phone' => 'required|string|max:20', 'workspace' => ['required', 'string', 'max:40', 'regex:' . Workspaces::SLUG], 'password' => ['required', 'string', 'max:200', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
            'terms' => 'accepted'], ['workspace.regex' => 'Workspace: 3–40 lower-case letters, numbers or dashes.']);
        $d['workspace'] = strtolower($d['workspace']);
        $back = fn (string $msg) => back()->withInput($r->except(['password', 'password_confirmation']))->withErrors(['trial' => $msg])->withFragment('trial');
        // E-mail check first: the workspace is created only after the 6-digit code comes back.
        if ($why = Sales::trialProblem($d['workspace'], $d['email'])) {
            return $back($why);
        }
        return $this->startOtp('trial', ['password' => bcrypt($d['password'])] + $d, $d['email'], "the ROVEXY workspace <b>" . e($d['workspace']) . '</b>', $back);
    }

    /**
     * E-mail one-time code (free trial and website purchase). The form data waits in the cache until the code comes back.
     * Code: 6 digits, valid OTP_MINUTES; OTP_TRIES wrong tries cancel the request; resend after OTP_RESEND_SECONDS, at most OTP_SENDS codes.
     */
    public const OTP_MINUTES = 15;
    public const OTP_TRIES = 5;
    public const OTP_RESEND_SECONDS = 60;
    public const OTP_SENDS = 5;

    private function startOtp(string $purpose, array $data, string $email, string $what, callable $fail)
    {
        if (!PlatformSettings::mailReady() && !self::showCodeOnScreen()) {
            return $fail('We cannot send the confirmation e-mail right now. Please try again later, or contact us.');
        }
        $token = bin2hex(random_bytes(16));
        $p = ['purpose' => $purpose, 'data' => $data, 'email' => $email, 'what' => $what, 'tries' => 0, 'sends' => 0, 'code' => '', 'sent_at' => 0];
        if (!$this->sendOtp($token, $p)) {
            return $fail('We could not send the e-mail to ' . $email . '. Check the address and try again.');
        }
        return view('site.verify', ['token' => $token, 'email' => $email, 'purpose' => $purpose, 'notice' => 'We sent a 6-digit code to ' . $email . '.']
            + $this->devCode($token));
    }

    /** Local test server without e-mail (APP_ENV=local, no SMTP): the code is shown on the page. Never on a live server. */
    private static function showCodeOnScreen(): bool
    {
        return app()->environment('local') && !PlatformSettings::mailReady();
    }

    private function devCode(string $token): array
    {
        return self::showCodeOnScreen() ? ['dev_code' => Cache::get("otp:{$token}")['plain'] ?? null] : [];
    }

    /** New code → e-mail → cache. False if the e-mail could not be sent (nothing is stored then). */
    private function sendOtp(string $token, array $p): bool
    {
        $code = (string) random_int(100000, 999999);
        if (self::showCodeOnScreen()) {
            $p['plain'] = $code;   // local test server only: shown on the page instead of e-mailed
        } else try {
            \Illuminate\Support\Facades\Mail::html('<p>Your code to confirm ' . $p['what'] . ' is</p><p style="font-size:26px;font-weight:bold;letter-spacing:4px">' . $code
                . '</p><p>It expires in ' . self::OTP_MINUTES . ' minutes. If you did not ask for this, ignore this e-mail.</p>',
                fn ($m) => $m->to($p['email'])->subject("{$code} is your ROVEXY code"));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('site.otp_mail_failed', ['to' => $p['email'], 'error' => $e->getMessage()]);
            return false;
        }
        $p['code'] = hash('sha256', $code);
        $p['tries'] = 0;
        $p['sends']++;
        $p['sent_at'] = now()->getTimestamp();
        $p['expires_at'] = now()->getTimestamp() + self::OTP_MINUTES * 60;
        Cache::put("otp:{$token}", $p, now()->addMinutes(self::OTP_MINUTES));
        return true;
    }

    private function otpExpired(string $purpose)
    {
        $back = match ($purpose) { 'buy' => url('/buy'), 'demo' => url('/explore-demo'), 'app' => url('/'), default => url('/#trial') };
        return view('site.message', ['title' => 'This code has expired', 'lines' => ['Codes work for ' . self::OTP_MINUTES . ' minutes. Please fill in the form again.'], 'action' => ['Back to the form', $back]]);
    }

    public function resendOtp(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|size:32']);
        $p = Cache::get("otp:{$d['token']}");
        if (!$p) {
            return $this->otpExpired((string) $r->input('purpose'));
        }
        $view = fn (array $extra) => view('site.verify', ['token' => $d['token'], 'email' => $p['email'], 'purpose' => $p['purpose']] + $extra);
        $wait = $p['sent_at'] + self::OTP_RESEND_SECONDS - now()->getTimestamp();
        if ($wait > 0) {
            return $view(['error' => "Please wait {$wait} seconds before asking for a new code."]);
        }
        if ($p['sends'] >= self::OTP_SENDS) {
            return $view(['error' => 'We have already sent ' . self::OTP_SENDS . ' codes. Use the latest one, or fill in the form again.']);
        }
        if (!$this->sendOtp($d['token'], $p)) {
            return $view(['error' => 'We could not send the e-mail just now. Please try again in a minute.']);
        }
        return $view(['notice' => 'A new code is on its way to ' . $p['email'] . '. Earlier codes no longer work.'] + $this->devCode($d['token']));
    }

    /** POST /verify-email (also /trial/verify): check the code, then create the trial or place the order. */
    public function verifyTrial(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|size:32', 'code' => ['required', 'string']]);
        $key = "otp:{$d['token']}";
        $p = Cache::get($key);
        if (!$p) {
            return $this->otpExpired((string) $r->input('purpose'));
        }
        $view = fn (array $extra) => view('site.verify', ['token' => $d['token'], 'email' => $p['email'], 'purpose' => $p['purpose']] + $extra);
        $code = preg_replace('/\D/', '', $d['code']);
        if (strlen($code) !== 6) {
            return $view(['error' => 'Enter the 6-digit code from the e-mail.']);
        }
        if (!hash_equals($p['code'], hash('sha256', $code))) {
            $p['tries']++;
            if ($p['tries'] >= self::OTP_TRIES) {
                Cache::forget($key);
                return view('site.message', ['title' => 'Too many wrong codes', 'lines' => ['For safety this request was cancelled. Please fill in the form again.'],
                    'action' => ['Back to the form', match ($p['purpose']) { 'buy' => url('/buy'), 'demo' => url('/explore-demo'), 'app' => url('/'), default => url('/#trial') }]]);
            }
            Cache::put($key, $p, now()->setTimestamp($p['expires_at']));
            $left = self::OTP_TRIES - $p['tries'];
            return $view(['error' => "That code is not right. {$left} " . ($left === 1 ? 'try' : 'tries') . ' left.']);
        }
        Cache::forget($key);
        if ($p['purpose'] === 'app') {
            return $this->createAppLead($p['data']);
        }
        if ($p['purpose'] === 'demo') {
            return $this->createDemo($p['data']);
        }
        if ($p['purpose'] === 'buy') {
            return $this->createOrder($p['data'], fn (string $msg) => view('site.message', ['title' => 'We could not place the order', 'lines' => [$msg], 'action' => ['Back to Buy', url('/buy')]]));
        }
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

    public const PLAY = ['staff' => ['ROVEXY', 'in.ametecs.managedmobile'], 'admin' => ['ROVEXY Admin', 'in.ametecs.rovexy.admin']];
    public const PURPOSES = ['Use ROVEXY in my company', 'Evaluate ROVEXY for my company', 'My company already uses ROVEXY', 'Other'];

    /** GET /play/{staff|admin}: short details form before the Google Play link. */
    public function appForm(string $app)
    {
        return view('site.play', ['app' => $app, 'appName' => self::PLAY[$app][0], 'purposes' => self::PURPOSES, 'industries' => self::industries()]);
    }

    /** Industry choices = the portfolio types ("Custom" shown as "Other"). */
    private static function industries(): array
    {
        return collect(\App\Domain\Portfolios\PortfolioTypes::TYPES)->pluck('label')->map(fn ($l) => $l === 'Custom' ? 'Other' : $l)->all();
    }

    /** POST /play/{app}: details → e-mail code → lead saved → off to Google Play. */
    public function appLead(Request $r, string $app)
    {
        if ($r->filled('hp_x9')) {
            return redirect()->away(self::playUrl($app));
        }
        $d = $r->validate(['company' => 'required|string|max:150', 'email' => 'required|email|max:190', 'users' => 'required|integer|min:1|max:100000',
            'purpose' => ['required', \Illuminate\Validation\Rule::in(self::PURPOSES)], 'industry' => ['required', \Illuminate\Validation\Rule::in(self::industries())]]);
        $d['email'] = strtolower($d['email']);
        $d['app'] = $app;
        $back = fn (string $msg) => back()->withInput()->withErrors(['play' => $msg]);
        return $this->startOtp('app', $d, $d['email'], 'your e-mail for the ' . e(self::PLAY[$app][0]) . ' app', $back);
    }

    private static function playUrl(string $app): string
    {
        return 'https://play.google.com/store/apps/details?id=' . (self::PLAY[$app] ?? self::PLAY['staff'])[1] . '&hl=en_IN';
    }

    private function createAppLead(array $d)
    {
        $app = self::PLAY[$d['app']][0];
        $info = "{$app} app download. Purpose: {$d['purpose']}. Industry: {$d['industry']}.";
        Lead::query()->create(['name' => $d['company'], 'company' => $d['company'], 'email' => $d['email'], 'users' => $d['users'], 'source' => 'app', 'status' => 'new', 'message' => $info]);
        if ($to = PlatformSettings::get('seller_email')) {
            Sales::send($to, "New {$app} app download — {$d['company']}", '<p>' . collect(['App' => $app, 'Company' => $d['company'], 'E-mail' => $d['email'],
                'Employees' => $d['users'], 'Purpose' => $d['purpose'], 'Industry' => $d['industry']])->map(fn ($v, $k) => '<b>' . e($k) . ':</b> ' . e((string) $v))->implode('<br>') . '</p>');
        }
        return redirect()->away(self::playUrl($d['app']));
    }

    /** GET /explore-demo: the demo sign-up page (city decides the team, customers and recordings the demo shows). */
    public function exploreDemo()
    {
        return view('site.explore-demo', ['cities' => \App\Services\Demo\DemoCities::options()]);
    }

    /** POST /explore-demo: details → e-mail code → demo workspace built for the chosen city → signed in to the console. */
    public function demo(Request $r)
    {
        if ($r->filled('hp_x9')) {   // hidden robot trap; not called "website": Chrome autofill fills that for real people
            return redirect('/thank-you');
        }
        $d = $r->validate(['name' => 'required|string|max:150', 'business' => 'required|string|max:150', 'mobile' => ['required', 'string', 'max:20', 'regex:/^[+0-9 ()-]{10,20}$/'],
            'email' => 'required|email|max:190', 'address' => 'required|string|max:300', 'city' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Services\Demo\DemoCities::CITIES))],
            'password' => ['required', 'string', 'max:200', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()], 'terms' => 'accepted'],
            ['city.in' => 'Choose your city (or the nearest one) from the list.', 'mobile.regex' => 'Enter a 10-digit mobile number.']);
        $d['email'] = strtolower($d['email']);
        $back = fn (string $msg) => back()->withInput($r->except(['password', 'password_confirmation']))->withErrors(['demo' => $msg]);
        if ($mine = Company::query()->whereNotNull('demo')->where('demo->email', $d['email'])->first()) {
            return view('site.message', ['title' => 'You already have a demo', 'lines' => ["Your demo for {$mine->name} is ready to use. Sign in with {$d['email']}.",
                'Forgot the password? Use "Forgot password" on the sign-in page.'], 'action' => ['Open my demo', Workspaces::consoleUrl($mine)]]);
        }
        return $this->startOtp('demo', ['password' => bcrypt($d['password'])] + \Illuminate\Support\Arr::except($d, ['password', 'password_confirmation', 'terms']), $d['email'], 'your ROVEXY demo', $back);
    }

    /** After the e-mail code: build the demo, sign the client straight in, tell sales. */
    private function createDemo(array $d)
    {
        try {
            $company = \App\Services\Demo\DemoWorkspace::register($d);
        } catch (DomainException $e) {
            return view('site.message', ['title' => 'We could not open the demo', 'lines' => [$e->getMessage()], 'action' => ['Back to the form', url('/explore-demo')]]);
        }
        $city = \App\Services\Demo\DemoCities::get($d['city'])[1];
        Lead::query()->create(['name' => $d['name'], 'company' => $d['business'], 'email' => $d['email'], 'phone' => $d['mobile'], 'source' => 'demo', 'status' => 'new',
            'message' => "Opened a demo ({$city}). Address: {$d['address']}"]);
        if ($to = PlatformSettings::get('seller_email')) {
            Sales::send($to, "New ROVEXY demo — {$d['business']} ({$city})", '<p>' . collect(['Name' => $d['name'], 'Business' => $d['business'], 'Mobile' => $d['mobile'],
                'E-mail' => $d['email'], 'Address' => $d['address'], 'City' => $city, 'Console' => Workspaces::consoleUrl($company)])
                ->map(fn ($v, $k) => '<b>' . e($k) . ':</b> ' . e((string) $v))->implode('<br>') . '</p>');
        }
        Sales::send($d['email'], 'Your ROVEXY demo is ready', '<p>Dear ' . e($d['name']) . ',</p><p>Your ROVEXY demo for <b>' . e($d['business']) . '</b> is ready, with a sample team and customers in '
            . e($city) . '.</p><p>Sign in: <a href="' . e(Workspaces::consoleUrl($company)) . '">' . e(Workspaces::consoleUrl($company)) . '</a> with ' . e($d['email']) . ' and the password you chose.</p><p>— Ametecs India</p>');
        app(\App\Support\CurrentCompany::class)->run($company, function () {
            $admin = \App\Models\User::query()->where('employee_code', 'admin')->firstOrFail();
            \Illuminate\Support\Facades\Auth::guard('web')->login($admin);
            request()->session()->regenerate();
            request()->session()->put(['auth_started_at' => time(), 'mfa_verified_at' => time()]);   // the e-mail code was the second factor
            \App\Support\Audit::record('admin.login', $admin, ['via' => 'demo_signup']);
        });
        return redirect(Workspaces::consoleUrl($company));
    }

    public function buy(Request $r)
    {
        $plans = $this->plans();
        $own = $r->query('kind') === 'perpetual';
        $pool = $plans->filter(fn (Plan $p) => $own !== $p->quarterlyCloud());   // on-premises: the classic plans; cloud: ROVEXY Cloud
        $asked = (string) $r->query('plan', $r->query('tier'));
        $legacy = preg_match('/^(cloud-(?:ametecs|own))-c2c(-smart31)?$/', $asked, $m) ? $m : null;   // old links: add-on plans → Click2Call for everyone
        $plan = $pool->firstWhere('code', $legacy[1] ?? $asked) ?? $pool->first();
        abort_if($plan === null, 404);
        $users = max($plan->min_users, (int) $r->query('users', $plan->min_users));
        return view('site.buy', ['plans' => $plans, 'plan' => $plan, 'states' => self::STATES, 'sellerState' => PlatformSettings::get('seller_state'),
            'kind' => $own ? 'perpetual' : 'cloud',
            'users' => $users, 'c2c' => min($users, max(0, (int) $r->query('c2c', $legacy ? $users : 0))), 'smart31' => $r->boolean('smart31', isset($legacy[2])),
            'billing' => array_key_exists((string) $r->query('billing'), Pricing::CYCLES) ? $r->query('billing') : 'annual',
            'gst' => (float) PlatformSettings::get('gst_rate'), 'payments' => Razorpay::configured(), 'trialDays' => (int) PlatformSettings::get('trial_days'),
            'annualPct' => (float) PlatformSettings::get('price_annual_discount_pct'), 'halfPct' => (float) PlatformSettings::get('price_half_yearly_discount_pct'),
            'amcPct' => (float) PlatformSettings::get('amc_pct'), 'c2cRate' => Pricing::c2cRate(false), 's31Rate' => Pricing::c2cRate(true),
            'seller' => ['name' => PlatformSettings::get('seller_name'), 'gstin' => PlatformSettings::get('seller_gstin'), 'phone' => PlatformSettings::get('seller_phone')]]);
    }

    public function placeOrder(Request $r)
    {
        if ($r->filled('hp_x9')) {   // hidden robot trap; not called "website": Chrome autofill fills that for real people
            return redirect('/thank-you');
        }
        $own = $r->input('kind') === 'perpetual';   // on-premises perpetual: a licence key, no cloud workspace
        $d = $r->validate(['plan' => 'required|string', 'kind' => 'nullable|in:cloud,perpetual', 'users' => 'required|integer|min:1|max:100000',
            'c2c_users' => 'nullable|integer|min:0|max:100000', 'smart31' => 'nullable|boolean', 'billing' => ($own ? 'nullable' : 'required') . '|in:annual,half_yearly,quarterly',
            'buyer_company' => 'required|string|max:150', 'buyer_name' => 'required|string|max:150',
            'buyer_email' => 'required|email|max:190', 'buyer_phone' => 'required|string|max:20', 'state_code' => ['required', \Illuminate\Validation\Rule::in(array_keys(self::STATES))],
            'buyer_gstin' => 'nullable|string|max:15', 'coupon' => 'nullable|string|max:30', 'workspace' => ($own ? 'nullable' : 'required') . '|string|max:40',
            'password' => $own ? ['nullable', 'string', 'max:200'] : ['required', 'string', 'max:200', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()], 'terms' => 'accepted']);
        $chosen = Plan::query()->where('code', $d['plan'])->where('active', true)->where('is_public', true)->firstOrFail();
        $back = fn (string $msg) => back()->withInput($r->except(['password', 'password_confirmation']))->withErrors(['order' => $msg]);
        if ($msg = self::offerProblem($chosen, $own)) {
            return $back($msg);
        }
        if (!$own) {
            $d['workspace'] = strtolower((string) $d['workspace']);
            if (!preg_match(Workspaces::SLUG, $d['workspace']) || \App\Models\Company::query()->where('slug', $d['workspace'])->exists()) {
                return $back('Choose another workspace name (3–40 lower-case letters, numbers or dashes; not already in use).');
            }
        }
        unset($d['password_confirmation']);
        // ponytail: the admin password waits in the cache (encrypted at rest only if the cache is); it is hashed when the workspace is created.
        return $this->startOtp('buy', $d, $d['buyer_email'], 'your ROVEXY order', $back);
    }

    private function createOrder(array $d, callable $fail)
    {
        $own = ($d['kind'] ?? 'cloud') === 'perpetual';
        $plan = Plan::query()->where('code', $d['plan'])->where('active', true)->where('is_public', true)->first();
        if (!$plan) {
            return $fail('This package is no longer on sale. Please choose another.');
        }
        if ($msg = self::offerProblem($plan, $own)) {
            return $fail($msg);
        }
        try {
            $o = Sales::createOrder('new', ['plan_id' => $plan->id, 'users' => $d['users'], 'billing' => $own ? null : $d['billing'], 'coupon' => $d['coupon'] ?? null,
                'c2c_users' => (int) ($d['c2c_users'] ?? 0), 'smart31' => (bool) ($d['smart31'] ?? false),
                'deployment' => $own ? 'onprem' : 'cloud', 'licence_kind' => $own ? 'perpetual' : 'subscription'],
                $own ? $d : ['workspace' => $d['workspace'], 'admin_password' => $d['password']] + $d, null, 'website');
        } catch (DomainException $e) {
            return $fail($e->getMessage());
        }
        return redirect($o->payUrl());
    }

    /** Live check of a discount code on the Buy page: the price the server will charge. */
    /** Live price on the Buy page and the pricing finder: exactly what the server will charge (Pricing::build). */
    public function priceCheck(Request $r)
    {
        $d = $r->validate(['plan' => 'required|string', 'users' => 'required|integer|min:1|max:100000', 'billing' => 'nullable|in:annual,half_yearly,quarterly',
            'deployment' => 'nullable|in:cloud,onprem', 'state_code' => 'nullable|string|size:2', 'code' => 'nullable|string|max:30', 'email' => 'nullable|email|max:190',
            'c2c_users' => 'nullable|integer|min:0|max:100000', 'smart31' => 'nullable|boolean']);
        $plan = Plan::query()->where('code', $d['plan'])->where('active', true)->firstOrFail();
        $onprem = ($d['deployment'] ?? 'cloud') === 'onprem';
        if ($msg = self::offerProblem($plan, $onprem)) {
            return response()->json(['ok' => false, 'error' => 'not_offered', 'message' => $msg], 422);
        }
        try {
            [, $pr] = Sales::quote('new', ['plan_id' => $plan->id, 'users' => $d['users'], 'billing' => $d['billing'] ?? 'annual', 'coupon' => $d['code'] ?? null,
                'c2c_users' => $d['c2c_users'] ?? null, 'smart31' => $d['smart31'] ?? null,
                'deployment' => $onprem ? 'onprem' : 'cloud', 'licence_kind' => $onprem ? 'perpetual' : 'subscription'],
                ['buyer_email' => $d['email'] ?? null, 'state_code' => $d['state_code'] ?? null]);
        } catch (DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->reason, 'message' => $e->getMessage()], 422);
        }
        $split = [];
        if ($plan->quarterlyCloud()) {   // ROVEXY Cloud: the subscription and the calling add-on shown separately (add-on = this plan − the plan without it)
            $base = Plan::query()->with('cloudTiers')->where('code', preg_replace('/-c2c(-smart31)?$/', '', $plan->code))->first();
            $baseRate = $base ? Pricing::cloudRate($base, (int) $d['users'], 'quarterly') : null;
            $rate = Pricing::cloudRate($plan->loadMissing('cloudTiers'), (int) $d['users'], 'quarterly');
            if ($baseRate !== null && $rate !== null) {
                $split = ['base_per_user_month' => $baseRate, 'addon_per_user_month' => round($rate - $baseRate, 2),
                    'base_quarter' => round($baseRate * $d['users'] * 3, 2), 'addon_quarter' => round(($rate - $baseRate) * $d['users'] * 3, 2)];
            }
        }
        return response()->json(['ok' => true] + $pr + ['per_user_month' => !$onprem ? round($pr['list_price'] / $d['users'] / Pricing::CYCLES[$pr['billing']], 2) : null] + $split,
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
        $updated = '11 November 2026';
        $gstin = $set('seller_gstin') ?: '36AAHCT0971F1ZB';
        $help = 'support@ametecsindia.com';
        $wa = $set('seller_phone') ?: '+91 90000 98877';
        $pages = [
            'privacy' => ['Privacy policy', [
                [null, "ROVEXY is a field-workforce, calling and company-phone management service built and operated by {$seller} (\"Ametecs\", \"we\"), Hyderabad, India. This policy explains plainly what data we hold and what we do with it. It covers two different things: our own records (this website, accounts, licences and billing) and the ROVEXY product your employer runs."],
                ['1. What we store for accounts and billing', [
                    'Account data: company name, contact name, work e-mail, phone and password (stored hashed, never in plain text). One-time e-mail codes are stored hashed and expire after 15 minutes.',
                    'Enquiries and app downloads: the company name, e-mail, team size, purpose and industry you give us before a trial, a demo or a Google Play download.',
                    'Billing data: orders, quotations, GST invoices, your GSTIN, state and billing address. Card, UPI and bank details are handled entirely by our payment gateway, Razorpay; we never see or store card numbers.',
                    'Licence data: plan, licensed user count, expiry, add-ons (Click2Call, Smart31) and, for on-premises installations, the licence status reported by your server. This is counting data, not your employees\' work content.',
                    'Operational logs: audit trails of console and Super Admin actions, and the service e-mails we send (codes, receipts, quotations, renewal reminders).',
                ]],
                ['2. What the ROVEXY product stores', 'Under the policy your employer sets and shows on each phone, ROVEXY records work data: the customer portfolios your employer uploads, call records and call recordings, call outcomes and follow-ups, field visits with check-in location and photos, payments and payment slips, attendance and work sessions, and phone location during a work session. It is stored where your employer chooses:'],
                [null, [
                    'ROVEXY Cloud: we host the data for your employer, keep each company\'s data separate, and process it only to provide the service. Recordings and data are kept for the retention period your employer sets in the console, then deleted.',
                    'On-premises: the data stays on your employer\'s own server and domain. Ametecs receives only the licence and the limited technical details needed for support, not your calls, recordings or locations.',
                ]],
                [null, 'ROVEXY is transparent and policy-driven, never hidden software. Your employer is the owner and controller of this data. Questions about what your employer records should go to your employer, who controls those settings.'],
                ['3. How we use and share our records', 'We use account and billing data to provide the service, issue GST invoices, send service e-mails and meet Indian tax and accounting duties. We share it only with our payment gateway (to take your payment), our infrastructure providers (to run the service), Google Maps services (to turn customer addresses into map locations and routes) and authorities where the law requires. We do not sell personal data, ever, and we do not use it for anyone else\'s marketing.'],
                ['4. Retention and deletion', 'Active account data is kept while your licence is live. Trial and expired workspaces are not deleted automatically on expiry; we delete them on request, or after we tell you in advance. Invoices and tax records are kept for 8 years as Indian law requires. You may ask us to delete your account data (except records we must keep) by writing to ' . $help . '.'],
                ['5. Security', 'Passwords and e-mail codes are hashed, sign-in is rate-limited and every sign-in is recorded, all traffic uses HTTPS, Ametecs staff open a workspace only to give support or when the law requires it, and every billing action is logged.'],
                ['6. Your rights and contact', "You can view and correct your company and billing details in the console. For access, correction or deletion, or any privacy concern, contact our grievance contact: {$help}, {$seller}, {$set('seller_address')}. We reply within 7 working days. Employees and customers of a client company should first contact that company, which controls its data. This policy is governed by Indian law, including the Digital Personal Data Protection Act, 2023."],
                ['7. ROVEXY mobile app (for employees)', 'ROVEXY (Android, in.ametecs.managedmobile) is the work app for your employer\'s staff. It works only with an account your employer creates and talks only to your employer\'s ROVEXY server. Before work starts, the app shows what your company can see, and a ROVEXY notification is shown whenever location, a call or a session is active.'],
                [null, [
                    'Calls: the app places customer calls from your work cases. It reads the call log for the duration of those calls and, where your employer records calls, uploads the recording of that customer call. Personal calls are not read.',
                    'Location: read only during a work session, between sign-in and sign-out, at the interval your employer sets, to show field visits and routes. The Google Play version does not track location in the background; with the separate direct-install version for all-day field staff, a notice stays on screen the whole time location is collected.',
                    'Camera and photos: used for visit and payment-slip photos you take, and for a camera session only when your employer requests one and you accept (the Android camera indicator is shown).',
                    'Screen sharing: only when your employer requests it and you accept; Android asks for your permission each time.',
                    'Account and device: your employee name, a random installation ID and the phone model, used to approve this phone for your account.',
                ]],
                [null, 'All app traffic is encrypted (HTTPS). The app contains no ads and no analytics trackers, and the data is not sold or shared with third parties. Your employer controls retention and deletion; ask your employer\'s administrator, or write to ' . $help . '. Signing out or uninstalling removes the app\'s data from the phone.'],
                ['8. ROVEXY Admin mobile app', 'ROVEXY Admin (Android, in.ametecs.rovexy.admin) lets your organisation\'s administrators and managers use the ROVEXY console on a phone. It signs in with an existing console account and talks only to your organisation\'s ROVEXY server.'],
                [null, [
                    'What it sends: your sign-in e-mail and password (to start a session), messages you write to employees, and records you add or change in the console.',
                    'What it shows: the work data ROVEXY already holds under your organisation\'s policy (section 2), such as calls and recordings, customers, field visits, payments and reports.',
                    'On the phone: it collects no location, camera, contacts or files. The microphone is used only for an internet call you start from Messages. Exported files are saved only where you choose.',
                ]],
                [null, 'The app contains no ads and no analytics trackers, and the data is not sold or shared. Administrators must use ROVEXY only under a policy disclosed to their employees, as the law requires.'],
            ]],
            'terms' => ['Terms of service', [
                [null, "These terms govern the use of ROVEXY, the field-workforce, calling and company-phone management service provided by {$seller} (\"Ametecs\"), Kondapur, Hyderabad, Telangana, GSTIN {$gstin}. By starting a trial, buying a licence or using the console or apps, you accept them on behalf of your organisation."],
                ['1. The service and licence types', [
                    'ROVEXY Cloud: hosted by Ametecs, priced per user per month and billed in advance for the period shown at purchase, with the Google Maps option and add-ons (Click2Call, Smart31 number rotation) you choose. The licence is valid for the paid period and renews only when a renewal payment is made.',
                    'On-premises: installed on your own server with your own domain and SSL, with a refundable deposit and a one-time installation, setup and training fee per field user, as quoted. Support and updates are included for the first 12 months, followed by an optional Annual Maintenance Contract (AMC). Google Maps on-premises runs on your own Google account and is billed to you by Google.',
                    'Free trial: a full-feature ROVEXY Cloud trial for the days and users shown at sign-up, with no card required.',
                ]],
                [null, 'A licence covers the number of users shown on the order and may be used only by the organisation it is issued to; it is not transferable. Prices are in Indian rupees and exclude GST.'],
                ['2. Payments, taxes and invoices', "Payments are processed by Razorpay (UPI, cards, NetBanking); bank transfer is also accepted and recorded manually. GST is added at the prevailing rate (currently 18%, SAC 997331) and a GST tax invoice is issued when payment is received. Quotations are valid for 15 days. Refunds are governed by our Refund policy."],
                ['3. Your responsibilities as an employer', 'ROVEXY is a transparent, policy-driven work tool; it is never covert surveillance software. You are responsible for using it lawfully: on company phones or phones your employees have agreed to use for work, telling your employees and customers what is recorded, obtaining any consent the law requires for call recording and location, and setting policies in proportion. You must not use it on people outside an employment or work relationship. You are responsible for your billing details (including GSTIN, if you wish to claim input tax credit) and for all activity under your console logins.'],
                ['4. Data', 'You own your data. On ROVEXY Cloud we host it for you as described in the Privacy policy, keep it secure and back it up, and keep it for the retention period you set. On-premises, the data stays on your server and is your responsibility. Data is not deleted on expiry. We may suspend a workspace with overdue invoices after notice.'],
                ['5. Renewal and expiry', 'Renewals extend from the current expiry date, so renewing early never loses days. After expiry there are a few days\' grace; after that, phones cannot start work until the licence is renewed.'],
                ['6. Support, availability and updates', "Support is provided on working days by WhatsApp ({$wa}) and e-mail ({$help}). We aim for high availability of ROVEXY Cloud but do not warrant uninterrupted operation; planned maintenance is announced in advance. Updates are included in active Cloud licences and in the first year or a valid AMC for on-premises."],
                ['7. Liability', 'To the maximum extent permitted by Indian law, Ametecs\' total liability under these terms is limited to the fees you paid in the 12 months before the claim, and we are not liable for indirect or consequential losses, or for decisions you take based on ROVEXY data. Nothing limits liability that cannot be limited under law.'],
                ['8. Termination and changes', 'You may stop using the service at any time; paid periods run to their end as set out in the Refund policy. We may terminate for material breach (including unlawful recording or tracking, or licence misuse) after notice. We may update these terms; material changes are notified by e-mail or console notice 15 days in advance, and continued use means acceptance.'],
                ['9. Governing law', "These terms are governed by the laws of India; courts at Hyderabad, Telangana have exclusive jurisdiction. Legal notices: {$help}."],
            ]],
            'refund' => ['Refund & cancellation policy', [
                [null, 'We keep refunds simple and honest. ROVEXY offers a free trial so you can try everything before paying, but if a first purchase still does not work out, this policy has you covered.'],
                ['1. 7-day money-back on your first Cloud purchase', 'If you are not satisfied with your first ROVEXY Cloud purchase, write to us within 7 days of payment and we refund the full amount. The licence is stopped on refund.'],
                ['2. On-premises deposit', 'The per-user deposit for an on-premises installation is refundable when your organisation stops using ROVEXY and the licence is closed. The one-time installation, setup and training fee is refunded only if no installation work has started.'],
                ['3. What is not refundable', [
                    'Renewals: a renewal extends a service you have already used and is not refundable. To stop, simply do not renew; there is no auto-charge, and your licence runs to the end of the paid period.',
                    'Used periods: months or days already used are not refunded.',
                    'Add-ons and usage: Click2Call and Smart31 add-ons for a period already started, and Google Maps usage already consumed, are not refundable.',
                    'AMC: covers a service year and is not refundable after it starts.',
                ]],
                ['4. Pro-rata refunds', 'Outside the cases above (for example, a genuine technical failure on our side that we cannot fix), Ametecs may at its discretion refund the unused part of a paid period. Duplicate payments for the same order are always refunded in full.'],
                ['5. How to request a refund', "E-mail {$help} (or WhatsApp {$wa}) from your registered e-mail with your order or invoice number and the reason. We acknowledge within 2 working days."],
                ['6. How refunds are paid', 'Approved refunds go back to the original payment method; Razorpay refunds usually reach you in 5 to 10 working days depending on your bank. Bank-transfer payments are refunded to the paying account. Where GST has been paid, a credit note is issued against the original tax invoice.'],
            ]],
            'delivery' => ['Service delivery policy', [
                ['How the service is delivered', 'ROVEXY is software delivered online. Nothing is shipped physically.'],
                ['When', 'A new ROVEXY Cloud workspace is created automatically as soon as payment is confirmed, and the sign-in link and details are e-mailed to the buyer. Renewals and upgrades apply to the existing workspace immediately. On-premises installations are scheduled with you once payment is received.'],
                ['If something goes wrong', "If you have paid and have not received your workspace e-mail within one hour, write to {$help} with your order number."],
            ]],
            'contact' => ['Contact us', [
                ['Sales and demos', 'Tell us your team size and we will call you back within one working day.'],
                ['Support', "Existing customers can reach us on working days by WhatsApp ({$wa}) or e-mail ({$help})."],
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

    /**
     * What the website sells: ROVEXY Cloud (quarterly cloud plans) and on-premises on the other plans. Existing cloud
     * licences on the older plans still renew from their console; staff can still quote anything from Super Admin.
     */
    private static function offerProblem(Plan $plan, bool $onprem): ?string
    {
        return match (true) {
            $onprem && $plan->quarterlyCloud() => 'This is a ROVEXY Cloud plan. For your own server choose an on-premises plan.',
            !$onprem && !$plan->quarterlyCloud() => 'ROVEXY Cloud is now sold with a Google Maps option. Choose Cloud on the pricing page.',
            default => null,
        };
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
