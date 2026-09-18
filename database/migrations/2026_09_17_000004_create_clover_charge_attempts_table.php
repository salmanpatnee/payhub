<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Replaces the migration that used to live at this filename
     * (clover_checkout_url, spec 0001's Hosted Checkout session concept —
     * removed in the same edit that dropped clover_checkout_session_id/
     * clover_checkout_expires_at from payments, see
     * 2026_09_17_000002_add_clover_columns_to_payments_table.php). Reused
     * rather than adding a new timestamp because this branch is unmerged and
     * nothing has shipped past it yet.
     *
     * The durable local record of every synchronous charge attempt against
     * Clover's /v1/charges API, written *before* Clover is ever called so a
     * lost or ambiguous response still has something to resolve against
     * (spec 0002, AC-5). idempotency_key is generated server-side and sent
     * as both Clover's idempotency key and its external_reference_id; the
     * resolver job searches by it but never uses it to re-submit a charge.
     */
    public function up(): void
    {
        Schema::create('clover_charge_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key')->unique(); // generated server-side, written before the Clover call
            $table->string('clover_charge_id')->nullable()->index(); // Clover's id for this attempt; null until a clean response supplies it
            // Plain string, not a DB enum: pending / approved / declined / hard_error /
            // abandoned — so a status Clover adds later can never fail the update.
            $table->string('status')->default('pending');
            $table->string('decline_reason')->nullable(); // Clover's raw decline code/text, operators only — never sent to the client
            $table->unsignedInteger('attempts_count')->default(0); // how many times a resolution check has checked this attempt
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clover_charge_attempts');
    }
};
