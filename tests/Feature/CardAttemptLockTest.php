<?php

use App\Jobs\SendPaymentNotification;
use App\Models\AuthorizenetChargeAttempt;
use App\Models\MpgsAccount;
use App\Models\MpgsChargeAttempt;
use App\Models\Payment;
use App\Models\User;
use App\Services\Authorizenet\AuthorizenetChargeClassifier;
use App\Services\Authorizenet\AuthorizenetChargeResolver;
use App\Services\CardLock\CardLockGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Bus::fake([SendPaymentNotification::class]);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    foreach (['admin', 'agent', 'account'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

function lockUser(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

function lockAnetPayment(array $attributes = []): Payment
{
    return Payment::factory()->authorizenet()->create(array_merge([
        'amount' => 1234,
        'currency' => 'usd',
        'status' => 'pending',
    ], $attributes));
}

/** @return array<string, string> */
function lockChargeBody(): array
{
    return [
        'data_descriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT',
        'data_value' => 'opaque-code-from-acceptjs',
        'postal_code' => '90210',
    ];
}

/** Fake a createTransaction reply: response code 2 with error code 2 is a plain decline. */
function lockFakeAnetReply(string $code, string $errorCode): void
{
    Http::fake(['*' => Http::response("\xEF\xBB\xBF".json_encode([
        'transactionResponse' => [
            'responseCode' => $code,
            'errors' => [['errorCode' => $errorCode, 'errorText' => 'gateway text']],
        ],
        'messages' => ['resultCode' => 'Error'],
    ]), 200)]);
}

function lockMpgsPayment(): Payment
{
    $account = MpgsAccount::factory()->create(['gateway_host' => 'https://test-gateway.example.com']);

    return Payment::factory()->mpgs()->create([
        'mpgs_account_id' => $account->id,
        'status' => 'pending',
        'amount' => 1500,
        'currency' => 'usd',
    ]);
}

// ── Authorize.net ────────────────────────────────────────────────────────────

it('locks an Authorize.net payment on the third decline and refuses further charges', function () {
    $payment = lockAnetPayment();
    lockFakeAnetReply('2', '2');

    $this->postJson(route('pay.authorizenet.charge', $payment), lockChargeBody())->assertOk()->assertJsonPath('outcome', 'declined');
    $this->postJson(route('pay.authorizenet.charge', $payment), lockChargeBody())->assertOk()->assertJsonPath('outcome', 'declined');
    expect($payment->fresh()->isCardLocked())->toBeFalse();

    $this->postJson(route('pay.authorizenet.charge', $payment), lockChargeBody())
        ->assertStatus(423)
        ->assertJsonPath('outcome', 'locked');

    $payment->refresh();
    expect($payment->isCardLocked())->toBeTrue()
        ->and($payment->card_locked_until->isBetween(now()->addMinutes(59), now()->addMinutes(61)))->toBeTrue();

    // A fourth try is refused without calling the gateway.
    Http::fake();
    $this->postJson(route('pay.authorizenet.charge', $payment), lockChargeBody())->assertStatus(423);
    Http::assertNothingSent();
    expect($payment->authorizenetChargeAttempts()->count())->toBe(3);
});

it('does not count error replies toward the Authorize.net lock', function () {
    $payment = lockAnetPayment();
    lockFakeAnetReply('3', '99');

    foreach (range(1, 3) as $_) {
        $this->postJson(route('pay.authorizenet.charge', $payment), lockChargeBody())->assertStatus(502);
    }

    expect($payment->fresh()->isCardLocked())->toBeFalse();
});

it('starts a fresh count after the lock ends', function () {
    $payment = lockAnetPayment();
    lockFakeAnetReply('2', '2');

    foreach (range(1, 3) as $_) {
        $this->postJson(route('pay.authorizenet.charge', $payment), lockChargeBody());
    }
    expect($payment->fresh()->isCardLocked())->toBeTrue();

    $this->travel(61)->minutes();
    Cache::flush(); // the per hour throttle lives in the cache

    expect($payment->fresh()->isCardLocked())->toBeFalse();

    $this->postJson(route('pay.authorizenet.charge', $payment), lockChargeBody())->assertOk()->assertJsonPath('outcome', 'declined');
    $this->postJson(route('pay.authorizenet.charge', $payment), lockChargeBody())->assertOk()->assertJsonPath('outcome', 'declined');
    expect($payment->fresh()->isCardLocked())->toBeFalse();

    $this->postJson(route('pay.authorizenet.charge', $payment), lockChargeBody())->assertStatus(423);
});

it('counts a decline found late by the resolver', function () {
    $payment = lockAnetPayment();
    AuthorizenetChargeAttempt::factory()->count(2)->sequence(['sequence' => 1], ['sequence' => 2])->create([
        'payment_id' => $payment->id,
        'status' => 'declined',
    ]);
    AuthorizenetChargeAttempt::factory()->create([
        'payment_id' => $payment->id,
        'sequence' => 3,
        'status' => 'unclear',
        'transaction_id' => null,
    ]);

    app(AuthorizenetChargeResolver::class)->apply($payment, AuthorizenetChargeClassifier::DECLINED, '60000009');

    expect($payment->fresh()->isCardLocked())->toBeTrue();
});

// ── MPGS ─────────────────────────────────────────────────────────────────────

it('locks an MPGS payment on the third decline and refuses session and pay', function () {
    $payment = lockMpgsPayment();
    Http::fake(['*' => Http::response(['result' => 'FAILURE', 'response' => ['gatewayCode' => 'DECLINED']], 200)]);

    foreach (range(1, 3) as $sequence) {
        $attempt = MpgsChargeAttempt::factory()->prepared()->create(['payment_id' => $payment->id, 'sequence' => $sequence]);
        $response = $this->postJson(route('pay.mpgs.pay', $payment), ['attempt_id' => $attempt->id]);

        if ($sequence < 3) {
            $response->assertOk()->assertJsonPath('outcome', 'declined');
        } else {
            $response->assertStatus(423)->assertJsonPath('outcome', 'locked');
        }
    }

    expect($payment->fresh()->isCardLocked())->toBeTrue();

    Http::fake();
    $this->postJson(route('pay.mpgs.session', $payment))->assertStatus(423)->assertJsonPath('outcome', 'locked');
    $this->postJson(route('pay.mpgs.pay', $payment), ['attempt_id' => $attempt->id])->assertStatus(423);
    Http::assertNothingSent();
});

it('does not count MPGS hard errors or abandoned sessions', function () {
    $payment = lockMpgsPayment();
    MpgsChargeAttempt::factory()->hardError()->create(['payment_id' => $payment->id, 'sequence' => 1]);
    MpgsChargeAttempt::factory()->hardError()->create(['payment_id' => $payment->id, 'sequence' => 2]);
    MpgsChargeAttempt::factory()->abandoned()->create(['payment_id' => $payment->id, 'sequence' => 3]);

    app(CardLockGuard::class)->recordFailure($payment);

    expect($payment->fresh()->isCardLocked())->toBeFalse();
});

it('does nothing for other providers', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);

    app(CardLockGuard::class)->recordFailure($payment);

    expect($payment->fresh()->card_locked_until)->toBeNull();
});

// ── Pay page ─────────────────────────────────────────────────────────────────

it('passes the lock end time to the pay page', function () {
    $payment = lockMpgsPayment();
    $payment->forceFill(['card_locked_until' => now()->addMinutes(30)])->save();

    $this->get(route('pay.show', $payment))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ClientPayment/PayMpgs')
            ->where('lockedUntil', fn ($value) => $value !== null));
});

