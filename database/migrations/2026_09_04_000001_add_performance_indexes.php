<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Postgres does not automatically index foreign-key columns the way
     * MySQL/InnoDB does, so the FK columns added by the original migrations
     * have been full-table-scanned on every join/filter since day one. This
     * only bites once access_logs/door_logs grow past a trivial row count —
     * add the indexes now so it doesn't surface as a production incident.
     */
    public function up(): void
    {
        Schema::table('access_logs', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('device_id');
            $table->index('created_at');
            $table->index(['date', 'access_type']);
        });

        Schema::table('door_logs', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('fingerprints', function (Blueprint $table) {
            // Looked up on every fingerprint access attempt.
            $table->index(['fingerprint_id', 'status']);
        });

        Schema::table('rfid_cards', function (Blueprint $table) {
            // card_uid is already unique/indexed; this covers the common
            // "active card" filter that rides along with it.
            $table->index('status');
        });

        Schema::table('access_pins', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('devices', function (Blueprint $table) {
            // Polled by the dashboard's /api/status every ~15s.
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('access_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['device_id']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['date', 'access_type']);
        });

        Schema::table('door_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('fingerprints', function (Blueprint $table) {
            $table->dropIndex(['fingerprint_id', 'status']);
        });

        Schema::table('rfid_cards', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('access_pins', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
};
