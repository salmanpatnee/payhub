<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kurv is the ninth payment provider (spec 0010). One table, no currency column
     * (USD only is a platform rule). api_key is a TEXT column holding an encrypted value.
     */
    public function up(): void
    {
        Schema::create('kurv_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->string('prefix')->nullable();
            $table->text('api_key');
            $table->string('environment')->default('sandbox');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kurv_accounts');
    }
};