// ── Admin reset ──────────────────────────────────────────────────────────────

it('lets an admin release a lock and starts a fresh count', function () {
    $payment = lockAnetPayment(['card_locked_until' => now()->addMinutes(30)]);

    $this->actingAs(lockUser('admin'))
        ->patch(route('payments.reset-lock', $payment))
        ->assertRedirect();

    $payment->refresh();
    expect($payment->card_locked_until)->toBeNull()
        ->and($payment->card_attempts_since)->not->toBeNull();
});

it('forbids agents and account users from releasing a lock', function (string $role) {
    $payment = lockAnetPayment(['card_locked_until' => now()->addMinutes(30)]);

    $this->actingAs(lockUser($role))
        ->patch(route('payments.reset-lock', $payment))
        ->assertForbidden();

    expect($payment->fresh()->isCardLocked())->toBeTrue();
})->with(['agent', 'account']);

// ── Admin views ──────────────────────────────────────────────────────────────

it('lists only currently locked payments on the admin dashboard', function () {
    $locked = lockAnetPayment(['card_locked_until' => now()->addMinutes(30)]);
    lockAnetPayment(['card_locked_until' => now()->subMinutes(5)]);
    lockAnetPayment();

    $this->actingAs(lockUser('admin'))->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('lockedPayments', 1)
            ->where('lockedPayments.0.uuid', $locked->uuid)
            ->where('lockedPayments.0.provider', 'authorizenet'));
});

