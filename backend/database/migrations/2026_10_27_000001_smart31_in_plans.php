<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Smart31 (added 21 Oct) reached no plan or licence: the 21 Oct migration added only Click2Call. Add it to
 * - plans and licences that already have every other feature (Enterprise, internal licences), and
 * - every trial licence (trials carry every feature).
 * Other plans: Super Admin → Plans & pricing → tick Smart31; that now also updates licences on the plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $others = ['calling', 'click2call', 'recording', 'customers', 'payments', 'reports', 'chat', 'field_map', 'live_map', 'media', 'integrations'];
        foreach (['plans', 'licences'] as $table) {
            $cols = $table === 'licences' ? ['id', 'features', 'kind'] : ['id', 'features'];
            DB::table($table)->get($cols)->each(function ($r) use ($table, $others) {
                $f = json_decode((string) $r->features, true) ?: [];
                $everything = array_diff($others, $f) === [];
                $trial = $table === 'licences' && in_array($r->kind, ['trial', 'internal'], true);
                if (($everything || $trial) && !in_array('smart31', $f, true)) {
                    $f[] = 'smart31';
                    DB::table($table)->where('id', $r->id)->update(['features' => json_encode(array_values($f))]);
                }
            });
        }
    }

    public function down(): void
    {
    }
};
