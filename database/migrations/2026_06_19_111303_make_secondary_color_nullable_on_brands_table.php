<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('secondary_color', 7)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('secondary_color', 7)->nullable(false)->change();
        });
    }
};
