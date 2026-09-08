<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enrollment audit log — one row per enrollment event (start, step completion, failure, rollback).
     */
    public function up(): void
    {
        Schema::create('enrollment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('enrolled_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('session_token', 64)->index();
            $table->string('step', 50);                 // user_info, pin, fingerprint, rfid, commit, cancel
            $table->string('status', 30);               // started, completed, failed, rolled_back
            $table->json('payload')->nullable();         // relevant IDs / UIDs returned by ESP32
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_logs');
    }
};
