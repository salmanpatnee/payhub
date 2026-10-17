<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_mpgs_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique(); // order_id:transaction_id:result
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_mpgs_events');
    }
};
