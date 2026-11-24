<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SPEC-platform P3 §7: connections (more ways to sign in, SMS and email senders), phone verification, SMS 2-step. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->nullable()->constrained()->cascadeOnDelete(); // null = platform
            $table->string('type', 16);         // oidc|oauth|meta|sms|email-smtp
            $table->string('key', 40)->unique(); // in sign-in URLs: /login/c/{key}
            $table->string('name', 80);          // button label ("Acme Microsoft 365")
            $table->text('config');               // encrypted JSON; secrets never leave the server
            $table->string('status', 12)->default('active'); // active|disabled
            $table->ulid('created_by')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::table('social_identities', function (Blueprint $table) {
            $table->string('provider', 60)->change();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->boolean('sms_two_step')->default(false)->after('phone_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['phone_verified_at', 'sms_two_step']));
        Schema::dropIfExists('connections');
    }
};
