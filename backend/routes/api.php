<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Mobile;
use App\Http\Controllers\Telephony\TelephonyWebhookController;
use App\Http\Controllers\Management\AmapiNotificationController;
use Illuminate\Support\Facades\Route;

/*
| Mobile API v1 — every route first resolves the company (no fallback).
| Layers: bootstrap (public) → device-signed → device session → work session (+ permission, + compliance).
*/
// Razorpay webhook (server-to-server, no cookies).
Route::post('webhooks/razorpay', [\App\Http\Controllers\Site\PaymentWebhookController::class, 'razorpay'])->middleware(['central', 'throttle:webhooks']);

// Licence server: on-prem installs check in here (the licence key is the credential). Central server only.
Route::post('v1/license/validate', [\App\Http\Controllers\Platform\OnPremController::class, 'validate'])->middleware(['central', 'throttle:60,1']);
Route::middleware(['central', 'throttle:30,1'])->prefix('v1/updates')->group(function () {
    Route::post('check', [\App\Http\Controllers\Platform\UpdateApiController::class, 'check']);
    Route::get('download/{token}', [\App\Http\Controllers\Platform\UpdateApiController::class, 'download'])->where('token', '[A-Za-z0-9]{48}');
});
Route::middleware(['central', 'throttle:30,1'])->prefix('v1/license')->group(function () {
    Route::post('offer', [\App\Http\Controllers\Platform\OnPremController::class, 'offer']);
    Route::post('quote', [\App\Http\Controllers\Platform\OnPremController::class, 'quote']);
    Route::post('order', [\App\Http\Controllers\Platform\OnPremController::class, 'order']);
    Route::post('cancel', [\App\Http\Controllers\Platform\OnPremController::class, 'cancelOrder']);
});

