<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC-platform P5: agents (§9.3), CIBA requests, dynamic client registration (initial tokens), variables + secrets (§9.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete(); // set = a person's own agent
            $table->string('name', 80);
            $table->string('description', 190)->nullable();
            $table->string('status', 12)->default('active'); // active|suspended
            $table->ulid('created_by')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('agent_grants', function (Blueprint $table) {
            $table->foreignUlid('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignId('app_role_id')->constrained('app_roles')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['agent_id', 'app_id']);
        });
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->ulid('agent_id')->nullable()->after('organization_id')->index();
            $table->string('registration_token_hash', 64)->nullable()->after('agent_id'); // RFC 7592
        });
        Schema::create('ciba_requests', function (Blueprint $table) {
            $table->string('id', 64)->primary(); // auth_req_id
            $table->uuid('client_id')->index();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 500);
            $table->string('binding_message', 120)->nullable();
            $table->string('status', 12)->default('pending'); // pending|approved|denied|used
            $table->text('code')->nullable();          // encrypted authorization code once approved
            $table->string('verifier', 128)->nullable(); // PKCE verifier for that code
            $table->timestamp('expires_at');
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('registration_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('token_hash', 64)->unique();
            $table->unsignedInteger('uses_left')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('config_values', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('secret')->default(false);
            $table->text('value'); // encrypted when secret
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_values');
        Schema::dropIfExists('registration_tokens');
        Schema::dropIfExists('ciba_requests');
        Schema::table('oauth_clients', fn (Blueprint $t) => $t->dropColumn(['agent_id', 'registration_token_hash']));
        Schema::dropIfExists('agent_grants');
        Schema::dropIfExists('agents');
    }
};
