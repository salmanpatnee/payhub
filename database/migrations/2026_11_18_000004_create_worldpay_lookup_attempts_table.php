<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per Worldpay lookup per page (spec 0014, AC-9). Diagnostic only: it never
     * decides a payment status and never holds a username, password, URL or signature.
     */
    public function up(): void
    {
        Schema::create('worldpay_lookup_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('worldpay_pages')->nullOnDelete();
            $table->string('source');
            $table->string('outcome');
            $table->string('last_event')->nullable();
            $table->text('reason')->nullable();
            $table->string('worldpay_payment_id')->nullable();
            $table->unsignedInteger('checks')->default(1);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['payment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worldpay_lookup_attempts');
    }
};
