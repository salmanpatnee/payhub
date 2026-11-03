<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whop Checkout embed / redirect (spec 0009 rework): the account picks a
     * checkout mode and each payment keeps its one Whop checkout configuration.
     */
    public function up(): void
    {
        Schema::table('whop_accounts', function (Blueprint $table) {
            $table->string('checkout_mode')->default('embed')->after('environment');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('whop_checkout_configuration_id')->nullable()->index();
            $table->text('whop_purchase_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['whop_checkout_configuration_id']);
            $table->dropColumn(['whop_checkout_configuration_id', 'whop_purchase_url']);
        });

        Schema::table('whop_accounts', function (Blueprint $table) {
            $table->dropColumn('checkout_mode');
        });
    }
};
