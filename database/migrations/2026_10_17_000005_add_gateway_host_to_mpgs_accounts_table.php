<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The bank's test and live gateways are different hosts, so the host moves
     * from one config value to a per-account column (spec 0005 amendment).
     * null means "use config('services.mpgs.host')".
     */
    public function up(): void
    {
        Schema::table('mpgs_accounts', function (Blueprint $table) {
            $table->string('gateway_host')->nullable()->after('api_version');
        });
    }

    public function down(): void
    {
        Schema::table('mpgs_accounts', function (Blueprint $table) {
            $table->dropColumn('gateway_host');
        });
    }
};
