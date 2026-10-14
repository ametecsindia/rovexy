<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Central catalogue of on-premises update packages (Super Admin → Software updates). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_updates', function (Blueprint $t) {
            $t->id();
            $t->string('version', 20)->unique();          // read from version.json INSIDE the package, never typed
            $t->string('min_version', 20)->nullable();    // lowest version allowed to jump straight to this one
            $t->string('channel', 10)->default('stable'); // stable | beta
            $t->string('title', 150)->nullable();
            $t->text('notes')->nullable();
            $t->string('filename', 190);                  // in storage/app/updates (never public/)
            $t->unsignedBigInteger('size_bytes');
            $t->string('sha256', 64);
            $t->text('signature');                        // RSA-signed {product, version, sha256} with the licence key
            $t->boolean('is_published')->default(false);
            $t->timestamp('released_at')->nullable();
            $t->string('uploaded_by', 190)->nullable();
            $t->unsignedInteger('downloads')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_updates');
    }
};
