<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Mirrors square_accounts: a per-merchant credential set with a `currency`
     * column. Unlike Square (currency detected per-account from the Square
     * location), Clover's currency is always 'usd' — there is no path that
     * creates a clover_accounts row with any other value (see
     * CloverAccountController). private_token and webhook_secret are
     * encrypted via the model cast.
     */
    public function up(): void
    {
        Schema::create('clover_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->string('prefix', 10)->nullable(); // reference-code prefix (mirror of other provider accounts)
            $table->string('merchant_id'); // Clover merchant id — required on every API call, not secret
            $table->text('private_token'); // encrypted cast — Bearer token for the Ecommerce API
            $table->text('webhook_secret'); // encrypted cast — HMAC-SHA256 key for Clover-Signature verification
            $table->string('currency')->default('usd');
            $table->enum('environment', ['sandbox', 'production'])->default('sandbox');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clover_accounts');
    }
};
