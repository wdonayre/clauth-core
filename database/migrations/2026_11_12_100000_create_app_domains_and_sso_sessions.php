<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An app's own sign-in address, e.g. auth.cebpos.com (one per app).
        Schema::create('app_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('app_id')->unique()->constrained('apps')->cascadeOnDelete();
            $table->string('host')->unique();
            $table->string('status', 16)->default('pending'); // pending|dns_ok|reachable|active|problem
            $table->string('verify_token', 48);
            $table->string('dns_target', 16)->nullable();     // cname|a|proxied|other
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('reachable_at')->nullable();
            $table->timestamp('active_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedSmallInteger('fail_count')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });

        // Browser sessions on different Clauth domains that belong to one sign-in, so signing out ends all of them.
        Schema::create('sso_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('family', 48)->index();
            $table->string('session_id')->index();
            $table->string('user_id', 26)->index();
            $table->string('host');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_sessions');
        Schema::dropIfExists('app_domains');
    }
};
