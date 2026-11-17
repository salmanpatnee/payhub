<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A short browser and device label per attempt row ("Chrome on Windows, desktop"), for support. */
    public function up(): void
    {
        Schema::table('nmi_charge_attempts', function (Blueprint $table) {
            $table->string('client_info')->nullable()->after('three_ds_result');
        });
    }

    public function down(): void
    {
        Schema::table('nmi_charge_attempts', function (Blueprint $table) {
            $table->dropColumn('client_info');
        });
    }
};
