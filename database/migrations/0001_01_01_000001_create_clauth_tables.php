<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Clauth schema (SPEC §5) plus Passport's OAuth tables with ULID user ids.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('status', 16)->default('invited'); // active|invited|locked|disabled
            $table->timestamp('locked_until')->nullable();
            $table->unsignedSmallInteger('failed_logins')->default(0);
            $table->string('clauth_role', 16)->nullable(); // owner|org_admin|null
            $table->text('two_factor_secret')->nullable();          // encrypted
            $table->text('two_factor_recovery_codes')->nullable();  // encrypted json
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->ulid('last_login_app_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('auto_join_domain')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_org_admin')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->primary(['organization_id', 'user_id']);
        });

        Schema::create('apps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('icon_letter', 2)->nullable();
            $table->string('color', 7)->default('#22D3EE');
            $table->string('home_url')->nullable();
            $table->string('manifest_url')->nullable();
            $table->timestamp('manifest_synced_at')->nullable();
            $table->string('manifest_hash', 64)->nullable();
            $table->json('manifest_diff')->nullable();
            $table->string('status', 16)->default('not_connected'); // live|paused|not_connected
            $table->json('require_mfa_roles')->nullable();
            $table->string('login_theme', 16)->default('classic');
            $table->string('logo_path')->nullable();
            $table->string('logo_dark_path')->nullable();
            $table->string('accent_color', 7)->nullable();
            $table->timestamp('last_token_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('app_organization', function (Blueprint $table) {
            $table->foreignUlid('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->primary(['app_id', 'organization_id']);
        });

        Schema::create('app_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('key');
            $table->string('description')->nullable();
            $table->string('source', 16)->default('manual');
            $table->timestamp('deprecated_at')->nullable();
            $table->timestamps();
            $table->unique(['app_id', 'key']);
        });

        Schema::create('app_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('source', 16)->default('manual');
            $table->boolean('edited')->default(false); // admin changed it in Clauth → manifest sync won't overwrite
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['app_id', 'key']);
        });

        Schema::create('app_role_permission', function (Blueprint $table) {
            $table->foreignId('app_role_id')->constrained('app_roles')->cascadeOnDelete();
            $table->foreignId('app_permission_id')->constrained('app_permissions')->cascadeOnDelete();
            $table->primary(['app_role_id', 'app_permission_id']);
        });

        Schema::create('grants', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_role_id')->constrained('app_roles')->cascadeOnDelete();
            $table->ulid('granted_by')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->unique(['user_id', 'app_id', 'organization_id']);
        });

        Schema::create('invites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('email');
            $table->string('name');
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->json('grants')->nullable(); // [{app_id, role_id}]
            $table->string('clauth_role', 16)->nullable();
            $table->boolean('is_org_admin')->default(false);
            $table->boolean('require_mfa')->default(false);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->ulid('invited_by')->nullable();
            $table->timestamps();
            $table->index('email');
        });

        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('cookie_hash', 64);
            $table->string('user_agent', 512)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('trusted_until')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'cookie_hash']);
        });

        Schema::create('client_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_id')->index();
            $table->ulid('app_id')->nullable()->index();
            $table->json('amr')->nullable();
            $table->timestamp('auth_time')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'client_id']);
        });

        Schema::create('signing_keys', function (Blueprint $table) {
            $table->id();
            $table->string('kid', 64)->unique();
            $table->string('alg', 8)->default('RS256');
            $table->json('public_jwk');
            $table->text('public_pem');
            $table->text('private_pem'); // encrypted with APP_KEY
            $table->string('status', 16)->default('staged'); // staged|active|retired
            $table->timestamp('activates_at')->nullable();
            $table->timestamp('retires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('at')->index();
            $table->ulid('actor_user_id')->nullable()->index();
            $table->string('actor_type', 16)->default('system');
            $table->string('event', 64)->index();
            $table->string('category', 16)->index();
            $table->string('subject_type', 32)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->ulid('app_id')->nullable()->index();
            $table->ulid('organization_id')->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // ---- Passport (user_id as ULID string) ----
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->nullableMorphs('owner');
            $table->ulid('app_id')->nullable()->index();
            $table->string('type', 16)->default('web'); // web|public|m2m
            $table->string('name');
            $table->string('secret')->nullable();
            $table->string('provider')->nullable();
            $table->text('redirect_uris');
            $table->json('post_logout_redirect_uris')->nullable();
            $table->text('grant_types');
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamp('secret_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->string('user_id', 26)->index();
            $table->foreignUuid('client_id');
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->string('user_id', 26)->nullable()->index();
            $table->foreignUuid('client_id')->index();
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->char('access_token_id', 80)->index();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('oauth_device_codes', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->string('user_id', 26)->nullable()->index();
            $table->foreignUuid('client_id')->index();
            $table->char('user_code', 8)->unique();
            $table->text('scopes');
            $table->boolean('revoked');
            $table->dateTime('user_approved_at')->nullable();
            $table->dateTime('last_polled_at')->nullable();
            $table->dateTime('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach ([
            'oauth_device_codes', 'oauth_refresh_tokens', 'oauth_access_tokens', 'oauth_auth_codes', 'oauth_clients',
            'settings', 'audit_events', 'signing_keys', 'client_sessions', 'user_devices', 'invites', 'grants',
            'app_role_permission', 'app_roles', 'app_permissions', 'app_organization', 'apps', 'organization_user',
            'organizations', 'users',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
