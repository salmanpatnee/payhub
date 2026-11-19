<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Worldpay Checkout card fields mode (spec 0015, AC-1). `checkout_id` and the Google Pay
     * gateway merchant id are public identifiers, so they are plain columns.
     */
    public function up(): void
    {
        Schema::table('worldpay_accounts', function (Blueprint $table) {
            $table->string('mode')->default('hosted')->after('environment'); // hosted | card_fields
            $table->string('checkout_id')->nullable()->after('mode');
            $table->boolean('google_pay_enabled')->default(false)->after('checkout_id');
            $table->string('google_pay_gateway_merchant_id')->nullable()->after('google_pay_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('worldpay_accounts', function (Blueprint $table) {
            $table->dropColumn(['mode', 'checkout_id', 'google_pay_enabled', 'google_pay_gateway_merchant_id']);
        });
    }
};
