<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per (user, currency) — replaces the four mutually-exclusive
     * provider FK columns on users with a per-currency payment account
     * assignment for agents.
     */
    public function up(): void
    {
        Schema::create('user_payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 3);
            $table->string('provider', 20);
            // Target table resolved by provider via App\Support\ProviderAccountTable,
            // same convention as payments.*_account_id — no FK constraint since it
            // points at one of four different tables depending on provider.
            $table->unsignedBigInteger('account_id');
            $table->timestamps();

            $table->unique(['user_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_payment_accounts');
    }
};
