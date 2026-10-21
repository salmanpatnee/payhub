<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Authorize.net is the seventh payment provider (spec 0006). Purely additive:
     * appends 'authorizenet' to the provider enum and adds the account FK and
     * the gateway transaction id.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('authorizenet_account_id')->nullable()->after('mpgs_account_id')
                ->constrained()->cascadeOnDelete();
            $table->string('authorizenet_transaction_id')->nullable()->after('mpgs_order_id')->index();
        });

        // Native change() keeps this portable across MySQL (prod) and SQLite (tests).
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva', 'clover', 'mpgs', 'authorizenet'])->default('stripe')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('authorizenet_account_id');
            $table->dropColumn(['authorizenet_transaction_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'revolut', 'square', 'viva', 'clover', 'mpgs'])->default('stripe')->change();
        });
    }
};
