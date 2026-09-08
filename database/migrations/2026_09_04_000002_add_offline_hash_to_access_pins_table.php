<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A fast, syncable hash so the ESP32 can verify a keypad PIN entirely
     * offline. Separate from `pin_code` (bcrypt, used for the online path)
     * because bcrypt is deliberately too slow to recompute per-request on a
     * microcontroller and Laravel never has the plaintext PIN again once
     * bcrypt has hashed it — this column is computed once, at the same
     * moment the plaintext is available, alongside the bcrypt hash.
     */
    public function up(): void
    {
        Schema::table('access_pins', function (Blueprint $table) {
            $table->string('offline_hash', 64)->nullable()->after('pin_code');
        });
    }

    public function down(): void
    {
        Schema::table('access_pins', function (Blueprint $table) {
            $table->dropColumn('offline_hash');
        });
    }
};
