<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpgs_accounts', function (Blueprint $table) {
            // Whether the bank has enabled PKR on this merchant; gates PKR payments to this account.
            $table->boolean('supports_pkr')->default(false)->after('gateway_host');
        });
    }

    public function down(): void
    {
        Schema::table('mpgs_accounts', function (Blueprint $table) {
            $table->dropColumn('supports_pkr');
        });
    }
};
