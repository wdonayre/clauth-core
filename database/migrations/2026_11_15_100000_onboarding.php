<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M10 (SPEC-organizations §8, §10.5): outside organizations request to join and William approves; organizations
 * prove a domain, accept the terms, can export their data and ask to be deleted (30-day grace).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_requests', function (Blueprint $table) {
            $table->id();
            $table->string('org_name', 120);
            $table->string('slug', 64);
            $table->string('website', 300)->nullable();
            $table->string('contact_name', 120);
            $table->string('contact_email', 190);
            $table->text('purpose');
            $table->string('verify_token_hash', 64)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('status', 16)->default('pending'); // pending|approved|rejected
            $table->string('reject_reason', 500)->nullable();
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'email_verified_at']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('domain', 190)->nullable()->after('slug');              // the organization's own domain
            $table->string('domain_token', 48)->nullable()->after('domain');
            $table->timestamp('domain_verified_at')->nullable()->after('domain_token');
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 16)->nullable();
            $table->ulid('terms_accepted_by')->nullable();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->ulid('deletion_requested_by')->nullable();
        });

        Schema::table('invites', function (Blueprint $table) {
            $table->string('org_role', 16)->nullable()->after('is_org_admin'); // owner|admin|developer (overrides is_org_admin)
        });
    }

    public function down(): void
    {
        Schema::table('invites', fn (Blueprint $table) => $table->dropColumn('org_role'));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['domain', 'domain_token', 'domain_verified_at',
            'terms_accepted_at', 'terms_version', 'terms_accepted_by', 'deletion_requested_at', 'deletion_requested_by']));
        Schema::dropIfExists('organization_requests');
    }
};
