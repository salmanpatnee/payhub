<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill: remove relationship_manager_user rows left stale by RMs that
     * were deactivated before association-detachment was enforced.
     */
    public function up(): void
    {
        $inactiveIds = DB::table('relationship_managers')
            ->where('is_active', false)
            ->pluck('id');

        DB::table('relationship_manager_user')
            ->whereIn('relationship_manager_id', $inactiveIds)
            ->delete();
    }

    public function down(): void
    {
        // Irreversible: the removed associations are not recoverable.
    }
};
