<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SPEC-platform P4 §8.1: sign-in policies per app, per organization (default for its apps) and the platform, with history. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signin_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('app_id')->nullable()->unique()->constrained('apps')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->json('rules');
            $table->unsignedInteger('version')->default(1);
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });
        Schema::create('signin_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signin_policy_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('rules');
            $table->ulid('changed_by')->nullable();
            $table->string('note', 190)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['signin_policy_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signin_policy_versions');
        Schema::dropIfExists('signin_policies');
    }
};
