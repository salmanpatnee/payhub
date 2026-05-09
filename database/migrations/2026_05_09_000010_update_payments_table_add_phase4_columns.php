<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('client_name')->after('client_email');
            $table->string('service')->nullable()->after('client_name');
            $table->enum('package', ['basic', 'standard', 'premium', 'platinum', 'diamond'])
                  ->nullable()->after('service');
            $table->text('note')->nullable()->after('package');
            $table->dropColumn('description');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['client_name', 'service', 'package', 'note']);
            $table->string('description')->nullable()->after('currency');
        });
    }
};
