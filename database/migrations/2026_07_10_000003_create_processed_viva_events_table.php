<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Viva's webhook payload event-id shape is unconfirmed against a live
     * sandbox (see .planning/research/VIVA_PAYMENTS.md). Mirrors
     * processed_revolut_events' composite-key approach (event_key rather than
     * a bare provider event id) since that is the safer default when a
     * provider's per-delivery event id is not guaranteed unique/stable.
     * Confirm against a live TransactionPaymentCreated payload during Phase 5
     * webhook testing and simplify to a bare event id column if one exists.
     */
    public function up(): void
    {
        Schema::create('processed_viva_events', function (Blueprint $table) {
            $table->string('event_key')->primary();
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_viva_events');
    }
};
