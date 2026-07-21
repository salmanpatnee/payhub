<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Superseded by user_payment_accounts (one row per user/currency) —
     * see the two prior migrations in this batch.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // dropConstrainedForeignId drops both the FK constraint and the
            // column itself — dropForeignIdFor only drops the constraint.
            $table->dropConstrainedForeignId('stripe_account_id');
            $table->dropConstrainedForeignId('revolut_account_id');
            $table->dropConstrainedForeignId('square_account_id');
            $table->dropConstrainedForeignId('viva_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('stripe_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('revolut_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('square_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('viva_account_id')->nullable()->constrained()->nullOnDelete();
        });
    }
};
