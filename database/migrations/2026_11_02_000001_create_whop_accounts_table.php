<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whop is the eighth payment provider (spec 0009). Single table, no currency
     * lock. api_key and webhook_secret are TEXT columns holding encrypted values.
     */
    public function up(): void
    {
        Schema::create('whop_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->string('prefix')->nullable();
            $table->string('company_id');
            $table->text('api_key');
            $table->text('webhook_secret')->nullable();
            $table->string('environment')->default('sandbox');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whop_accounts');
    }
};
