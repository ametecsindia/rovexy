<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calling Configuration: who may use Click2Call / Smart31 (teams or people), the company's Smart31 number pool with
 * round-robin rotation, and the company number actually used on each call. Click2Call becomes a plan feature like the
 * others (LC); existing plans and licences get it, so nothing that works today is switched off. Smart31 is not added
 * to any existing plan: Super Admin ticks it per plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calling_access', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('feature', 16);                         // click2call | smart31
            $t->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'feature', 'team_id', 'user_id']);
        });
        Schema::create('smart31_numbers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('number', 15);
            $t->string('label', 60)->nullable();
            $t->json('team_ids')->nullable();                  // null / [] = every group
            $t->boolean('active')->default(true);
            $t->date('expires_on')->nullable();
            $t->timestamp('last_used_at')->nullable();         // round-robin: the least recently used number goes next
            $t->unsignedInteger('calls')->default(0);
            $t->timestamps();
            $t->unique(['company_id', 'number']);
        });
        Schema::table('calls', function (Blueprint $t) {
            $t->string('caller_number', 15)->nullable();       // company number (VMN / Smart31) the customer saw
        });
        // Replaced by calling_access (team or person). Kept harmless if it was already added.
        if (Schema::hasColumn('users', 'c2c_allowed')) {
            $off = DB::table('users')->where('c2c_allowed', false)->pluck('id');
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('c2c_allowed'));
            if ($off->isNotEmpty()) {
                // ponytail: people switched off on the old checkbox → "selected people" mode with everyone else allowed.
                DB::table('users')->whereNotIn('id', $off)->get(['id', 'company_id'])->each(fn ($u) => DB::table('calling_access')
                    ->insert(['company_id' => $u->company_id, 'feature' => 'click2call', 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]));
                DB::table('companies')->get(['id', 'settings'])->each(function ($c) {
                    $s = json_decode((string) $c->settings, true) ?: [];
                    $s['calling_access']['click2call'] = 'selected';
                    DB::table('companies')->where('id', $c->id)->update(['settings' => json_encode($s)]);
                });
            }
        }
        foreach (['plans', 'licences'] as $table) {
            DB::table($table)->get(['id', 'features'])->each(function ($r) use ($table) {
                $f = json_decode((string) $r->features, true) ?: [];
                if (in_array('calling', $f, true) && !in_array('click2call', $f, true)) {
                    $f[] = 'click2call';
                    DB::table($table)->where('id', $r->id)->update(['features' => json_encode(array_values($f))]);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('calls', fn (Blueprint $t) => $t->dropColumn('caller_number'));
        Schema::dropIfExists('smart31_numbers');
        Schema::dropIfExists('calling_access');
    }
};
