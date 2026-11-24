<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Console redesign 2026-11: an optional personal note in the invite pop-up, included in the invite email (max 500 chars).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->text('note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
