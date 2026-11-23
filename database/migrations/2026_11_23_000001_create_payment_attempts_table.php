<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per verified provider outcome, shared by every provider that has an extractor
     * (spec 0016). `provider` is a plain string so a new provider needs no migration.
     */
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->unsignedInteger('sequence');
            $table->string('event_key', 191)->nullable();
            $table->string('event', 100);
            $table->string('status', 20);
            $table->unsignedBigInteger('amount')->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('amount_reported')->default(false);
            $table->string('reference', 191)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('source', 20)->default('webhook');
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['payment_id', 'sequence']);
            $table->unique(['provider', 'event_key']);
            $table->index(['payment_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
