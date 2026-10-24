<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Card attempt lock (MPGS and Authorize.net). card_locked_until pauses the pay page after
     * too many failed attempts; card_attempts_since marks where the next count starts (the end
     * of the last lock, or an admin reset).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('card_locked_until')->nullable()->after('authorizenet_last_attempt_at')->index();
            $table->timestamp('card_attempts_since')->nullable()->after('card_locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['card_locked_until', 'card_attempts_since']);
        });
    }
};
