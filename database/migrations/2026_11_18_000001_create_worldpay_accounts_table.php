<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Worldpay (Access Worldpay, Hosted Payment Pages) is a payment provider (spec 0014).
     * GBP only, so there is no currency column. The API login and the webhook secret are
     * TEXT columns holding encrypted values.
     */
    public function up(): void
    {
        Schema::create('worldpay_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->string('prefix')->nullable();
            $table->text('api_username');
            $table->text('api_password');
            $table->string('entity_ref');
            $table->string('environment')->default('try');
            $table->string('webhook_key_id')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worldpay_accounts');
    }
};
