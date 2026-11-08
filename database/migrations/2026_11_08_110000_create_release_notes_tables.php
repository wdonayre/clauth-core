<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('app_id')->nullable()->constrained('apps')->cascadeOnDelete(); // null = every app
            $table->string('version', 40)->nullable();
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('published_at')->index();
            $table->ulid('created_by')->nullable();
            $table->string('source', 16)->default('admin'); // admin|api
            $table->timestamps();
        });

        Schema::create('release_note_reads', function (Blueprint $table) {
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('release_note_id')->constrained('release_notes')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->primary(['user_id', 'release_note_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_note_reads');
        Schema::dropIfExists('release_notes');
    }
};
