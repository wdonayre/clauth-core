<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SPEC-platform P4 §8.5: editable email/SMS templates (per organization or platform, per language) and text overrides. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 40);       // invite | login_code | new_device | lockout | sms_code
            $table->string('locale', 10)->default('en');
            $table->string('subject', 190)->nullable();
            $table->text('body');
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'key', 'locale']);
        });
        Schema::create('translation_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 10);
            $table->string('key', 500);
            $table->text('text');
            $table->timestamps();
            $table->unique(['locale', 'key']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->string('locale', 10)->nullable()->after('phone'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('locale'));
        Schema::dropIfExists('translation_overrides');
        Schema::dropIfExists('message_templates');
    }
};
