<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Mirrors stripe_accounts / revolut_accounts: a global, per-merchant
     * credential set. access_token/webhook_signature_key are encrypted via the
     * model cast.
     */
    public function up(): void
    {
        Schema::create('square_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->string('prefix', 10)->nullable(); // reference-code prefix (mirror of stripe/revolut accounts)
            $table->string('application_id'); // public — Web Payments SDK (Stripe publishable_key equivalent)
            $table->string('location_id');    // required on every Square charge
            $table->text('access_token');     // encrypted cast (Stripe secret_key equivalent)
            $table->text('webhook_signature_key')->nullable(); // encrypted cast (Stripe webhook_secret equivalent)
            $table->enum('environment', ['sandbox', 'production'])->default('sandbox');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('square_accounts');
    }
};
