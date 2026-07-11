<?php

use App\Models\VivaAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Agents are locked to a single payment account, which may now be a Viva
     * account. Mirrors users.stripe_account_id / users.revolut_account_id /
     * users.square_account_id.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('viva_account_id')->nullable()->after('square_account_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeignIdFor(VivaAccount::class);
        });
    }
};
