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
     * to the provider enum and adds the Clover-specific account FK and
     * payment id. Does NOT re-touch any existing provider columns.
     *
     * Edited in place for spec 0002 (Hosted Iframe): the original version of
     * this migration also added clover_checkout_session_id/
     * clover_checkout_expires_at — the Hosted Checkout session concept, which
     * Hosted Iframe has no use for (there is no redirect, so nothing to
     * resume). Dropped here rather than layering a follow-up migration,
     * because this branch is unmerged and nothing has shipped past it yet.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('clover_account_id')->nullable()->after('viva_account_id')
                ->constrained()->cascadeOnDelete();
            // Set by whichever path (the synchronous charge handler or the resolver
            // job) first completes the payment — the winning attempt's charge id
            // (see clover_charge_attempts for the full attempt log).
            $table->string('clover_payment_id')->nullable()->after('viva_order_code')->index();
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
            $table->dropColumn(['clover_payment_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva'])->default('stripe')->change();
        });
    }
};
