<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Worldpay (spec 0014). Purely additive: appends 'worldpay' to the provider enum and
     * adds the account FK, Worldpay's id of the paid sale (written only by the resolver),
     * the "a page exists" lock timestamp and the last lookup time the sweep reads.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('worldpay_account_id')->nullable()->after('nmi_account_id')
                ->constrained()->nullOnDelete();
            $table->string('worldpay_payment_id')->nullable()->index();
            $table->timestamp('worldpay_charge_started_at')->nullable();
            $table->timestamp('worldpay_last_lookup_at')->nullable();
        });

        // Native change() keeps this portable across MySQL (prod) and SQLite (tests).
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva', 'clover', 'mpgs', 'authorizenet', 'whop', 'kurv', 'nmi', 'worldpay'])->default('stripe')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('worldpay_account_id');
            $table->dropIndex(['worldpay_payment_id']);
            $table->dropColumn(['worldpay_payment_id', 'worldpay_charge_started_at', 'worldpay_last_lookup_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva', 'clover', 'mpgs', 'authorizenet', 'whop', 'kurv', 'nmi'])->default('stripe')->change();
        });
    }
};
