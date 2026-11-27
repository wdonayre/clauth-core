<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign up, then set up an organization (SPEC-signup.md): organization requests belong to a signed-in account, the
 * reviewer can ask for more information, requests close after 30 days. Invite-only apps can be asked for access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_requests', function (Blueprint $table) {
            $table->foreignUlid('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('country', 60)->nullable()->after('website');
            $table->string('team_size', 20)->nullable()->after('country');
            $table->foreignUlid('source_app_id')->nullable()->after('team_size')->constrained('apps')->nullOnDelete();
            $table->text('info_message')->nullable()->after('reject_reason');   // the reviewer's question
            $table->timestamp('info_requested_at')->nullable()->after('info_message');
            $table->index(['user_id', 'status']);
        });

        Schema::create('access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('note', 500)->nullable();
            $table->string('status', 16)->default('pending'); // pending|approved|declined
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['user_id', 'app_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
        Schema::table('organization_requests', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('source_app_id');
            $table->dropColumn(['country', 'team_size', 'info_message', 'info_requested_at']);
        });
    }
};