// Super Admin console API (Ametecs staff). Cookie session + CSRF like the company console, but no company: platform-wide.
Route::prefix('super')->middleware('central')->group(function () {
    $c = \App\Http\Controllers\Platform\ConsoleController::class;
    Route::post('auth/login', [\App\Http\Controllers\Platform\AuthController::class, 'login'])->middleware('throttle:admin-login');
    Route::get('auth/me', [\App\Http\Controllers\Platform\AuthController::class, 'me']);
    Route::post('auth/logout', [\App\Http\Controllers\Platform\AuthController::class, 'logout'])->middleware('platform');
    $auth = \App\Http\Controllers\Platform\AuthController::class;
    Route::post('auth/mfa/setup', [$auth, 'mfaSetup'])->middleware('throttle:admin-login');
    Route::post('auth/mfa/verify', [$auth, 'mfaVerify'])->middleware('throttle:admin-login');
    Route::post('auth/mfa/disable', [$auth, 'mfaDisable'])->middleware(['platform', 'throttle:admin-login']);
    Route::put('auth/profile', [$auth, 'profile'])->middleware('platform');
    Route::put('auth/password', [$auth, 'password'])->middleware(['platform', 'throttle:admin-login']);
    Route::get('dashboard', [$c, 'dashboard'])->middleware('platform:dashboard');
    Route::get('companies', [$c, 'companies'])->middleware('platform:companies');
    Route::post('companies', [$c, 'createCompany'])->middleware('platform:companies');
    Route::post('companies/{id}/status', [$c, 'setCompanyStatus'])->whereNumber('id')->middleware('platform:companies');
    Route::get('companies/{id}/admins', [$c, 'companyAdmins'])->whereNumber('id')->middleware('platform:companies');
    Route::get('companies/{id}', [$c, 'company'])->whereNumber('id')->middleware('platform:companies');
    Route::put('companies/{id}', [$c, 'updateCompany'])->whereNumber('id')->middleware('platform:companies');
    Route::post('companies/{id}/licence', [$c, 'issueLicence'])->whereNumber('id')->middleware('platform:licences');
    Route::post('companies/{id}/admin-password', [$c, 'resetCompanyAdminPassword'])->whereNumber('id')->middleware('platform:companies');
    Route::post('companies/{id}/invite', [$c, 'inviteCompanyAdmin'])->whereNumber('id')->middleware(['platform:companies', 'throttle:10,1']);
    Route::get('licences', [$c, 'licences'])->middleware('platform:licences');
    Route::get('licences/{id}', [$c, 'licence'])->whereNumber('id')->middleware('platform:licences');
    Route::patch('licences/{id}', [$c, 'updateLicence'])->whereNumber('id')->middleware('platform:licences');
    Route::get('plans', [$c, 'plans'])->middleware('platform:plans');
    Route::post('plans', [$c, 'savePlan'])->middleware('platform:plans');
    Route::put('plans/{id}', [$c, 'savePlan'])->whereNumber('id')->middleware('platform:plans');
    Route::delete('plans/{id}', [$c, 'deletePlan'])->whereNumber('id')->middleware('platform:plans');
    Route::get('orders', [$c, 'orders'])->middleware('platform:orders');
    Route::post('orders', [$c, 'createOrder'])->middleware('platform:orders');
    Route::post('orders/preview', [$c, 'previewOrder'])->middleware('platform:orders');
    $op = \App\Http\Controllers\Platform\OnPremController::class;   // on-prem licence: server binding, AMC, licence file
    Route::post('licences/{id}/release', [$op, 'release'])->whereNumber('id')->middleware('platform:licences');
    Route::post('licences/{id}/bind', [$op, 'bind'])->whereNumber('id')->middleware('platform:licences');
    Route::post('licences/{id}/amc', [$op, 'renewAmc'])->whereNumber('id')->middleware('platform:licences');
    Route::post('licences/{id}/file', [$op, 'file'])->whereNumber('id')->middleware('platform:licences');
    Route::get('orders.csv', [$c, 'ordersCsv'])->middleware('platform:orders');
    Route::post('orders/{id}/payment', [$c, 'recordPayment'])->whereNumber('id')->middleware('platform:orders');
    Route::post('orders/{id}/cancel', [$c, 'cancelOrder'])->whereNumber('id')->middleware('platform:orders');
    Route::delete('orders/{id}', [$c, 'deleteOrder'])->whereNumber('id')->middleware('platform:orders');
    $pr = \App\Http\Controllers\Platform\PricingController::class;   // custom (negotiated) prices: view = plans view, change = super only
    Route::get('custom-prices', [$pr, 'index'])->middleware('platform:plans');
    Route::get('custom-prices/preview', [$pr, 'preview'])->middleware('platform:plans');
    Route::get('custom-prices/{id}/history', [$pr, 'history'])->whereNumber('id')->middleware('platform:plans');
    Route::post('custom-prices', [$pr, 'save'])->middleware('platform:plans');
    Route::put('custom-prices/{id}', [$pr, 'save'])->whereNumber('id')->middleware('platform:plans');
    Route::delete('custom-prices/{id}', [$pr, 'destroy'])->whereNumber('id')->middleware('platform:plans');
    Route::get('coupons', [$c, 'coupons'])->middleware('platform:plans');
    Route::post('coupons', [$c, 'saveCoupon'])->middleware('platform:plans');
    Route::put('coupons/{id}', [$c, 'saveCoupon'])->whereNumber('id')->middleware('platform:plans');
    Route::delete('coupons/{id}', [$c, 'deleteCoupon'])->whereNumber('id')->middleware('platform:plans');
    $uc = \App\Http\Controllers\Platform\UpdateCatalogController::class;   // software updates for on-prem servers (super only)
    Route::get('updates', [$uc, 'index'])->middleware('platform:settings');
    Route::post('updates', [$uc, 'store'])->middleware('platform:settings');
    Route::put('updates/{id}', [$uc, 'update'])->whereNumber('id')->middleware('platform:settings');
    Route::post('updates/{id}/publish', [$uc, 'publish'])->whereNumber('id')->middleware('platform:settings');
    Route::delete('updates/{id}', [$uc, 'destroy'])->whereNumber('id')->middleware('platform:settings');
    Route::get('mail-log', [$c, 'mailLog'])->middleware('platform:audit');
    Route::get('leads', [$c, 'leads'])->middleware('platform:leads');
    Route::post('leads', [$c, 'saveLead'])->middleware('platform:leads');
    Route::patch('leads/{id}', [$c, 'saveLead'])->whereNumber('id')->middleware('platform:leads');
    Route::delete('leads/{id}', [$c, 'deleteLead'])->whereNumber('id')->middleware('platform:leads');
    Route::get('admins', [$c, 'admins'])->middleware('platform:admins');
    Route::post('admins', [$c, 'saveAdmin'])->middleware('platform:admins');
    Route::put('admins/{id}', [$c, 'saveAdmin'])->whereNumber('id')->middleware('platform:admins');
    Route::delete('admins/{id}', [$c, 'deleteAdmin'])->whereNumber('id')->middleware('platform:admins');
    Route::post('admins/{id}/reset-mfa', [$c, 'resetAdminMfa'])->whereNumber('id')->middleware('platform:admins');
    Route::get('settings', [$c, 'settings'])->middleware('platform:settings');
    Route::put('settings', [$c, 'saveSettings'])->middleware('platform:settings');
    Route::post('settings/test-mail', [$c, 'testMail'])->middleware(['platform:settings', 'throttle:6,1']);
    Route::get('audit', [$c, 'audit'])->middleware('platform:audit');

    // Accounts (Accountant panel at /accounts; super admins too). Ametecs' own books.
    Route::prefix('accounts')->middleware('platform:accounts')->group(function () {
        $a = \App\Http\Controllers\Platform\AccountsController::class;
        Route::get('lookups', [$a, 'lookups']);
        Route::get('dashboard', [$a, 'dashboard']);
        Route::get('transactions', [$a, 'transactions']);
        Route::get('transactions/export', [$a, 'exportTransactions'])->middleware('throttle:30,1');
        Route::post('transactions', [$a, 'createTransaction']);
        Route::put('transactions/{id}', [$a, 'updateTransaction'])->whereNumber('id');
        Route::delete('transactions/{id}', [$a, 'deleteTransaction'])->whereNumber('id');
        Route::get('categories', [$a, 'categories']);
        Route::post('categories', [$a, 'saveCategory']);
        Route::put('categories/{id}', [$a, 'saveCategory'])->whereNumber('id');
        Route::delete('categories/{id}', [$a, 'deleteCategory'])->whereNumber('id');
        Route::get('accounts', [$a, 'accounts']);
        Route::post('accounts', [$a, 'saveAccount']);
        Route::put('accounts/{id}', [$a, 'saveAccount'])->whereNumber('id');
        Route::delete('accounts/{id}', [$a, 'deleteAccount'])->whereNumber('id');
        Route::get('invoices', [$a, 'invoices']);
        Route::get('invoices/export', [$a, 'exportInvoices'])->middleware('throttle:30,1');
        Route::post('invoices', [$a, 'createInvoice']);
        Route::get('invoices/{id}', [$a, 'invoice'])->whereNumber('id');
        Route::put('invoices/{id}', [$a, 'updateInvoice'])->whereNumber('id');
        Route::delete('invoices/{id}', [$a, 'deleteInvoice'])->whereNumber('id');
        Route::post('invoices/{id}/issue', [$a, 'issueInvoice'])->whereNumber('id');
        Route::post('invoices/{id}/cancel', [$a, 'cancelInvoice'])->whereNumber('id');
        Route::post('invoices/{id}/payments', [$a, 'payInvoice'])->whereNumber('id');
        Route::get('invoices/{id}/print', [$a, 'printInvoice'])->whereNumber('id');
        Route::get('payments', [$a, 'payments']);
        Route::get('reports', [$a, 'reports']);
        Route::get('reports/export', [$a, 'exportReport'])->middleware('throttle:30,1');
        Route::get('sales-invoices', [$a, 'salesInvoices']);
    });
});

