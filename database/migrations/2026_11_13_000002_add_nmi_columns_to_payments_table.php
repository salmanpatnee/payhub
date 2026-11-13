<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NMI is the tenth payment provider (spec 0012). Purely additive: appends 'nmi' to the
     * provider enum and adds the account FK, the order id sent to NMI, the transaction id the
     * resolver stores, and two attempt timestamps.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('nmi_account_id')->nullable()->after('kurv_account_id')
                ->constrained()->nullOnDelete();
            $table->uuid('nmi_order_id')->nullable()->unique();
            $table->string('nmi_transaction_id')->nullable()->index();
            $table->timestamp('nmi_first_attempt_at')->nullable();
            $table->timestamp('nmi_last_attempt_at')->nullable();
        });

        // Native change() keeps this portable across MySQL (prod) and SQLite (tests).
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva', 'clover', 'mpgs', 'authorizenet', 'whop', 'kurv', 'nmi'])->default('stripe')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nmi_account_id');
            $table->dropUnique(['nmi_order_id']);
            $table->dropIndex(['nmi_transaction_id']);
            $table->dropColumn(['nmi_order_id', 'nmi_transaction_id', 'nmi_first_attempt_at', 'nmi_last_attempt_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva', 'clover', 'mpgs', 'authorizenet', 'whop', 'kurv'])->default('stripe')->change();
        });
    }
};
