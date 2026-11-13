<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per NMI charge call or 3DS failure report (spec 0012, AC-11). Only what the gateway
     * sends back is kept: never a card value, the payment token or the request body.
     */
    public function up(): void
    {
        Schema::create('nmi_charge_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('method')->default('card'); // card | google_pay
            // Plain string: sent / approved / declined / check_details / unclear / error / 3ds_failed.
            $table->string('status')->default('sent');
            $table->string('response_code')->nullable();
            $table->string('reason')->nullable(); // operators only
            $table->string('transaction_id')->nullable()->index();
            $table->string('avs_result', 8)->nullable();
            $table->string('cvv_result', 8)->nullable();
            $table->string('three_ds_result', 8)->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nmi_charge_attempts');
    }
};
