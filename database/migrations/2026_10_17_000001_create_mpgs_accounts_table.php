<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Per-merchant MPGS (Mastercard Gateway) credential set, spec 0005.
     * integration_password and webhook_secret are encrypted via the model
     * cast. No currency column (MPGS accounts accept USD and GBP, a currency
     * the bank has not enabled fails at pay time) and no environment column
     * (test versus live is a different merchant id and password; the gateway
     * host is one value from config).
     */
    public function up(): void
    {
        Schema::create('mpgs_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->string('prefix', 10)->nullable(); // reference-code prefix (mirror of other provider accounts)
            $table->string('merchant_id'); // plain, not secret, needed in URLs and the browser
            $table->text('integration_password'); // encrypted cast
            $table->text('webhook_secret')->nullable(); // encrypted cast; null = webhook route rejects everything
            $table->unsignedSmallInteger('api_version');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpgs_accounts');
    }
};
