<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stripe_accounts', function (Blueprint $table) {
            $table->string('prefix', 10)->nullable()->after('account_name');
        });

        Schema::table('revolut_accounts', function (Blueprint $table) {
            $table->string('prefix', 10)->nullable()->after('account_name');
        });
    }

    public function down(): void
    {
        Schema::table('stripe_accounts', function (Blueprint $table) {
            $table->dropColumn('prefix');
        });

        Schema::table('revolut_accounts', function (Blueprint $table) {
            $table->dropColumn('prefix');
        });
    }
};