Route::get('mobile/v1/branding/{workspace}/logo', [Mobile\BootstrapController::class, 'logoFor'])->where('workspace', '[a-z0-9][a-z0-9-]{0,61}[a-z0-9]')->middleware('throttle:bootstrap');
Route::prefix('mobile/v1')->middleware('company')->group(function () {
    Route::get('bootstrap', [Mobile\BootstrapController::class, 'show'])->middleware('throttle:bootstrap');
    Route::get('branding/logo', [Mobile\BootstrapController::class, 'logo'])->middleware('throttle:bootstrap');
    Route::post('devices/register', [Mobile\DeviceController::class, 'register'])->middleware('throttle:register');

    Route::get('device/status', [Mobile\DeviceController::class, 'status'])->middleware(['device.signed:allow_inactive', 'throttle:status']);
    Route::post('device/session', [Mobile\DeviceController::class, 'session'])->middleware(['device.signed', 'throttle:status']);
    Route::post('auth/refresh', [Mobile\AuthController::class, 'refresh'])->middleware(['device.signed', 'throttle:login']);

    Route::middleware('device.session')->group(function () {
        Route::post('auth/login', [Mobile\AuthController::class, 'login'])->middleware('throttle:login');
        Route::get('policy', [Mobile\PolicyController::class, 'show']);
        Route::post('policy/ack', [Mobile\PolicyController::class, 'ack']);
        Route::put('device/push-token', [Mobile\DeviceController::class, 'pushToken']);
        Route::post('device/commands/{id}/ack', [Mobile\DeviceController::class, 'ackCommand']);
    });

    Route::middleware('work.session')->group(function () {
        Route::get('me', [Mobile\AuthController::class, 'me']);
        Route::post('auth/logout', [Mobile\AuthController::class, 'logout']);
        Route::post('sync', [Mobile\SyncController::class, 'store']);
        Route::get('conversations', [Mobile\ChatController::class, 'index'])->middleware('perm:chat.use');
        Route::get('conversations/{id}/messages', [Mobile\ChatController::class, 'messages'])->middleware('perm:chat.use');
        Route::post('conversations/{id}/messages', [Mobile\ChatController::class, 'send'])->middleware(['perm:chat.use', 'throttle:chat']);
        Route::get('conversations/{id}/messages/{messageId}/attachment', [Mobile\ChatController::class, 'attachment'])->middleware('perm:chat.use');
        Route::get('media/pending', [Mobile\MediaController::class, 'pending']);
        Route::post('media/{uuid}/{action}', [Mobile\MediaController::class, 'act'])->whereIn('action', ['accept', 'decline', 'start', 'end', 'signal', 'protected-content']);
        Route::get('media/{uuid}/ice', [Mobile\MediaController::class, 'ice']);
        Route::get('media/{uuid}/signals', [Mobile\MediaController::class, 'signals']);

        Route::middleware('work.compliant')->group(function () {
            Route::get('customers', [Mobile\CustomerController::class, 'index']);
            Route::get('customers/{id}', [Mobile\CustomerController::class, 'show'])->whereNumber('id');
            Route::post('customers/{id}/entry', [Mobile\CustomerController::class, 'entry'])->whereNumber('id')->middleware('throttle:calls');
            Route::get('dispositions', [Mobile\CustomerController::class, 'dispositions']);
            // Field map (Google Routes / Geocoding via the server key; 30 a minute is far above normal use).
            Route::get('field/config', [Mobile\FieldMapController::class, 'config'])->middleware('feature:field_map');
            Route::post('field/distances', [Mobile\FieldMapController::class, 'distances'])->middleware(['throttle:maps', 'feature:field_map']);
            Route::post('field/route', [Mobile\FieldMapController::class, 'route'])->middleware(['throttle:maps', 'feature:field_map']);
            Route::post('customers/{id}/geocode', [Mobile\FieldMapController::class, 'geocode'])->whereNumber('id')->middleware(['throttle:maps', 'feature:field_map']);
            Route::post('customers/{id}/location', [Mobile\FieldMapController::class, 'setLocation'])->whereNumber('id')->middleware(['throttle:maps', 'feature:field_map']);
            Route::post('customers/{id}/visit', [Mobile\FieldMapController::class, 'visit'])->whereNumber('id')->middleware(['throttle:maps', 'feature:field_map']);
            Route::post('calls', [Mobile\CallController::class, 'store'])->middleware(['perm:calls.make', 'throttle:calls']);
            Route::get('calls/{uuid}', [Mobile\CallController::class, 'show']);
            Route::post('calls/{uuid}/hangup', [Mobile\CallController::class, 'hangup']);
            Route::post('calls/{uuid}/sim-result', [Mobile\CallController::class, 'simResult']);
            Route::post('calls/{uuid}/recording', [Mobile\CallController::class, 'recording'])->middleware('throttle:calls');
            Route::post('calls/{uuid}/disposition', [Mobile\CallController::class, 'disposition']);
        });
    });
});

