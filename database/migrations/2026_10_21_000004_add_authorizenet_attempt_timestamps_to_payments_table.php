<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * First and latest charge attempt time (spec 0007). They bound the
     * invoice-number lookup, the sweep window, the lookup guard and the edit lock.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('authorizenet_first_attempt_at')->nullable()->after('authorizenet_transaction_id');
            $table->timestamp('authorizenet_last_attempt_at')->nullable()->after('authorizenet_first_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['authorizenet_first_attempt_at', 'authorizenet_last_attempt_at']);
        });
    }
};
