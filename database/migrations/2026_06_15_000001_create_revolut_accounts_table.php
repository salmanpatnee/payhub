<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Mirrors stripe_accounts (post brand_id drop): a global, per-merchant
     * credential set. secret_key/webhook_secret are encrypted via the model cast.
     */
    public function up(): void
    {
        Schema::create('revolut_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->string('public_key')->nullable();
            $table->text('secret_key');
            $table->text('webhook_secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revolut_accounts');
    }
};