it('does not send locked payments to account users on the dashboard', function () {
    lockAnetPayment(['card_locked_until' => now()->addMinutes(30)]);

    $this->actingAs(lockUser('account'))->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('lockedPayments'));
});

it('marks locked payments in the admin payments list only', function () {
    $agent = lockUser('agent');
    $payment = lockAnetPayment(['user_id' => $agent->id, 'card_locked_until' => now()->addMinutes(30)]);

    $this->actingAs(lockUser('admin'))->get(route('payments.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payments.data.0.uuid', $payment->uuid)
            ->where('payments.data.0.card_locked_until', fn ($value) => $value !== null));

    $this->actingAs($agent)->get(route('payments.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payments.data.0.card_locked_until', null));
});

// ── Same card retried (gateway duplicate window) ─────────────────────────────

/** A createTransaction reply carrying a transaction id (decline, or the duplicate echo). */
function lockFakeAnetReplyWithId(string $code, string $errorCode, string $transId): void
{
    Http::fake(['*' => Http::response("\xEF\xBB\xBF".json_encode([
        'transactionResponse' => [
            'responseCode' => $code,
            'transId' => $transId,
            'errors' => [['errorCode' => $errorCode, 'errorText' => 'gateway text']],
        ],
        'messages' => ['resultCode' => 'Error'],
    ]), 200)]);
}

it('treats a duplicate of a declined charge as a decline, not an unclear answer', function () {
    $payment = lockAnetPayment();

    lockFakeAnetReplyWithId('2', '2', '80061533312');
    $this->postJson(route('pay.authorizenet.charge', $payment), lockChargeBody())->assertJsonPath('outcome', 'declined');

    // Same card again: the gateway answers "duplicate" (code 3, error 11) with the first transaction id.
    lockFakeAnetReplyWithId('3', '11', '80061533312');
    $this->postJson(route('pay.authorizenet.charge', $payment), lockChargeBody())
        ->assertOk()
        ->assertJsonPath('outcome', 'declined');

    expect($payment->authorizenetChargeAttempts()->orderBy('sequence')->pluck('status')->all())->toBe(['declined', 'declined'])
        ->and(Cache::has("authorizenet:pause:{$payment->id}"))->toBeFalse();
});

it('locks after the third try even when the same card is used every time', function () {
    $payment = lockAnetPayment();

    lockFakeAnetReplyWithId('2', '2', '80061533312');
    $this->postJson(route('pay.authorizenet.charge', $payment), lockChargeBody())->assertJsonPath('outcome', 'declined');

    lockFakeAnetReplyWithId('3', '11', '80061533312');
    $this->postJson(route('pay.authorizenet.charge', $payment), lockChargeBody())->assertJsonPath('outcome', 'declined');
    $this->postJson(route('pay.authorizenet.charge', $payment), lockChargeBody())
        ->assertStatus(423)
        ->assertJsonPath('outcome', 'locked');

    expect($payment->fresh()->isCardLocked())->toBeTrue();
});

it('keeps a duplicate of a charge that was not declined as unclear', function () {
    $payment = lockAnetPayment();

    lockFakeAnetReplyWithId('3', '11', '80061599999'); // no earlier declined attempt carries this id
    $this->postJson(route('pay.authorizenet.charge', $payment), lockChargeBody())
        ->assertStatus(200)
        ->assertJsonPath('outcome', 'unclear');

    expect($payment->fresh()->isCardLocked())->toBeFalse();
});
