<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // stripe_account_id was NOT NULL when Stripe was the only rail. A Square payment
            // leaves it null, so it must become nullable now that provider is a discriminator.
            $table->foreignId('stripe_account_id')->nullable()->change();
            // Existing rows backfill to 'stripe' via the default.
            $table->enum('provider', ['stripe', 'square'])->default('stripe')->after('stripe_account_id');
            $table->foreignId('square_account_id')->nullable()->after('provider')->constrained()->cascadeOnDelete();
            // Square payment id for webhook correlation (mirror of stripe_payment_intent_id)
            $table->string('square_payment_id')->nullable()->after('stripe_payment_intent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('square_account_id');
            $table->dropColumn(['provider', 'square_payment_id']);
        });
    }
};
