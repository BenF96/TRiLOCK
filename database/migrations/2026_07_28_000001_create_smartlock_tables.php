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
        // Modify default users table if needed or drop/recreate to include TRILOCK fields
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'employee_student_id')) {
                    $table->string('employee_student_id')->nullable()->after('id');
                    $table->string('first_name')->nullable()->after('employee_student_id');
                    $table->string('middle_name')->nullable()->after('first_name');
                    $table->string('last_name')->nullable()->after('middle_name');
                    $table->string('photo')->nullable()->after('last_name');
                    $table->string('role')->default('student')->after('email'); // admin, student, faculty, lab_tech
                    $table->string('status')->default('active')->after('role'); // active, inactive
                }
            });
        }

        Schema::create('rfid_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('card_uid')->unique();
            $table->string('status')->default('active'); // active, disabled, lost
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fingerprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('fingerprint_id')->comment('Sensor ID location (1-127)');
            $table->string('status')->default('active'); // active, disabled
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_name');
            $table->string('device_type'); // esp32_cam, esp32_main
            $table->string('ip_address')->nullable();
            $table->string('mac_address')->nullable();
            $table->string('status')->default('online'); // online, offline
            $table->timestamp('last_seen')->nullable();
            $table->timestamps();
        });

        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('authentication_method'); // rfid, fingerprint, rfid_fingerprint, unauthorized, manual_remote
            $table->string('access_type'); // entry, exit, denied
            $table->string('captured_image')->nullable();
            $table->string('door_status')->default('locked');
            $table->date('date');
            $table->time('time');
            $table->foreignId('device_id')->nullable()->constrained('devices')->onDelete('set null');
            $table->timestamps();
        });

        Schema::create('door_logs', function (Blueprint $table) {
            $table->id();
            $table->string('status'); // unlocked, locked, left_open, emergency_unlock
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->integer('door_unlock_duration')->default(5); // seconds
            $table->boolean('auto_lock')->default(true);
            $table->boolean('camera_enabled')->default(true);
            $table->boolean('capture_entry')->default(true);
            $table->string('lab_name')->default('Com Lab 1');
            $table->string('lab_room')->default('Lab 402');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('door_logs');
        Schema::dropIfExists('access_logs');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('fingerprints');
        Schema::dropIfExists('rfid_cards');
        
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn([
                    'employee_student_id',
                    'first_name',
                    'middle_name',
                    'last_name',
                    'photo',
                    'role',
                    'status'
                ]);
            });
        }
    }
};
