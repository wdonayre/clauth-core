<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M9 (SPEC-organizations §7.3, §10): per-organization limits, suspension of organizations and apps, per-domain
 * certificate state, and the "Report this page" queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('verified_at');
            $table->string('suspend_reason', 500)->nullable()->after('suspended_at');
            $table->json('limits')->nullable()->after('suspend_reason'); // overrides of the defaults in config/clauth.php
        });
        Schema::table('apps', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('visibility');
            $table->string('suspend_reason', 500)->nullable()->after('suspended_at');
        });
        Schema::table('app_domains', function (Blueprint $table) {
            // Per-domain certificates (cert_mode = per-host): the server job reports each host's result here.
            $table->string('cert_status', 16)->nullable()->after('status'); // issued|failed
            $table->timestamp('cert_issued_at')->nullable()->after('cert_status');
            $table->string('cert_error', 500)->nullable()->after('cert_issued_at');
        });
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('host');
            $table->string('url', 1000)->nullable();
            $table->foreignUlid('app_id')->nullable()->constrained('apps')->nullOnDelete();
            $table->string('reason', 32); // phishing|impersonation|spam|other
            $table->text('details')->nullable();
            $table->ulid('reporter_user_id')->nullable();
            $table->string('reporter_ip_hash', 64)->nullable();
            $table->string('status', 16)->default('open'); // open|closed
            $table->ulid('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
        Schema::table('app_domains', fn (Blueprint $table) => $table->dropColumn(['cert_status', 'cert_issued_at', 'cert_error']));
        Schema::table('apps', fn (Blueprint $table) => $table->dropColumn(['suspended_at', 'suspend_reason']));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['suspended_at', 'suspend_reason', 'limits']));
    }
};
