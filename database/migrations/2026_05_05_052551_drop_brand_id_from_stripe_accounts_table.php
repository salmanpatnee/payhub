<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stripe_accounts', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
            $table->dropColumn('brand_id');
        });
    }

    public function down(): void
    {
        Schema::table('stripe_accounts', function (Blueprint $table) {
            $table->foreignId('brand_id')->after('id')->constrained()->cascadeOnDelete();
        });
    }
};
