<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Clover's webhook carries no confirmed stable per-delivery event id (see
     * .planning/research/CLOVER_PAYMENTS.md and spec 0001's Follow-up), so —
     * mirroring processed_viva_events / processed_revolut_events — the key is
     * computed server-side as "{payment_id}:{checkout_session_id}" rather than
     * trusted verbatim from the payload.
     */
    public function up(): void
    {
        Schema::create('processed_clover_events', function (Blueprint $table) {
            $table->string('event_key')->primary();
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_clover_events');
    }
};
