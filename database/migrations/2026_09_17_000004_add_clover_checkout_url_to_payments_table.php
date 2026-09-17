<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Deviation from spec 0001's original data model sketch, flagged to the
     * engineer: AC-4 requires reusing the current Clover checkout session
     * (no new API call) while it is still valid, but Clover's Hosted
     * Checkout API — confirmed against docs.clover.com — has no endpoint to
     * retrieve a session's checkout URL after creation (unlike Stripe's
     * PaymentIntent retrieve or Revolut's order retrieve, which is how those
     * two avoid persisting their equivalent secrets). Without storing the
     * URL, AC-4's reuse requirement is unimplementable against Clover's real
     * API surface. Encrypted at rest as a mitigation, and never logged, to
     * stay as close as possible to AC-10's intent.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->text('clover_checkout_url')->nullable()->after('clover_checkout_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('clover_checkout_url');
        });
    }
};
