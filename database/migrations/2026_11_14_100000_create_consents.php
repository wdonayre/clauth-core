<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M8 (SPEC-organizations §6): a person's one-time OK for an app outside their own organizations, plus the policy
 * links outside apps must publish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('app_id')->constrained('apps')->cascadeOnDelete();
            $table->json('scopes');
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'app_id']);
        });

        Schema::table('apps', function (Blueprint $table) {
            $table->string('privacy_url', 500)->nullable()->after('home_url');
            $table->string('terms_url', 500)->nullable()->after('privacy_url');
            $table->string('support_email', 190)->nullable()->after('terms_url');
        });
    }

    public function down(): void
    {
        Schema::table('apps', fn (Blueprint $table) => $table->dropColumn(['privacy_url', 'terms_url', 'support_email']));
        Schema::dropIfExists('consents');
    }
};
