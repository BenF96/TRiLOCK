<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The CHECK constraints from database/supabase_schema.sql were narrower than what
     * the app writes, so on Postgres these requests 500'd:
     *  - POST /api/door-status with status=left_open (door-ajar alarm)
     *  - POST /api/access/offline-log (authentication_method=multi_factor_offline),
     *    which also wedged the ESP32's offline queue since it stops at the first failure.
     * The Laravel migrations never created these constraints, so SQLite/MySQL are unaffected.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE access_logs DROP CONSTRAINT IF EXISTS access_logs_authentication_method_check');
        DB::statement("ALTER TABLE access_logs ADD CONSTRAINT access_logs_authentication_method_check CHECK (authentication_method IN ('rfid', 'fingerprint', 'rfid_fingerprint', 'pin', 'multi_factor', 'multi_factor_offline', 'triple_auth', 'unauthorized', 'manual_remote'))");

        DB::statement('ALTER TABLE door_logs DROP CONSTRAINT IF EXISTS door_logs_status_check');
        DB::statement("ALTER TABLE door_logs ADD CONSTRAINT door_logs_status_check CHECK (status IN ('opened', 'closed', 'unlocked', 'locked', 'left_open'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE access_logs DROP CONSTRAINT IF EXISTS access_logs_authentication_method_check');
        DB::statement("ALTER TABLE access_logs ADD CONSTRAINT access_logs_authentication_method_check CHECK (authentication_method IN ('rfid', 'fingerprint', 'rfid_fingerprint', 'pin', 'multi_factor', 'triple_auth', 'unauthorized', 'manual_remote')) NOT VALID");

        DB::statement('ALTER TABLE door_logs DROP CONSTRAINT IF EXISTS door_logs_status_check');
        DB::statement("ALTER TABLE door_logs ADD CONSTRAINT door_logs_status_check CHECK (status IN ('opened', 'closed', 'unlocked', 'locked')) NOT VALID");
    }
};
