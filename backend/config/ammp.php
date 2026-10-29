<?php

return [
    'product_name' => env('APP_PRODUCT_NAME', 'ROVEXY'),
    'employee_app_package' => env('AMMP_APP_PACKAGE', 'in.ametecs.managedmobile'),

    'tenancy' => [
        // 'shared' (SaaS: resolve from verified domain or workspace slug) | 'fixed' (on-prem single company)
        'mode' => env('TENANCY_MODE', 'shared'),
        'fixed_company_id' => env('TENANCY_FIXED_COMPANY_ID') ? (int) env('TENANCY_FIXED_COMPANY_ID') : null,
        // APP_ENV=local only: workspace used for http://127.0.0.1 / localhost requests that carry no X-Workspace.
        'local_workspace' => env('TENANCY_LOCAL_WORKSPACE', 'demo-collections'),
    ],

    // Licensing. central = this server sells and issues licences (our cloud / Super Admin). client = an on-prem install:
    // it checks its licence with LICENCE_SERVER_URL (or a signed licence file) and has no Super Admin, website or payments.
    'licence' => [
        'mode' => env('LICENCE_MODE', 'central'),
        'server_url' => rtrim((string) env('LICENCE_SERVER_URL', ''), '/'),
        'private_key' => env('LICENCE_PRIVATE_KEY', storage_path('app/keys/licence-private.pem')),   // central only, never shipped
        'public_key' => env('LICENCE_PUBLIC_KEY', resource_path('licence/public.pem')),            // shipped with every install
        'evaluation_days' => 14,       // a fresh on-prem install with no key yet
        'unverified_days' => 7,        // key entered but the licence server not reached yet
        'recheck_hours' => 24,
        // An online licence must reach the licence server at least this often (a revoked or unpaid licence can't be kept alive
        // by blocking the network). Licence files are exempt. 0 = never expire.
        'offline_days' => (int) env('LICENCE_OFFLINE_DAYS', 30),
        'machine_id' => env('LICENCE_MACHINE_ID'),   // optional override (containers without a stable machine id)
    ],

    // On-premises self-update (Licence page → Check for update). Paths only needed when they can't be found automatically.
    'updates' => [
        'php_binary' => env('ROVEXY_PHP_BINARY'),   // PHP CLI, e.g. C:\laragon\bin\php\php-8.3\php.exe
        'mysqldump' => env('ROVEXY_MYSQLDUMP'),     // database backup before an update
        'mysql' => env('ROVEXY_MYSQL'),             // database restore if an update fails
        'auto_check' => (bool) env('ROVEXY_UPDATE_AUTO_CHECK', true),   // ask once a day and show "update available"
    ],

    'sessions' => [
        'device_token_ttl' => 900,          // 15 min
        'work_access_ttl' => 900,           // 15 min
        'work_refresh_ttl' => 43200,        // 12 h
        'admin_absolute_hours' => 12,
        // Authenticator (TOTP) step at sign-in — client console, Super Admin and Accounts. Off (email + password only) unless
        // ADMIN_MFA_REQUIRED=true; when off, codes are never asked, even for accounts that enrolled earlier.
        'admin_mfa_required' => (bool) env('ADMIN_MFA_REQUIRED', false),
        'ws_revalidate_seconds' => 60,      // documented WebSocket revocation bound
    ],

    'rate_limits' => [
        'register_per_ip_per_hour' => 20,
        'login_per_device_per_minute' => 5,
        'status_poll_per_device_per_minute' => 12,
    ],

    'management' => [
        // 'amapi' (Android Management API, production) | 'app_only' (no device-level enforcement; reported honestly)
        'driver' => env('MANAGEMENT_DRIVER', 'app_only'),
        'amapi' => [
            'enterprise' => env('AMAPI_ENTERPRISE_NAME'),           // enterprises/LC0xxxxx (per company — stored on companies.amapi_enterprise when multi-tenant)
            'service_account_json' => env('AMAPI_SERVICE_ACCOUNT_JSON_PATH'),
            'pubsub_audience' => env('AMAPI_PUBSUB_AUDIENCE'),
        ],
    ],

    'telephony' => [
        // How employees reach customers:
        //  'sim'    — the phone dials from its own SIM; the app reports duration from the call log and uploads the
        //             phone's built-in call-recorder file. Needs no telephony server. Recording quality depends on the OEM recorder.
        //  'server' — server-originated calls through the driver below (Exotel cloud telephony or FreeSWITCH), recorded centrally.
        'route' => env('CALLING_ROUTE', 'sim'),
        // Routes the employee may choose between on each call, e.g. "sim,server" (SIM call or Click2Call). The first is
        // the default for app builds that don't offer a choice. Empty = only CALLING_ROUTE (see CallService::routes()).
        'routes' => array_values(array_filter(array_map('trim', explode(',', (string) env('CALLING_ROUTES', ''))),
            fn ($v) => in_array($v, ['sim', 'server'], true))),
        // SIM route recorder: 'phone' = the phone's own dialer auto-records (both voices, after answer) and the app uploads
        // that file; 'app' = this app is the dialer and records the mic itself (Android blocks the customer's voice).
        'sim_recorder' => env('SIM_RECORDER', 'phone'),
        // 'click2call' | 'exotel' | 'freeswitch' | 'simulator' (refuses to run unless APP_ENV=local or testing)
        'driver' => env('TELEPHONY_DRIVER', 'freeswitch'),
        // Exotel click-to-call: rings the employee's work number, then the customer; recorded on Exotel, copied here.
        'exotel' => [
            'sid' => env('EXOTEL_SID'),
            'api_key' => env('EXOTEL_API_KEY'),
            'api_token' => env('EXOTEL_API_TOKEN'),
            'subdomain' => env('EXOTEL_SUBDOMAIN', 'api.in.exotel.com'),   // Mumbai cluster; Singapore = api.exotel.com
            'caller_id' => env('EXOTEL_CALLER_ID'),                          // your ExoPhone (virtual number)
            'call_type' => env('EXOTEL_CALL_TYPE', 'trans'),
            'recording_channels' => env('EXOTEL_RECORDING_CHANNELS', 'dual'),
            'ring_timeout' => (int) env('EXOTEL_RING_TIMEOUT', 30),
            'time_limit' => (int) env('EXOTEL_TIME_LIMIT', 3600),
            'timezone' => env('EXOTEL_TIMEZONE', 'Asia/Kolkata'),            // timezone of Exotel's StartTime/EndTime
        ],
        // Click2Call (Telozar start + Ametecs OBD gateway for result/recording, as in LeadGER): rings the employee's work
        // number, then the customer from the company VMN; recorded by the provider, copied here.
        'click2call' => [
            'start_url' => env('C2C_START_URL', 'https://sip.telozar.com/api/webrtc/c2c_call'),
            'cdr_url' => env('C2C_CDR_URL', 'https://obd.ametecsindia.com/admin/click2call_data'),
            'gateway_secret' => env('C2C_GATEWAY_SECRET'),
            'did' => env('C2C_DID'),                                     // the company VMN shown to customers
            'agent_caller_id' => env('C2C_AGENT_CALLER_ID'),             // number the employee is rung from, if not the VMN
            'webhook_secret' => env('C2C_WEBHOOK_SECRET'),               // required on the provider callback when set
            'timeout' => (int) env('C2C_TIMEOUT', 15),
            'timezone' => env('C2C_TIMEZONE', 'Asia/Kolkata'),           // timezone of the gateway's dmYHis times
            // Add Voucher: vouchers/LCs are created in the Ametecs CRM (lc.ametecsindia.com). GET {licence_url}/api/did_voucher/{code}/{crm_id}, header Secret-Key.
            'licence_url' => env('C2C_LICENSE_URL', 'https://lc.ametecsindia.com'),
            'licence_secret' => env('C2C_LICENSE_SECRET'),
            'crm_id' => env('C2C_CRM_ID'),                                 // default; a company can set its own in Calling options
            'daily_limit' => (int) env('C2C_DAILY_LIMIT_PER_NUMBER', 100), // Click2Call attempts per customer number per day (LeadGER)
            'max_users' => (int) env('C2C_MAX_USERS', 0),                  // licensed Click2Call users per company, 0 = no limit
            'webhook_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('C2C_WEBHOOK_IPS', ''))))),
            'recording_hosts' => array_values(array_filter(array_map(fn ($h) => strtolower(trim($h)), explode(',', (string) env('C2C_RECORDING_HOSTS', ''))))),
            'recording_wait_minutes' => 60,                                // no recording this long after the call → failed (LeadGER 60)
        ],
        'freeswitch' => [
            'host' => env('FREESWITCH_ESL_HOST', '127.0.0.1'),
            'port' => (int) env('FREESWITCH_ESL_PORT', 8021),
            'password' => env('FREESWITCH_ESL_PASSWORD'),
            'gateway' => env('FREESWITCH_GATEWAY'),
            'caller_id' => env('FREESWITCH_CALLER_ID'),
            'sip_domain' => env('FREESWITCH_SIP_DOMAIN'),
            'recordings_base' => env('FREESWITCH_RECORDINGS_BASE', '/var/lib/freeswitch/recordings'),
            // 'local' (shared mount readable by app server) | 'http' (pull from FS host via signed URL)
            'recording_source' => env('FREESWITCH_RECORDING_SOURCE', 'local'),
            'recording_http_base' => env('FREESWITCH_RECORDING_HTTP_BASE'),
            'recording_http_secret' => env('FREESWITCH_RECORDING_HTTP_SECRET'),
        ],
        // HMAC secret for the HTTP event webhook alternative (existing Ametecs telephony systems).
        'webhook_secret' => env('TELEPHONY_WEBHOOK_SECRET'),
    ],

    'recordings' => [
        'disk' => env('RECORDINGS_DISK', 'recordings'),
        'playback_url_ttl' => 300,
        'transcode' => env('RECORDINGS_TRANSCODE', 'mp3'),     // 'none' | 'mp3' (requires ffmpeg on PATH)
        'missing_after_minutes' => 30,                          // reconciliation alert threshold
    ],

    // Field map (Google Maps Platform): keys are set in the admin console (Integrations → Google Maps). Routing is
    // always basic (no live traffic, Routes "Essentials") to keep each client's Google bill at its free allowance —
    // see App\Services\GoogleMaps. There is no .env switch for it.

    'push' => [
        // 'fcm' | 'log' (development only; refuses in production)
        'driver' => env('PUSH_DRIVER', 'log'),
        'fcm_project_id' => env('FCM_PROJECT_ID'),
        'fcm_service_account_json' => env('FCM_SERVICE_ACCOUNT_JSON_PATH'),
    ],

    'media' => [
        'turn_urls' => array_filter(explode(',', (string) env('TURN_URLS', ''))),
        'turn_secret' => env('TURN_SHARED_SECRET'),
        'stun_urls' => array_filter(explode(',', (string) env('STUN_URLS', ''))),
    ],

    'uploads' => [
        'import_max_mb' => 20,
        'branding_logo_max_kb' => 512,
    ],
];
