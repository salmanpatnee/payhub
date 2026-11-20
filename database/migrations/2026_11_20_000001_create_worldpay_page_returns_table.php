<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row each time Worldpay sends the client back to PayHub on a failure, error, cancel or expiry
     * address (spec 0014). Display only: the word and the error details come from the browser address,
     * so nothing here ever decides a payment status.
     */
    public function up(): void
    {
        Schema::create('worldpay_page_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('worldpay_pages')->nullOnDelete();
            $table->string('word');
            $table->string('error_ref', 64)->nullable();
            $table->string('error_text', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['payment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worldpay_page_returns');
    }
};
