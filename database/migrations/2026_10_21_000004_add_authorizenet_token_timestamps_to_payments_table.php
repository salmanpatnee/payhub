<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * First and latest hosted-form token issue time (spec 0006). They bound the
     * invoice-number lookup, the sweep window, the lookup guard and the edit lock.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('authorizenet_token_issued_at')->nullable()->after('authorizenet_transaction_id');
            $table->timestamp('authorizenet_token_last_issued_at')->nullable()->after('authorizenet_token_issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['authorizenet_token_issued_at', 'authorizenet_token_last_issued_at']);
        });
    }
};