/*
| Admin API — Sanctum stateful cookie session + CSRF, company-resolved, MFA-gated, permission-gated.
*/
Route::prefix('admin')->middleware('company')->group(function () {
    Route::post('auth/login', [Admin\AuthController::class, 'login'])->middleware('throttle:admin-login');
    Route::middleware('auth:web')->group(function () {
        Route::post('auth/mfa/setup', [Admin\AuthController::class, 'mfaSetup']);
        Route::post('auth/mfa/verify', [Admin\AuthController::class, 'mfaVerify'])->middleware('throttle:admin-login');
        Route::post('auth/logout', [Admin\AuthController::class, 'logout']);
        Route::get('auth/me', [Admin\AuthController::class, 'me']);
        Route::put('auth/password', [Admin\AuthController::class, 'password'])->middleware('throttle:admin-login');
    });

    Route::middleware(['auth:web', 'mfa'])->group(function () {
        Route::get('overview', [Admin\OverviewController::class, 'show']);
        Route::get('reports/portfolio', [Admin\PortfolioReportController::class, 'show'])->middleware('perm:calls.view');
        Route::get('reports/portfolio.csv', [Admin\PortfolioReportController::class, 'export'])->middleware('perm:reports.export');
        Route::get('reports/portfolio/cases', [Admin\PortfolioReportController::class, 'cases'])->middleware('perm:calls.view');

        Route::get('employees', [Admin\PeopleController::class, 'employees'])->middleware('perm:employees.view');
        Route::post('employees', [Admin\PeopleController::class, 'createEmployee'])->middleware('perm:employees.manage');
        Route::patch('employees/{id}', [Admin\PeopleController::class, 'updateEmployee'])->middleware('perm:employees.manage');
        Route::post('employees/bulk', [Admin\PeopleController::class, 'bulkUpload'])->middleware('perm:employees.manage');
        Route::get('teams', [Admin\PeopleController::class, 'teams'])->middleware('perm:employees.view');
        Route::post('teams', [Admin\PeopleController::class, 'createTeam'])->middleware('perm:employees.manage');
        Route::patch('teams/{id}', [Admin\PeopleController::class, 'renameTeam'])->middleware('perm:employees.manage');
        Route::put('teams/{id}/members', [Admin\PeopleController::class, 'setMembers'])->middleware('perm:employees.manage');
        Route::get('roles', [Admin\PeopleController::class, 'roles'])->middleware('perm:employees.view');
        Route::post('roles', [Admin\PeopleController::class, 'saveRole'])->middleware('perm:employees.manage');
        Route::put('roles/{id}', [Admin\PeopleController::class, 'saveRole'])->middleware('perm:employees.manage');
        Route::delete('roles/{id}', [Admin\PeopleController::class, 'deleteRole'])->middleware('perm:employees.manage');

        Route::get('devices', [Admin\DeviceController::class, 'index'])->middleware('perm:devices.view');
        Route::get('devices/{id}', [Admin\DeviceController::class, 'show'])->middleware('perm:devices.view');
        Route::post('devices/{id}/approve', [Admin\DeviceController::class, 'approve'])->middleware('perm:devices.approve');
        Route::post('devices/{id}/reject', [Admin\DeviceController::class, 'reject'])->middleware('perm:devices.approve');
        Route::post('devices/{id}/reassign', [Admin\DeviceController::class, 'reassign'])->middleware('perm:devices.approve');
        Route::post('devices/{id}/revoke', [Admin\DeviceController::class, 'revoke'])->middleware('perm:devices.revoke');
        Route::post('devices/{id}/wipe', [Admin\DeviceController::class, 'wipe'])->middleware('perm:devices.wipe');
        Route::post('enrolment-tokens', [Admin\DeviceController::class, 'enrolmentToken'])->middleware('perm:devices.approve');

        Route::get('policy/catalog', [Admin\PolicyController::class, 'catalog'])->middleware('perm:policies.view');
        Route::get('policies', [Admin\PolicyController::class, 'index'])->middleware('perm:policies.view');
        Route::post('policies', [Admin\PolicyController::class, 'createProfile'])->middleware('perm:policies.edit');
        Route::post('policies/{id}/versions', [Admin\PolicyController::class, 'draft'])->middleware('perm:policies.edit');
        Route::post('policies/{id}/versions/{versionId}/publish', [Admin\PolicyController::class, 'publish'])->middleware('perm:policies.publish');
        Route::post('policies/{id}/rollback', [Admin\PolicyController::class, 'rollback'])->middleware('perm:policies.publish');
        Route::put('policy-assignments', [Admin\PolicyController::class, 'assign'])->middleware('perm:policies.publish');
        Route::get('devices/{id}/effective-policy', [Admin\PolicyController::class, 'effective'])->middleware('perm:policies.view');

        Route::get('imports/template', [Admin\CustomerController::class, 'template'])->middleware('perm:customers.import');
        Route::get('imports', [Admin\CustomerController::class, 'imports'])->middleware('perm:customers.import');
        Route::post('imports', [Admin\CustomerController::class, 'upload'])->middleware(['perm:customers.import', 'throttle:uploads']);
        Route::post('imports/{id}/preview', [Admin\CustomerController::class, 'preview'])->middleware('perm:customers.import');
        Route::post('imports/{id}/validate', [Admin\CustomerController::class, 'validateImport'])->middleware('perm:customers.import');
        Route::post('imports/{id}/commit', [Admin\CustomerController::class, 'commit'])->middleware('perm:customers.import');
        Route::patch('imports/{id}', [Admin\CustomerController::class, 'renameImport'])->middleware('perm:customers.import');
        Route::get('portfolios', [Admin\CustomerController::class, 'portfolios'])->middleware('perm:customers.view');
        Route::get('imports/{id}', [Admin\CustomerController::class, 'importStatus'])->middleware('perm:customers.import');
        Route::get('imports/{id}/errors.csv', [Admin\CustomerController::class, 'errorReport'])->middleware('perm:customers.import');
        Route::get('customers', [Admin\CustomerController::class, 'index'])->middleware('perm:customers.view');
        Route::get('customers/{id}', [Admin\CustomerController::class, 'show'])->whereNumber('id')->middleware('perm:customers.view');
        Route::post('customers/{id}/note', [Admin\CustomerController::class, 'note'])->whereNumber('id')->middleware('perm:customers.view');
        Route::post('customers/{id}/status', [Admin\CustomerController::class, 'status'])->whereNumber('id')->middleware('perm:customers.assign');
        Route::post('customers/assign', [Admin\CustomerController::class, 'assign'])->middleware('perm:customers.assign');
        Route::get('address-changes', [Admin\AddressReportController::class, 'changes'])->middleware('perm:customers.view');
        Route::get('reachability', [Admin\AddressReportController::class, 'reachability'])->middleware('perm:customers.view');
        Route::get('customers/facets', [Admin\CustomerController::class, 'facets'])->middleware('perm:customers.view');
        Route::post('customers/allocate', [Admin\CustomerController::class, 'allocate'])->middleware('perm:customers.assign');
        Route::post('customers/transfer', [Admin\CustomerController::class, 'transfer'])->middleware('perm:customers.assign');
        Route::post('customers/withdraw', [Admin\CustomerController::class, 'withdraw'])->middleware('perm:customers.assign');

        Route::get('calls', [Admin\CallController::class, 'index'])->middleware('perm:calls.view');
        Route::get('calls/{id}', [Admin\CallController::class, 'show'])->middleware('perm:calls.view');
        Route::post('calls/{id}/recording/playback', [Admin\CallController::class, 'playback'])->middleware('perm:recordings.play');
        Route::post('calls/{id}/recording/download', [Admin\CallController::class, 'download'])->middleware('perm:recordings.download');
        // ponytail: reuses settings.retention (admins have it) instead of a new permission + reseed.
        Route::delete('calls/{id}', [Admin\CallController::class, 'destroy'])->middleware('perm:settings.retention');
        Route::post('calls/{id}/recording/retry', [Admin\CallController::class, 'retry'])->middleware('perm:telephony.configure');
        Route::get('follow-ups', [Admin\CallController::class, 'followUps'])->middleware('perm:calls.view');
        Route::get('disposition-codes', [Admin\CallController::class, 'codes'])->middleware('perm:calls.view');
        // Payments reported from call feedback (9 Oct 2026): slip view, verify / reject, e-mail.
        Route::get('payments', [Admin\PaymentController::class, 'index'])->middleware('perm:calls.view');
        Route::get('payments/{id}/slip', [Admin\PaymentController::class, 'slip'])->middleware('perm:calls.view');
        Route::post('payments/{id}/review', [Admin\PaymentController::class, 'review'])->middleware('perm:payments.verify');
        Route::post('payments/{id}/email', [Admin\PaymentController::class, 'email'])->middleware('perm:payments.verify');
        Route::put('disposition-codes', [Admin\CallController::class, 'saveCodes'])->middleware('perm:telephony.configure');

        Route::get('presence', [Admin\SupervisionController::class, 'presence'])->middleware('perm:employees.view');
        Route::get('locations', [Admin\SupervisionController::class, 'locations'])->middleware('perm:location.view');
        Route::get('live-locations', [Admin\SupervisionController::class, 'live'])->middleware(['perm:location.view', 'feature:live_map']);
        Route::get('day-route', [Admin\SupervisionController::class, 'dayRoute'])->middleware(['perm:location.view', 'feature:live_map']);
        Route::get('media-sessions', [Admin\SupervisionController::class, 'mediaIndex']);
        Route::post('media-sessions', [Admin\SupervisionController::class, 'mediaRequest'])->middleware('feature:media');
        Route::post('media-sessions/{uuid}/end', [Admin\SupervisionController::class, 'mediaEnd']);
        Route::post('media-sessions/{uuid}/signal', [Admin\SupervisionController::class, 'mediaSignal']);
        Route::get('media-sessions/{uuid}/ice', [Admin\SupervisionController::class, 'mediaIce']);
        Route::get('media-sessions/{uuid}/signals', [Admin\SupervisionController::class, 'mediaSignals']);
        Route::post('media-sessions/{uuid}/screenshots', [Admin\SupervisionController::class, 'screenshot'])->middleware('perm:media.screenshot');
        Route::get('conversations', [Admin\SupervisionController::class, 'conversations'])->middleware('perm:chat.use');
        Route::get('chat/unread', [Admin\SupervisionController::class, 'unread'])->middleware('perm:chat.use');
        Route::post('chat/read-all', [Admin\SupervisionController::class, 'readAll'])->middleware('perm:chat.use');
        Route::post('conversations', [Admin\SupervisionController::class, 'startConversation'])->middleware('perm:chat.use');
        Route::get('conversations/{id}/messages', [Admin\SupervisionController::class, 'messages'])->middleware('perm:chat.use');
        Route::post('conversations/{id}/messages', [Admin\SupervisionController::class, 'send'])->middleware(['perm:chat.use', 'throttle:chat']);

        // ROVEXY plan & licence (12 Oct 2026): every admin can see it; renewing needs settings.storage (admins have it).
        Route::get('licence', [Admin\LicenceController::class, 'show']);
        Route::post('licence/renew', [Admin\LicenceController::class, 'renew'])->middleware('perm:settings.storage');
        Route::get('licence/quote', [Admin\LicenceController::class, 'quote'])->middleware('perm:settings.storage');
        Route::post('licence/key', [Admin\LicenceController::class, 'saveKey'])->middleware(['perm:settings.storage', 'throttle:10,1']);
        Route::post('licence/check', [Admin\LicenceController::class, 'checkNow'])->middleware(['perm:settings.storage', 'throttle:10,1']);
        Route::post('licence/file', [Admin\LicenceController::class, 'uploadFile'])->middleware(['perm:settings.storage', 'throttle:10,1']);
        Route::get('licence/update', [Admin\UpdateController::class, 'show'])->middleware('perm:settings.storage');
        Route::post('licence/update/check', [Admin\UpdateController::class, 'check'])->middleware(['perm:settings.storage', 'throttle:10,1']);
        Route::post('licence/update/download', [Admin\UpdateController::class, 'download'])->middleware(['perm:settings.storage', 'throttle:5,1']);
        Route::post('licence/update/install', [Admin\UpdateController::class, 'install'])->middleware(['perm:settings.storage', 'throttle:5,1']);
        Route::post('licence/orders/{number}/cancel', [Admin\LicenceController::class, 'cancel'])->middleware('perm:settings.storage');
        Route::get('branding', [Admin\SettingsController::class, 'branding']);
        Route::post('branding', [Admin\SettingsController::class, 'saveBranding'])->middleware('perm:branding.manage');
        Route::get('storage/estimate', [Admin\SettingsController::class, 'estimate'])->middleware('perm:settings.storage');
        Route::get('retention-jobs', [Admin\SettingsController::class, 'retentionJobs'])->middleware('perm:settings.retention');
        Route::post('retention-jobs', [Admin\SettingsController::class, 'planRetention'])->middleware('perm:settings.retention');
        Route::post('retention-jobs/{id}/approve', [Admin\SettingsController::class, 'approveRetention'])->middleware('perm:settings.retention');
        Route::get('audit', [Admin\SettingsController::class, 'audit'])->middleware('perm:audit.view');
        Route::get('health', [Admin\SettingsController::class, 'health'])->middleware('perm:system.health');
        // SmartPRS2 / SmartEPT field-force integration (6 Oct 2026).
        Route::get('integrations', [Admin\IntegrationController::class, 'index'])->middleware('perm:system.health');
        Route::get('integrations/maps', [Admin\IntegrationController::class, 'maps'])->middleware('perm:system.health');
        Route::put('integrations/maps', [Admin\IntegrationController::class, 'saveMaps'])->middleware('perm:telephony.configure');
        Route::put('integrations/{system}', [Admin\IntegrationController::class, 'save'])->whereIn('system', ['smartprs', 'smartept'])->middleware(['perm:telephony.configure', 'feature:integrations']);
        Route::post('integrations/{system}/test', [Admin\IntegrationController::class, 'test'])->whereIn('system', ['smartprs', 'smartept'])->middleware('perm:system.health');
        Route::post('integrations/flush', [Admin\IntegrationController::class, 'flush'])->middleware('perm:telephony.configure');
        Route::get('reports/calls.csv', [Admin\SettingsController::class, 'exportCalls'])->middleware('perm:reports.export');
    });
});

