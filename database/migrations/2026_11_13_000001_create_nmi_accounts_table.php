<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per merchant NMI credential set (spec 0012). private_key and webhook_signing_key are
     * encrypted by the model cast. One currency per account (USD or GBP).
     */
    public function up(): void
    {
        Schema::create('nmi_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->string('prefix', 10)->nullable();
            $table->string('environment')->default('sandbox'); // sandbox | production (enum cast in PHP)
            $table->string('currency', 3)->default('usd');
            $table->text('private_key'); // encrypted cast
            $table->string('public_tokenization_key')->unique(); // public identifier for the Payment Component
            $table->text('webhook_signing_key')->nullable(); // encrypted cast; null = webhook route rejects everything
            $table->boolean('three_ds_enabled')->default(false);
            $table->boolean('google_pay_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nmi_accounts');
    }
};
