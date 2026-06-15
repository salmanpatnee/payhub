<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive multi-provider support: a `provider` discriminator, a nullable
     * revolut_account_id FK, and revolut_order_id (the Revolut analog of
     * stripe_payment_intent_id). stripe_account_id becomes nullable so Revolut
     * payments are not forced to reference a Stripe account.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut'])->default('stripe')->after('uuid');
            $table->foreignId('revolut_account_id')->nullable()->after('stripe_account_id')
                ->constrained()->cascadeOnDelete();
            $table->string('revolut_order_id')->nullable()->after('stripe_payment_intent_id')->index();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('stripe_account_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revolut_account_id');
            $table->dropColumn(['provider', 'revolut_order_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('stripe_account_id')->nullable(false)->change();
        });
    }
};
