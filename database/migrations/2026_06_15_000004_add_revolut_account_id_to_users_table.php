<?php

use App\Models\RevolutAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Agents are locked to a single payment account, which may now be a Revolut
     * account instead of a Stripe one. Mirrors users.stripe_account_id.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('revolut_account_id')->nullable()->after('stripe_account_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeignIdFor(RevolutAccount::class);
        });
    }
};
