<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Brings a database that already ran the spec 0006 (hosted iframe) migrations up to
     * spec 0007 (Accept.js). Migrations 000001 and 000004 were edited in place, so a
     * database that ran their old versions has no `public_client_key` and still carries
     * the old token timestamps. Every step checks first, so a fresh database (which
     * already has the new shape) passes through untouched.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('authorizenet_accounts', 'public_client_key')) {
            Schema::table('authorizenet_accounts', function (Blueprint $table) {
                $table->string('public_client_key')->nullable()->after('signature_key'); // public Accept.js identifier, refreshed from getMerchantDetails
            });
        }

        // The edited 000004 adds the new attempt columns; carry the old values across, then drop the old ones.
        if (Schema::hasColumn('payments', 'authorizenet_token_issued_at')) {
            DB::table('payments')->whereNull('authorizenet_first_attempt_at')->update([
                'authorizenet_first_attempt_at' => DB::raw('authorizenet_token_issued_at'),
                'authorizenet_last_attempt_at' => DB::raw('authorizenet_token_last_issued_at'),
            ]);

            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn(['authorizenet_token_issued_at', 'authorizenet_token_last_issued_at']);
            });
        }
    }

    /**
     * Nothing to undo: the new shape is what migrations 000001 and 000004 now create.
     */
    public function down(): void {}
};
