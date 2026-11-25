<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SPEC-provisioning E1: webhook endpoints per app, deliveries with retries, a per-app event sequence. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('url', 500);
            $table->text('secret'); // encrypted
            $table->json('events')->nullable(); // null = all
            $table->string('status', 12)->default('active'); // active|disabled
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->unsignedBigInteger('sequence');
            $table->json('payload');
            $table->string('status', 12)->default('pending'); // pending|delivered|failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['webhook_endpoint_id', 'status']);
        });
        Schema::table('apps', fn (Blueprint $t) => $t->unsignedBigInteger('webhook_sequence')->default(0));
        // When a grant was last given or changed, for the people list's ?since=.
        Schema::table('grants', function (Blueprint $t) {
            $t->timestamp('changed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('apps', fn (Blueprint $t) => $t->dropColumn('webhook_sequence'));
        if (Schema::hasColumn('grants', 'changed_at')) {
            Schema::table('grants', function (Blueprint $t) {
                $t->dropIndex(['changed_at']);
                $t->dropColumn('changed_at');
            });
        }
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
