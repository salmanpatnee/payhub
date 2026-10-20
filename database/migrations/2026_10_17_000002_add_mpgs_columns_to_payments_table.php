<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * MPGS is the sixth payment provider (spec 0005). Purely additive:
     * appends 'mpgs' to the provider enum and adds the MPGS account FK and
     * the winning attempt's gateway order id.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('mpgs_account_id')->nullable()->after('clover_account_id')
                ->constrained()->cascadeOnDelete();
            // The winning attempt's gateway order id, shown as the provider
            // reference in the CSV export and dashboard.
            $table->string('mpgs_order_id')->nullable()->after('clover_payment_id')->index();
        });

        // Native change() keeps this portable across MySQL (prod) and SQLite (tests).
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva', 'clover', 'mpgs'])->default('stripe')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mpgs_account_id');
            $table->dropColumn(['mpgs_order_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva', 'clover'])->default('stripe')->change();
        });
    }
};
