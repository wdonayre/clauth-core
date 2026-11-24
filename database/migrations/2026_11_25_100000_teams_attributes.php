<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SPEC-platform P2: teams inside an organization (§6.3), organization profile fields (§6.4), SCIM membership state. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name', 80);
            $table->string('description', 190)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });
        Schema::create('team_user', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->ulid('added_by')->nullable();
            $table->timestamp('added_at')->nullable();
            $table->primary(['team_id', 'user_id']);
        });
        Schema::create('org_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('label', 80);
            $table->string('type', 10)->default('string'); // string|number|bool|date|enum
            $table->json('options')->nullable();            // enum choices
            $table->boolean('required')->default(false);
            $table->string('editable_by', 8)->default('admin'); // admin|self
            $table->boolean('in_token')->default(false);       // sent to apps in the orgs claim
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });
        Schema::create('user_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('value', 255)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'organization_id', 'key']);
        });
        Schema::table('organization_user', function (Blueprint $table) {
            $table->string('external_id', 190)->nullable()->after('role');   // SCIM externalId (the HR system's id)
            $table->timestamp('deactivated_at')->nullable()->after('external_id'); // SCIM active=false: member, no access
        });
        Schema::table('invites', function (Blueprint $table) {
            $table->json('teams')->nullable()->after('profiles');
            $table->json('fields')->nullable()->after('teams'); // profile field values {key: value}
        });
    }

    public function down(): void
    {
        Schema::table('invites', fn (Blueprint $table) => $table->dropColumn(['teams', 'fields']));
        Schema::table('organization_user', fn (Blueprint $table) => $table->dropColumn(['external_id', 'deactivated_at']));
        Schema::dropIfExists('user_attributes');
        Schema::dropIfExists('org_attributes');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
