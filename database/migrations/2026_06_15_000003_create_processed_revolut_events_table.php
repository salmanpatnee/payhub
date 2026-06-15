<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Idempotency ledger for Revolut webhooks. Revolut payloads carry no unique
     * event id, so the key is composed from "{order_id}:{event}" — a given event
     * for a given order is therefore processed at most once.
     */
    public function up(): void
    {
        Schema::create('processed_revolut_events', function (Blueprint $table) {
            $table->string('event_key')->primary();
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_revolut_events');
    }
};
