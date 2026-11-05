<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kurv is the ninth payment provider (spec 0010). Purely additive: appends 'kurv'
     * to the provider enum and adds the account FK, Kurv's payment request id and
     * payment id, the saved hosted link, and three timestamps (link created, last
     * lookup, link closed).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('kurv_account_id')->nullable()->after('whop_account_id')
                ->constrained()->restrictOnDelete();
            $table->string('kurv_request_id')->nullable()->index();
            $table->text('kurv_payment_url')->nullable();
            $table->string('kurv_payment_id')->nullable();
            $table->timestamp('kurv_charge_started_at')->nullable();
            $table->timestamp('kurv_last_lookup_at')->nullable();
            $table->timestamp('kurv_link_closed_at')->nullable();
        });

        // Native change() keeps this portable across MySQL (prod) and SQLite (tests).
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva', 'clover', 'mpgs', 'authorizenet', 'whop', 'kurv'])->default('stripe')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kurv_account_id');
            $table->dropIndex(['kurv_request_id']);
            $table->dropColumn([
                'kurv_request_id', 'kurv_payment_url', 'kurv_payment_id',
                'kurv_charge_started_at', 'kurv_last_lookup_at', 'kurv_link_closed_at',
            ]);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva', 'clover', 'mpgs', 'authorizenet', 'whop'])->default('stripe')->change();
        });
    }
};
