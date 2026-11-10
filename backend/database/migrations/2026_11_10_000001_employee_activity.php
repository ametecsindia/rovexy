<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Employee activity reports: one summary row per employee-day (built by App\Services\Activity\DayBuilder) + office locations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_activity', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('work_date');
            $t->timestamp('first_login_at')->nullable();
            $t->timestamp('last_logout_at')->nullable();
            $t->boolean('no_signout')->default(false);
            foreach (['signed_in_s', 'worked_s', 'productive_s', 'active_s', 'customer_s', 'travel_s', 'break_s', 'idle_s', 'offline_s', 'office_s',
                'longest_idle_s', 'talk_s'] as $c) {
                $t->unsignedInteger($c)->default(0);
            }
            $t->unsignedSmallInteger('idle_sessions')->default(0);
            $t->unsignedTinyInteger('productivity_pct')->nullable();
            $t->unsignedTinyInteger('coverage_pct')->nullable();
            $t->unsignedInteger('distance_m')->default(0);
            foreach (['calls', 'connected', 'visits', 'case_updates', 'planned_stops', 'planned_visited'] as $c) {
                $t->unsignedSmallInteger($c)->default(0);
            }
            $t->unsignedSmallInteger('late_min')->default(0);
            $t->unsignedSmallInteger('early_min')->default(0);
            $t->boolean('scheduled')->default(false);             // a working day in the company's work hours
            $t->string('attendance', 12);                          // present | half_day | short | absent | off | worked_off
            $t->boolean('final')->default(false);                  // false while the day is today
            $t->timestamp('built_at');
            $t->unique(['user_id', 'work_date']);
            $t->index(['company_id', 'work_date']);
        });
        Schema::table('branches', function (Blueprint $t) {
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->unsignedSmallInteger('radius_m')->default(150);
        });
        Schema::table('activity_events', function (Blueprint $t) {
            $t->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_events', fn (Blueprint $t) => $t->dropIndex(['user_id', 'occurred_at']));
        Schema::table('branches', fn (Blueprint $t) => $t->dropColumn(['lat', 'lng', 'radius_m']));
        Schema::dropIfExists('daily_activity');
    }
};
