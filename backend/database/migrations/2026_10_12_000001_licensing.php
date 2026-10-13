<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Caller platform side (12 Oct 2026): Super Admin staff, plans, licences, sales orders (Razorpay), leads, platform settings
 * and platform audit. None of these tables are tenant-scoped. Value sets (roles, statuses) live in code, columns are VARCHAR.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $t) {
            $t->string('role', 30)->default('super')->after('password');     // super | sales | support
            $t->string('status', 20)->default('active')->after('role');
            $t->timestamp('last_login_at')->nullable();
        });
        Schema::create('platform_settings', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->text('value')->nullable();
            $t->timestamps();
        });
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 80);
            $t->string('tagline', 190)->nullable();
            $t->decimal('price_inr', 12, 2)->nullable();          // fixed package price before GST; NULL = custom quote ("Contact sales")
            $t->unsignedSmallInteger('months')->default(12);       // package duration
            $t->unsignedInteger('max_users');                      // users (sign-ins) included in the package
            $t->json('features');                                  // feature keys switched on (Licensing::FEATURES)
            $t->string('deployment', 10)->default('both');         // cloud | onprem | both
            $t->boolean('is_public')->default(true);               // shown on the website
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });
        Schema::create('licences', function (Blueprint $t) {
            $t->id();
            $t->string('key', 40)->unique();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();   // cloud workspace; NULL for on-premise
            $t->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $t->string('holder_name', 150);
            $t->string('holder_email', 190)->nullable();
            $t->string('kind', 20);                                // trial | package | internal
            $t->string('deployment', 10);                          // cloud | onprem
            $t->unsignedInteger('max_users');                      // 0 = unlimited (internal only)
            $t->json('features');
            $t->string('status', 20)->default('active');           // active | suspended | revoked | superseded
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('expires_at')->nullable();               // NULL = no expiry (internal)
            $t->unsignedSmallInteger('grace_days')->default(7);
            $t->string('server_fingerprint', 64)->nullable();      // on-premise: bound on first validation
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('last_validated_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });
        Schema::create('licence_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('licence_id')->constrained()->cascadeOnDelete();
            $t->string('type', 40);
            $t->json('data')->nullable();
            $t->string('actor', 120)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['licence_id', 'created_at']);
        });
        Schema::create('sales_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->string('kind', 20);                                // new | renewal | upgrade
            $t->foreignId('plan_id')->constrained();
            $t->foreignId('licence_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->string('deployment', 10);
            $t->string('buyer_company', 150);
            $t->string('buyer_name', 150);
            $t->string('buyer_email', 190);
            $t->string('buyer_phone', 20)->nullable();
            $t->string('buyer_gstin', 15)->nullable();
            $t->char('state_code', 2)->nullable();
            $t->string('workspace', 63)->nullable();              // cloud, new buyer: wanted workspace name
            $t->string('admin_password')->nullable();             // cloud, new buyer: bcrypt hash, cleared once provisioned
            $t->decimal('amount', 12, 2);                          // before GST
            $t->decimal('cgst', 12, 2)->default(0);
            $t->decimal('sgst', 12, 2)->default(0);
            $t->decimal('igst', 12, 2)->default(0);
            $t->decimal('total', 12, 2);
            $t->string('status', 20)->default('created');          // created | paid | cancelled
            $t->string('payment_method', 20)->nullable();          // razorpay | bank | upi | cheque | cash
            $t->string('payment_ref', 100)->nullable();
            $t->string('razorpay_order_id', 60)->nullable()->unique();
            $t->string('razorpay_payment_id', 60)->nullable()->unique();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('provisioned_at')->nullable();
            $t->string('invoice_number', 30)->nullable()->unique();
            $t->string('created_by', 120)->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->string('company', 150)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('phone', 20)->nullable();
            $t->unsignedInteger('users')->nullable();
            $t->text('message')->nullable();
            $t->string('source', 20)->default('website');          // website | trial | manual
            $t->string('status', 20)->default('new');               // new | contacted | demo | quoted | won | lost
            $t->text('notes')->nullable();
            $t->date('follow_up_on')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
        Schema::create('platform_audit', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admin_id')->nullable();
            $t->string('action', 80);
            $t->string('subject_type', 40)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('meta')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index('created_at');
        });

        // Starting plans (prices are placeholders — set real prices in Super Admin → Plans before going live).
        $all = ['calling', 'recording', 'customers', 'payments', 'reports', 'chat', 'field_map', 'live_map', 'media', 'integrations'];
        $now = now();
        DB::table('plans')->insert([
            ['code' => 'starter', 'name' => 'Starter', 'tagline' => 'A small calling team getting organised', 'price_inr' => 25000, 'months' => 12, 'max_users' => 10,
                'features' => json_encode(['calling', 'recording', 'customers', 'payments', 'reports', 'chat']), 'deployment' => 'both', 'is_public' => true, 'active' => true, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'business', 'name' => 'Business', 'tagline' => 'Calling plus field teams on the map', 'price_inr' => 75000, 'months' => 12, 'max_users' => 50,
                'features' => json_encode(array_values(array_diff($all, ['integrations']))), 'deployment' => 'both', 'is_public' => true, 'active' => true, 'sort' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'tagline' => 'Large teams and the SmartPRS / SmartEPT link', 'price_inr' => null, 'months' => 12, 'max_users' => 500,
                'features' => json_encode($all), 'deployment' => 'both', 'is_public' => true, 'active' => true, 'sort' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
        // Existing workspaces keep working exactly as before: an internal, unlimited licence each.
        foreach (DB::table('companies')->get(['id', 'name']) as $c) {
            DB::table('licences')->insert(['key' => 'INT-' . strtoupper(bin2hex(random_bytes(8))), 'company_id' => $c->id, 'plan_id' => null, 'holder_name' => $c->name,
                'kind' => 'internal', 'deployment' => 'cloud', 'max_users' => 0, 'features' => json_encode($all), 'status' => 'active', 'starts_at' => $now,
                'expires_at' => null, 'grace_days' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        foreach (['platform_audit', 'leads', 'sales_orders', 'licence_events', 'licences', 'plans', 'platform_settings'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('platform_admins', fn (Blueprint $t) => $t->dropColumn(['role', 'status', 'last_login_at']));
    }
};
