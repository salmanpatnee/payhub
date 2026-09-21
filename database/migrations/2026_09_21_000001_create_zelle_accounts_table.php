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
        Schema::create('zelle_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            // No unique index: MySQL cannot ignore soft deleted rows. Uniqueness is enforced in form requests.
            $table->string('email');
            $table->string('mobile_number', 20)->nullable();
            $table->string('currency');
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
        Schema::dropIfExists('zelle_accounts');
    }
};
