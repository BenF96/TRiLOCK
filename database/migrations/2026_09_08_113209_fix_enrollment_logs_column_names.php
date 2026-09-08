<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The 2026_08_06_000001 migration was edited after it had already run
        // against some databases (user_id/message -> enrolled_user_id/remarks),
        // so EnrollmentLog::create() failed with "column ... does not exist" on
        // every enrollment step against those. A fresh database — a new install,
        // CI, or the test suite's sqlite :memory: — never had the drift: it
        // already gets enrolled_user_id/remarks straight from 2026_08_06_000001,
        // so guard each rename on the old column still being there.
        Schema::table('enrollment_logs', function (Blueprint $table) {
            if (Schema::hasColumn('enrollment_logs', 'user_id') && !Schema::hasColumn('enrollment_logs', 'enrolled_user_id')) {
                $table->renameColumn('user_id', 'enrolled_user_id');
            }
            if (Schema::hasColumn('enrollment_logs', 'message') && !Schema::hasColumn('enrollment_logs', 'remarks')) {
                $table->renameColumn('message', 'remarks');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enrollment_logs', function (Blueprint $table) {
            if (Schema::hasColumn('enrollment_logs', 'enrolled_user_id') && !Schema::hasColumn('enrollment_logs', 'user_id')) {
                $table->renameColumn('enrolled_user_id', 'user_id');
            }
            if (Schema::hasColumn('enrollment_logs', 'remarks') && !Schema::hasColumn('enrollment_logs', 'message')) {
                $table->renameColumn('remarks', 'message');
            }
        });
    }
};
