<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Per-merchant Authorize.net credential set, spec 0006. transaction_key and
     * signature_key are encrypted via the model cast. One currency per account
     * (USD), like Square.
     */
    public function up(): void
    {
        Schema::create('authorizenet_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->string('prefix', 10)->nullable(); // reference-code prefix (mirror of other provider accounts)
            $table->string('api_login_id'); // plain, identifies the merchant and is not a secret
            $table->text('transaction_key'); // encrypted cast
            $table->text('signature_key')->nullable(); // encrypted cast; null = webhook route rejects everything
            $table->string('public_client_key')->nullable(); // public identifier for Accept.js, fetched from getMerchantDetails
            $table->string('environment')->default('sandbox'); // sandbox | production (enum cast in PHP)
            $table->string('currency', 3)->default('usd');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorizenet_accounts');
    }
};
