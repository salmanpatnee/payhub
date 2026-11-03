<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Webhook idempotency for Whop: one row per `webhook-id` header.
     */
    public function up(): void
    {
        Schema::create('processed_whop_events', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_id')->unique();
            $table->string('event_type');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_whop_events');
    }
};
