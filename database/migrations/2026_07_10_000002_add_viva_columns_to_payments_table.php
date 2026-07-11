<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Viva is a fourth payment provider, added on top of the multi-provider
     * scaffolding Square already established. This migration is purely
     * additive: it appends 'viva' to the provider enum and adds the
     * Viva-specific account FK, transaction id, and order code. It does NOT
     * re-create `provider` or re-touch any existing provider columns.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('viva_account_id')->nullable()->after('square_account_id')
                ->constrained()->cascadeOnDelete();
            // Viva transaction id for webhook correlation (mirror of stripe_payment_intent_id / square_payment_id).
            $table->string('viva_transaction_id')->nullable()->after('square_payment_id')->index();
            // Viva order code, needed to build the checkout redirect URL and correlate
            // the return callback before the transaction id is known.
            $table->string('viva_order_code')->nullable()->after('viva_transaction_id');
        });

        // Widen the provider enum to include 'viva'. Uses the schema builder's
        // native change() so it is portable across MySQL (prod) and SQLite (tests).
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva'])->default('stripe')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('viva_account_id');
            $table->dropColumn(['viva_transaction_id', 'viva_order_code']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square'])->default('stripe')->change();
        });
    }
};
