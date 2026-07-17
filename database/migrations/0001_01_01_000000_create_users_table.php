<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * users — central account table (ERD: Identity & Profiles).
     * Login identifier is the unique phone number (SRS: phone-based auth).
     * NOTE: ERD names this column password_hash; we keep Laravel's
     * conventional `password` (hashed cast) — same content, framework naming.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 20)->unique();
            $table->string('password');
            $table->string('role', 20)->index(); // enum App\Enums\UserRole
            $table->string('fcm_token')->nullable();
            $table->string('profile_image_url')->nullable();
            $table->boolean('is_banned')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
