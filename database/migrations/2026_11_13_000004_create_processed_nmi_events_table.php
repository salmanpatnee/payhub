<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Webhook dedupe (spec 0012, AC-14): written only after the job succeeds. */
    public function up(): void
    {
        Schema::create('processed_nmi_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_nmi_events');
    }
};
