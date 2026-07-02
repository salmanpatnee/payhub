<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Square is a third payment provider, added on top of the multi-provider
     * scaffolding Revolut already established (the `provider` discriminator and
     * a now-nullable stripe_account_id). This migration is purely additive:
     * it appends 'square' to the provider enum and adds the Square-specific
     * account FK and payment id. It does NOT re-create `provider` or re-touch
     * stripe_account_id — those already exist.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('square_account_id')->nullable()->after('revolut_account_id')
                ->constrained()->cascadeOnDelete();
            // Square payment id for webhook correlation (mirror of stripe_payment_intent_id / revolut_order_id).
            $table->string('square_payment_id')->nullable()->after('revolut_order_id')->index();
        });

        // Widen the provider enum to include 'square'. Uses the schema builder's
        // native change() so it is portable across MySQL (prod) and SQLite (tests).
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square'])->default('stripe')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('square_account_id');
            $table->dropColumn('square_payment_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut'])->default('stripe')->change();
        });
    }
};
