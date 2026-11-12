<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.5.0: a sign-in domain belongs to an organization and can be shared by several of that organization's apps
 * (e.g. auth.alabapparel.com for Alab Studio and Alab ERP). "trusted" = silent single sign-on from the main domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_domains', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('trusted')->default(false)->after('status');
            $table->string('display_name', 80)->nullable()->after('trusted');
        });

        Schema::create('app_domain_app', function (Blueprint $table) {
            $table->foreignId('app_domain_id')->constrained('app_domains')->cascadeOnDelete();
            $table->foreignUlid('app_id')->unique()->constrained('apps')->cascadeOnDelete(); // an app uses at most one domain
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->primary(['app_domain_id', 'app_id']);
        });

        // Existing domains: link their app, owned by the app's (first) organization, trusted (they are all William's).
        foreach (DB::table('app_domains')->get() as $d) {
            DB::table('app_domain_app')->insert(['app_domain_id' => $d->id, 'app_id' => $d->app_id, 'created_by' => $d->created_by, 'created_at' => $d->created_at, 'updated_at' => now()]);
            $org = DB::table('app_organization')->join('organizations', 'organizations.id', '=', 'app_organization.organization_id')
                ->where('app_id', $d->app_id)->orderBy('organizations.created_at')->value('organizations.id');
            DB::table('app_domains')->where('id', $d->id)->update(['organization_id' => $org, 'trusted' => true]);
        }

        Schema::table('app_domains', function (Blueprint $table) {
            $table->dropForeign(['app_id']);
        });
        if (Schema::hasIndex('app_domains', 'app_domains_app_id_unique')) { // MySQL keeps it; SQLite's table rebuild already dropped it
            Schema::table('app_domains', fn (Blueprint $table) => $table->dropUnique(['app_id']));
        }
        Schema::table('app_domains', function (Blueprint $table) {
            $table->dropColumn('app_id');
        });
    }

    public function down(): void
    {
        Schema::table('app_domains', function (Blueprint $table) {
            $table->foreignUlid('app_id')->nullable()->after('id')->constrained('apps')->cascadeOnDelete();
        });
        foreach (DB::table('app_domain_app')->orderBy('created_at')->get() as $l) {
            DB::table('app_domains')->where('id', $l->app_domain_id)->whereNull('app_id')->update(['app_id' => $l->app_id]);
        }
        Schema::dropIfExists('app_domain_app');
        Schema::table('app_domains', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['trusted', 'display_name']);
        });
    }
};
