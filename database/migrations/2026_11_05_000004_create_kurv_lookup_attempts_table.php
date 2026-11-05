<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per Kurv lookup (spec 0010, AC-11). Diagnostic only: it never decides a
     * payment status and never holds the API key or card data.
     */
    public function up(): void
    {
        Schema::create('kurv_lookup_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('outcome');
            $table->string('kurv_status')->nullable();
            $table->string('result_code')->nullable();
            $table->text('reason')->nullable();
            $table->string('kurv_request_id')->nullable();
            $table->string('kurv_payment_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['payment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kurv_lookup_attempts');
    }
};
