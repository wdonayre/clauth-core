<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SPEC-platform P4 §8.4: sign-in page themes an organization (or the platform) designs and apps/domains pick. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signin_themes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->nullable()->constrained()->cascadeOnDelete(); // null = platform
            $table->string('key', 60)->unique();
            $table->string('name', 80);
            $table->json('settings');
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });
        Schema::table('apps', fn (Blueprint $t) => $t->foreignId('signin_theme_id')->nullable()->after('login_theme')->constrained('signin_themes')->nullOnDelete());
        Schema::table('app_domains', fn (Blueprint $t) => $t->foreignId('signin_theme_id')->nullable()->constrained('signin_themes')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('app_domains', fn (Blueprint $t) => $t->dropConstrainedForeignId('signin_theme_id'));
        Schema::table('apps', fn (Blueprint $t) => $t->dropConstrainedForeignId('signin_theme_id'));
        Schema::dropIfExists('signin_themes');
    }
};
