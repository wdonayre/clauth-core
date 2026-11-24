<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Console redesign 2026-11: a short description of an app ("Expense tracking"), shown on the Apps list and edited on App › Settings.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->string('description', 120)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
