<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_account_health', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stripe_account_id')->unique()->constrained('stripe_accounts')->cascadeOnDelete();
            $table->string('stripe_remote_id')->nullable();
            $table->string('status');
            $table->boolean('charges_enabled')->nullable();
            $table->boolean('payouts_enabled')->nullable();
            $table->boolean('details_submitted')->nullable();
            $table->string('disabled_reason')->nullable();
            $table->json('requirements')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('default_currency', 3)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_account_health');
    }
};
