<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Clover is a fifth payment provider, added on top of the multi-provider
     * scaffolding Viva already established. Purely additive: appends 'clover'
     * to the provider enum and adds the Clover-specific account FK, checkout
     * session id/expiry, and payment id. Does NOT re-touch any existing
     * provider columns.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('clover_account_id')->nullable()->after('viva_account_id')
                ->constrained()->cascadeOnDelete();
            // Clover Hosted Checkout session id — set when a session is created, only
            // replaced once clover_checkout_expires_at has passed (see AC-4).
            $table->string('clover_checkout_session_id')->nullable()->after('viva_order_code')->index();
            // Copied verbatim from Clover's expirationTime response field — the single
            // source of truth for whether the stored session can still be reused.
            $table->timestamp('clover_checkout_expires_at')->nullable()->after('clover_checkout_session_id');
            // Set by the webhook handler on both approval and decline (Clover assigns
            // an id to a declined attempt too, so a decline is still traceable).
            $table->string('clover_payment_id')->nullable()->after('clover_checkout_expires_at')->index();
        });

        // Widen the provider enum to include 'clover'. Uses the schema builder's
        // native change() so it is portable across MySQL (prod) and SQLite (tests).
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva', 'clover'])->default('stripe')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('clover_account_id');
            $table->dropColumn(['clover_checkout_session_id', 'clover_checkout_expires_at', 'clover_payment_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva'])->default('stripe')->change();
        });
    }
};
