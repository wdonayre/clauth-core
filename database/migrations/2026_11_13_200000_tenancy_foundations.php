<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M7 (SPEC-organizations.md): organizations are the tenants.
 *  - organizations.is_platform: William's own businesses (first-party apps, trusted domains).
 *  - organization_user.role owner|admin|developer|member replaces is_org_admin.
 *  - apps.owner_organization_id (the publisher) + visibility private|installable.
 *  - app_organization becomes app_installations with a status (requested|active), approved by the installing org.
 *  - app_domains.trusted becomes an override: platform organizations' domains are always trusted.
 */
return new class extends Migration
{
    /** Decided 2026-11-13 (SPEC-organizations §11.2). */
    private const PUBLISHERS = [
        'alab-studio' => 'alab-apparel', 'alab-erp' => 'alab-apparel', 'xpenses' => 'the-cake-labs',
        'cebpos' => 'cebucake', 'cakepricer' => 'cebucake',
    ];

    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_platform')->default(false)->after('status');
            $table->timestamp('verified_at')->nullable()->after('is_platform');
        });
        DB::table('organizations')->update(['is_platform' => true, 'verified_at' => now()]); // every existing org is William's

        Schema::table('organization_user', function (Blueprint $table) {
            $table->string('role', 16)->default('member')->after('user_id');
        });
        DB::table('organization_user')->where('is_org_admin', true)->update(['role' => 'admin']);
        Schema::table('organization_user', fn (Blueprint $table) => $table->dropColumn('is_org_admin'));

        Schema::table('apps', function (Blueprint $table) {
            $table->foreignUlid('owner_organization_id')->nullable()->after('id')->constrained('organizations')->nullOnDelete();
            $table->string('visibility', 16)->default('private')->after('status');
        });
        foreach (DB::table('apps')->get(['id', 'key']) as $app) {
            $slug = self::PUBLISHERS[$app->key] ?? null;
            $org = $slug ? DB::table('organizations')->where('slug', $slug)->value('id') : null;
            $org ??= DB::table('app_organization')->join('organizations', 'organizations.id', '=', 'app_organization.organization_id')
                ->where('app_id', $app->id)->orderBy('organizations.created_at')->value('organizations.id');
            DB::table('apps')->where('id', $app->id)->update(['owner_organization_id' => $org]);
        }

        Schema::rename('app_organization', 'app_installations');
        Schema::table('app_installations', function (Blueprint $table) {
            $table->string('status', 16)->default('active');
            $table->ulid('requested_by')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        DB::table('app_installations')->update(['approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        // trusted is now an override for outside organizations; platform organizations' domains are always trusted.
        DB::table('app_domains')->update(['trusted' => false]);
    }

    public function down(): void
    {
        DB::table('app_installations')->where('status', '!=', 'active')->delete();
        Schema::table('app_installations', fn (Blueprint $table) => $table->dropColumn(['status', 'requested_by', 'approved_by', 'approved_at', 'created_at', 'updated_at']));
        Schema::rename('app_installations', 'app_organization');
        Schema::table('apps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_organization_id');
            $table->dropColumn('visibility');
        });
        Schema::table('organization_user', fn (Blueprint $table) => $table->boolean('is_org_admin')->default(false));
        DB::table('organization_user')->whereIn('role', ['owner', 'admin'])->update(['is_org_admin' => true]);
        Schema::table('organization_user', fn (Blueprint $table) => $table->dropColumn('role'));
        DB::table('app_domains')->update(['trusted' => true]);
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['is_platform', 'verified_at']));
    }
};
