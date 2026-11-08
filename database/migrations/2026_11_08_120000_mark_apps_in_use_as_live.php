<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Apps registered before v0.3.1 stayed "not connected" even after people signed in through Clauth.
// Anything that has issued a token or loaded the Clauth bar is in use: mark it live.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('apps')->where('status', 'not_connected')
            ->where(fn ($q) => $q->whereNotNull('last_token_at')->orWhereNotNull('bar_seen_at'))
            ->update(['status' => 'live', 'updated_at' => now()]);
    }

    public function down(): void
    {
        //
    }
};
