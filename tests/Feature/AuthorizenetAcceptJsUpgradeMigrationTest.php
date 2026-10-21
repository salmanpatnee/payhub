<?php

use App\Models\Payment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function runAcceptJsUpgrade(): void
{
    (require database_path('migrations/2026_10_21_000005_upgrade_authorizenet_to_accept_js.php'))->up();
}

it('leaves a database that already has the new shape untouched', function () {
    runAcceptJsUpgrade();
    runAcceptJsUpgrade();

    expect(Schema::hasColumn('authorizenet_accounts', 'public_client_key'))->toBeTrue()
        ->and(Schema::hasColumns('payments', ['authorizenet_first_attempt_at', 'authorizenet_last_attempt_at']))->toBeTrue()
        ->and(Schema::hasColumn('payments', 'authorizenet_token_issued_at'))->toBeFalse();
});

it('upgrades a database that ran the old hosted iframe migrations', function () {
    $payment = Payment::factory()->authorizenet()->create(['authorizenet_first_attempt_at' => null, 'authorizenet_last_attempt_at' => null]);

    // Put the tables back into the old shape.
    Schema::table('authorizenet_accounts', fn (Blueprint $t) => $t->dropColumn('public_client_key'));
    Schema::table('payments', function (Blueprint $t) {
        $t->timestamp('authorizenet_token_issued_at')->nullable();
        $t->timestamp('authorizenet_token_last_issued_at')->nullable();
    });

    DB::table('payments')->where('id', $payment->id)->update([
        'authorizenet_token_issued_at' => '2026-10-01 10:00:00',
        'authorizenet_token_last_issued_at' => '2026-10-01 10:05:00',
    ]);

    runAcceptJsUpgrade();

    $row = DB::table('payments')->where('id', $payment->id)->first();

    expect(Schema::hasColumn('authorizenet_accounts', 'public_client_key'))->toBeTrue()
        ->and(Schema::hasColumn('payments', 'authorizenet_token_issued_at'))->toBeFalse()
        ->and(Schema::hasColumn('payments', 'authorizenet_token_last_issued_at'))->toBeFalse()
        ->and($row->authorizenet_first_attempt_at)->toBe('2026-10-01 10:00:00')
        ->and($row->authorizenet_last_attempt_at)->toBe('2026-10-01 10:05:00');
});