// Short-lived signed recording stream (local storage driver). URL minted only after a scoped, audited lookup.
Route::get('admin/recordings/{id}/stream', [Admin\CallController::class, 'stream'])->name('recordings.stream')->middleware(['company', 'signed']);

// Telephony HMAC webhook (alternative to the ESL listener for existing Ametecs telephony systems). No tenant guessing:
// the company is taken from the call record the event references.
Route::post('telephony/v1/events', [TelephonyWebhookController::class, 'store'])->middleware('throttle:telephony');
// Exotel status callback: signed URL per call; the body is ignored — it only triggers a read from Exotel's API.
Route::post('telephony/v1/exotel/{uuid}', function (string $uuid, \App\Services\CallService $svc) {
    $ctx = app(\App\Support\CurrentCompany::class);
    $call = $ctx->withoutScope(fn () => \App\Models\Call::query()->where('uuid', $uuid)->where('provider', 'exotel')->first());
    if ($call) {
        $ctx->run(\App\Models\Company::query()->findOrFail($call->company_id), fn () => $svc->syncProvider($call));
    }
    return response()->json(['ok' => true]);
})->name('telephony.exotel')->middleware(['signed', 'throttle:telephony']);
// AMAPI Pub/Sub push notifications (OIDC-verified).
Route::post('amapi/v1/notifications', [AmapiNotificationController::class, 'store']);
