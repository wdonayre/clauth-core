<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SPEC-platform P5: RFC 9396 authorization detail types per app. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorization_detail_types', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('type', 120);
            $table->string('description', 190)->nullable();
            $table->json('schema');
            $table->string('processor_url', 500)->nullable();
            $table->text('processor_secret')->nullable(); // encrypted
            $table->timestamps();
            $table->unique(['app_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_detail_types');
    }
};
