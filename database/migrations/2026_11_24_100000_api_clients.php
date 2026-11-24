<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Platform API (SPEC-platform §5.1): API clients are oauth_clients of type `api`, owned by an organization or the platform. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->ulid('organization_id')->nullable()->after('app_id')->index(); // null + type api = platform client
            $table->ulid('created_by')->nullable()->after('organization_id');
            $table->timestamp('last_used_at')->nullable()->after('secret_expires_at');
        });
        Schema::table('audit_events', function (Blueprint $table) {
            $table->uuid('actor_client_id')->nullable()->after('actor_type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', fn (Blueprint $table) => $table->dropColumn(['organization_id', 'created_by', 'last_used_at']));
        Schema::table('audit_events', fn (Blueprint $table) => $table->dropColumn('actor_client_id'));
    }
};
