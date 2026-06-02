<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
        });

        // Backfill existing users so login keeps working after the switch.
        User::query()->whereNull('username')->get()->each(function (User $user) {
            $base = $user->email ? Str::before($user->email, '@') : 'user'.$user->id;
            $username = $base;
            $suffix = 1;

            while (User::query()->where('username', $username)->whereKeyNot($user->id)->exists()) {
                $username = $base.$suffix++;
            }

            $user->forceFill(['username' => $username])->save();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
