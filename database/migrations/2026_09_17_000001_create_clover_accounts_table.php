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
     *
     * api_access_key added for spec 0002 (Hosted Iframe): the public
     * identifier the client-side SDK needs to initialize the embedded card
     * form. Deliberately plain, not encrypted — same treatment as Square's
     * application_id (safe in logs and on the frontend), unlike
     * private_token.
     *
     * No webhook_secret column: Clover's Hosted Iframe + API/SDK integration
     * type has no documented webhook (spec 0002's correction) — status comes
     * from the synchronous /v1/charges response instead, so there is no
     * signature to verify and keeping an unused secret column would
     * misleadingly imply one exists.
     */
    public function up(): void
    {
        Schema::create('clover_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->string('prefix', 10)->nullable(); // reference-code prefix (mirror of other provider accounts)
            $table->string('merchant_id'); // Clover merchant id — required on every API call, not secret
            $table->string('api_access_key'); // plain — public identifier the Hosted Iframe SDK needs client-side
            $table->text('private_token'); // encrypted cast — Bearer token for the Ecommerce API
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
