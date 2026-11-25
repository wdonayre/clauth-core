<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Branded accounts (SPEC-branding B1): how far an organization may brand (set by Clauth owners), what it chose, and its brand.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->string('branding_allowed', 12)->default('clauth');
            $t->string('branding_mode', 12)->default('clauth');
            $t->string('account_name', 60)->nullable();
            $t->string('brand_color', 7)->nullable();
            $t->string('support_email', 190)->nullable();
            $t->string('privacy_url', 300)->nullable();
            $t->string('terms_url', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->dropColumn(['branding_allowed', 'branding_mode', 'account_name', 'brand_color', 'support_email', 'privacy_url', 'terms_url']);
        });
    }
};
