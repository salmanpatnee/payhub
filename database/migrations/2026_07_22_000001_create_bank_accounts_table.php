<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('account_name');
            $table->string('account_number');
            $table->string('currency');
            $table->string('sort_code')->nullable();
            $table->string('routing_number')->nullable();
            $table->string('iban')->nullable();
            $table->string('swift_bic')->nullable();
            $table->string('bank_address')->nullable();
            $table->string('bank_country')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
