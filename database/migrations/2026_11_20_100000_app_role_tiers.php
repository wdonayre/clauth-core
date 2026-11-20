<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Access profiles R1 (SPEC-roles §5, §7, §8): every app role gets a tier on Clauth's fixed scale, optional old keys
// (aliases) that tokens keep sending while an app catches up with a rename, and an "assignable" switch to retire a role.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_roles', function (Blueprint $table) {
            $table->string('tier', 8)->nullable()->after('description');        // owner|admin|member|viewer
            $table->string('tier_source', 8)->nullable()->after('tier');        // manifest|manual (manual = sync won't overwrite)
            $table->json('aliases')->nullable()->after('tier_source');
            $table->boolean('assignable')->default(true)->after('aliases');
        });
    }

    public function down(): void
    {
        Schema::table('app_roles', function (Blueprint $table) {
            $table->dropColumn(['tier', 'tier_source', 'aliases', 'assignable']);
        });
    }
};
