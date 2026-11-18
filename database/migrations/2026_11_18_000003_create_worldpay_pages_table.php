<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per hosted page created for a payment (spec 0014, AC-6 and AC-7). The
     * transaction reference is unique so it is never reused. The url stays empty until the
     * create call succeeds and is never sent to the browser except as the redirect target.
     */
    public function up(): void
    {
        Schema::create('worldpay_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('transaction_reference')->unique();
            $table->text('url')->nullable();
            $table->timestamp('expires_at');
            $table->string('last_event')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worldpay_pages');
    }
};
