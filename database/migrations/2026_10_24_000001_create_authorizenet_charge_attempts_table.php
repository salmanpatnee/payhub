<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per Authorize.net charge call, written before the call and updated from the
     * gateway's reply, so a plain decline or a timeout leaves a record. Only what the
     * gateway sends back is kept: never a card value, the Accept.js code or the request body.
     */
    public function up(): void
    {
        Schema::create('authorizenet_charge_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            // Plain string, not a DB enum: sent / approved / held / declined / check_details /
            // error / unclear, so a new status can never fail an update.
            $table->string('status')->default('sent');
            $table->string('response_code')->nullable();
            $table->string('error_code')->nullable();
            $table->string('reason')->nullable(); // operators only
            $table->string('transaction_id')->nullable()->index();
            $table->string('avs_result', 8)->nullable();
            $table->string('cvv_result', 8)->nullable();
            $table->string('cavv_result', 8)->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorizenet_charge_attempts');
    }
};
