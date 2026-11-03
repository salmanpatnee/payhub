<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whop is the eighth payment provider (spec 0009). Purely additive: appends
     * 'whop' to the provider enum, adds the account FK, the Whop payment id and
     * the charge start timestamp that locks amount, currency and account edits.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('whop_account_id')->nullable()->after('authorizenet_account_id')
                ->constrained()->restrictOnDelete();
            $table->string('whop_payment_id')->nullable()->index();
            $table->timestamp('whop_charge_started_at')->nullable();
        });

        // Native change() keeps this portable across MySQL (prod) and SQLite (tests).
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva', 'clover', 'mpgs', 'authorizenet', 'whop'])->default('stripe')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whop_account_id');
            $table->dropColumn(['whop_payment_id', 'whop_charge_started_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva', 'clover', 'mpgs', 'authorizenet'])->default('stripe')->change();
        });
    }
};
