<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per Worldpay card charge, 3D Secure step or page event (spec 0015, AC-11).
     * Diagnostic only, except that the resolver reads `transaction_reference` and `outcome`.
     * Never a card value, the session link, a wallet token or the cardholder name.
     */
    public function up(): void
    {
        Schema::create('worldpay_charge_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence')->nullable(); // null on event rows
            $table->string('transaction_reference', 64)->nullable()->unique(); // null on event rows
            $table->string('method')->default('card'); // card | google_pay
            $table->string('outcome');
            $table->string('step')->default('charge'); // charge | 3ds_device_data | 3ds_challenge | event
            $table->text('three_ds_state')->nullable(); // encrypted, never sent to the browser
            $table->string('last_event')->nullable();
            $table->string('response_code')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('worldpay_payment_id')->nullable();
            $table->string('client_info')->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'sequence']);
            $table->index(['payment_id', 'outcome']);
        });

        Schema::table('worldpay_lookup_attempts', function (Blueprint $table) {
            $table->foreignId('charge_attempt_id')->nullable()->after('page_id')
                ->constrained('worldpay_charge_attempts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('worldpay_lookup_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('charge_attempt_id');
        });

        Schema::dropIfExists('worldpay_charge_attempts');
    }
};
