<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Access profiles R2 (SPEC-roles §6, §8, §11 step 1). An organization's profiles map to one role (or No access) per
// installed app; people hold any number of profiles; an override wins for one person × app. `grants` stays the
// effective result apps read, now written only by the resolver (App\Services\Access).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name', 80);
            $table->string('description', 190)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });

        // No row = not mapped yet; app_role_id null = No access.
        Schema::create('access_profile_roles', function (Blueprint $table) {
            $table->foreignId('access_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignId('app_role_id')->nullable()->constrained('app_roles')->cascadeOnDelete();
            $table->ulid('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['access_profile_id', 'app_id']);
        });

        Schema::create('access_profile_user', function (Blueprint $table) {
            $table->foreignId('access_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->ulid('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->primary(['access_profile_id', 'user_id']);
        });

        // One per person × org × app; app_role_id null = No access.
        Schema::create('grant_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignId('app_role_id')->nullable()->constrained('app_roles')->cascadeOnDelete();
            $table->string('reason', 190)->nullable();
            $table->ulid('set_by')->nullable();
            $table->timestamp('set_at')->nullable();
            $table->unique(['user_id', 'organization_id', 'app_id']);
        });

        Schema::table('grants', function (Blueprint $table) {
            $table->string('source', 8)->default('override');   // profile|override
            $table->timestamp('resolved_at')->nullable();
        });

        Schema::table('invites', function (Blueprint $table) {
            $table->json('profiles')->nullable()->after('grants');
        });

        // §11 step 1: every existing grant becomes an override, so nobody's access changes.
        DB::table('grants')->orderBy('id')->each(function ($g) {
            DB::table('grant_overrides')->insert([
                'user_id' => $g->user_id, 'organization_id' => $g->organization_id, 'app_id' => $g->app_id,
                'app_role_id' => $g->app_role_id, 'reason' => 'Set before access profiles',
                'set_by' => $g->granted_by, 'set_at' => $g->granted_at ?? now(),
            ]);
        });
        DB::table('grants')->update(['source' => 'override', 'resolved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('invites', fn (Blueprint $table) => $table->dropColumn('profiles'));
        Schema::table('grants', fn (Blueprint $table) => $table->dropColumn(['source', 'resolved_at']));
        Schema::dropIfExists('grant_overrides');
        Schema::dropIfExists('access_profile_user');
        Schema::dropIfExists('access_profile_roles');
        Schema::dropIfExists('access_profiles');
    }
};
