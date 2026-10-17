<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The durable record of every MPGS attempt, written at prepare time and
     * before any PAY call (spec 0005, AC-5). order_id is
     * `{payment_id}-{sequence}` and is never reused for a second try.
     */
    public function up(): void
    {
        Schema::create('mpgs_charge_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('order_id')->unique(); // gateway order id: {payment_id}-{sequence}
            $table->string('auth_transaction_id'); // 3DS transaction id, generated server side
            $table->string('transaction_id'); // the PAY transaction id, generated server side
            $table->text('session_id')->nullable(); // encrypted cast; set at prepare, nulled at any final state
            // Plain string, not a DB enum: prepared / pending / approved / declined /
            // hard_error / abandoned, so a new status can never fail an update.
            $table->string('status')->default('prepared');
            $table->string('auth_status')->nullable(); // 3DS outcome
            $table->string('gateway_code')->nullable();
            $table->string('decline_reason')->nullable(); // raw gateway text, operators only
            $table->string('receipt')->nullable();
            $table->unsignedInteger('attempts_count')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpgs_charge_attempts');
    }
};
