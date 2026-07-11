<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Mirrors stripe_accounts / revolut_accounts / square_accounts: a global,
     * per-merchant credential set. client_secret, api_key, and
     * webhook_verification_key are encrypted via the model cast.
     *
     * Unlike square_accounts, there is no `currency` column — Viva is GBP-only
     * as a flat platform rule (enforced in Store/UpdatePaymentRequest), not a
     * per-account variable like Square's currency lock.
     */
    public function up(): void
    {
        Schema::create('viva_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->string('prefix', 10)->nullable(); // reference-code prefix (mirror of other provider accounts)
            $table->string('client_id'); // OAuth2 client_credentials id (public, not secret)
            $table->text('client_secret'); // encrypted cast — OAuth2 client_credentials secret
            $table->string('merchant_id'); // legacy Basic Auth pair, required for order creation
            $table->text('api_key'); // encrypted cast — legacy Basic Auth pair
            $table->string('source_code'); // Viva's 4-digit payment source code, required on order creation
            $table->text('webhook_verification_key')->nullable(); // encrypted cast — handshake echo value
            $table->enum('environment', ['demo', 'production'])->default('demo');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viva_accounts');
    }
};
