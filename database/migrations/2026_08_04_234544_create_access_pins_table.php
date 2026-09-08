<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PIN authentication credentials — one or more PINs per user.
     * PINs are stored as bcrypt hashes so they are never exposed in
     * the database or logs.
     */
    public function up(): void
    {
        Schema::create('access_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('pin_code', 255); // bcrypt hash of the numeric PIN
            $table->string('status', 20)->default('active');
            $table->timestampTz('assigned_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_pins');
    }
};
