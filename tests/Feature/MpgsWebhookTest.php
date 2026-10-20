<?php

use App\Jobs\HandleMpgsWebhookJob;
use App\Jobs\SendPaymentNotification;
use App\Models\MpgsAccount;
use App\Models\MpgsChargeAttempt;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
});

/**
 * @return array{0: MpgsAccount, 1: Payment, 2: MpgsChargeAttempt}
 */
function mpgsWebhookSetup(?string $secret = 'notify-secret-value'): array
{
    $account = MpgsAccount::factory()->create();

    if ($secret !== null) {
        $account->webhook_secret = $secret;
        $account->save();
    }

    $payment = Payment::factory()->mpgs()->create([
        'mpgs_account_id' => $account->id,
        'status' => 'pending',
        'amount' => 1500,
        'currency' => 'usd',
    ]);
    $attempt = MpgsChargeAttempt::factory()->pending()->create([
        'payment_id' => $payment->id,
        'transaction_id' => 'txn-pay',
    ]);

    return [$account, $payment, $attempt];
}

function mpgsNotification(MpgsChargeAttempt $attempt, array $overrides = []): array
{
    return array_replace_recursive([
        'result' => 'SUCCESS',
        'order' => ['id' => $attempt->order_id],
        'transaction' => ['id' => 'txn-pay'],
    ], $overrides);
}

function mpgsHooked(MpgsAccount $account, array $body, ?string $secret = 'notify-secret-value')
{
    $headers = $secret === null ? [] : ['X-Notification-Secret' => $secret];

    return test()->postJson(route('webhook.mpgs', $account), $body, $headers);
}

// AC-9: no stored secret means every delivery is refused.
it('rejects every delivery when the account has no secret set', function () {
    Queue::fake();
    [$account, , $attempt] = mpgsWebhookSetup(null);

    mpgsHooked($account, mpgsNotification($attempt), 'anything')->assertForbidden();
    mpgsHooked($account, mpgsNotification($attempt), null)->assertForbidden();

    Queue::assertNothingPushed();
});

it('rejects a missing or wrong secret header', function () {
    Queue::fake();
    [$account, , $attempt] = mpgsWebhookSetup();

    mpgsHooked($account, mpgsNotification($attempt), null)->assertUnauthorized();
    mpgsHooked($account, mpgsNotification($attempt), 'wrong-secret')->assertUnauthorized();

    Queue::assertNothingPushed();
    expect($attempt->refresh()->status)->toBe('pending');
});

it('404s an unknown account', function () {
    $this->postJson('/webhook/mpgs/999999', [], ['X-Notification-Secret' => 'x'])->assertNotFound();
});

it('queues one job for a valid delivery and only that', function () {
    Queue::fake();
    [$account, , $attempt] = mpgsWebhookSetup();

    mpgsHooked($account, mpgsNotification($attempt))->assertOk();

    Queue::assertPushed(HandleMpgsWebhookJob::class, 1);
    Queue::assertPushed(HandleMpgsWebhookJob::class, fn ($job) => $job->attemptId === $attempt->id);
    expect($attempt->refresh()->status)->toBe('pending'); // the controller changes nothing itself
});

it('drops a duplicate delivery but accepts a different notification for the same order', function () {
    Queue::fake();
    [$account, , $attempt] = mpgsWebhookSetup();

    mpgsHooked($account, mpgsNotification($attempt))->assertOk();
    mpgsHooked($account, mpgsNotification($attempt))->assertOk(); // same key
    Queue::assertPushed(HandleMpgsWebhookJob::class, 1);

    // 3DS sends a second notification that differs by transaction id.
    mpgsHooked($account, mpgsNotification($attempt, ['transaction' => ['id' => 'txn-auth']]))->assertOk();
    Queue::assertPushed(HandleMpgsWebhookJob::class, 2);

    $this->assertDatabaseCount('processed_mpgs_events', 2);
});

it('acknowledges but ignores an order that is unknown or belongs to another account', function () {
    Queue::fake();
    [$account, , $attempt] = mpgsWebhookSetup();
    [, , $foreignAttempt] = mpgsWebhookSetup('other-secret');

    mpgsHooked($account, ['result' => 'SUCCESS', 'order' => ['id' => 'does-not-exist'], 'transaction' => ['id' => 't']])->assertOk();
    mpgsHooked($account, mpgsNotification($foreignAttempt))->assertOk();

    Queue::assertNothingPushed();
    $this->assertDatabaseCount('processed_mpgs_events', 0);
});

it('ignores a delivery for an attempt that is no longer pending', function () {
    Queue::fake();
    [$account, , $attempt] = mpgsWebhookSetup();
    $attempt->update(['status' => 'approved']);

    mpgsHooked($account, mpgsNotification($attempt))->assertOk();

    Queue::assertNothingPushed();
});

it('rejects a body that is not JSON and acknowledges one with no order id', function () {
    Queue::fake();
    [$account] = mpgsWebhookSetup();

    $this->call('POST', route('webhook.mpgs', $account), [], [], [], ['HTTP_X-Notification-Secret' => 'notify-secret-value', 'CONTENT_TYPE' => 'application/json'], 'not json')
        ->assertStatus(400);
    mpgsHooked($account, ['result' => 'SUCCESS'])->assertOk();

    Queue::assertNothingPushed();
});

it('is excluded from CSRF', function () {
    expect(file_get_contents(base_path('bootstrap/app.php')))->toContain("'webhook/mpgs/*'");
});

it('shows the real webhook URL on the admin edit page', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $account = MpgsAccount::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.mpgs-accounts.edit', $account))
        ->assertInertia(fn ($page) => $page->where('webhookUrl', route('webhook.mpgs', $account)));
});

// AC-9: the job re-reads the order and never trusts the body.
it('completes the payment from what the gateway says, not what the notification said', function () {
    Queue::fake([SendPaymentNotification::class]);
    [, $payment, $attempt] = mpgsWebhookSetup();

    Http::fake(['*/order/*' => Http::response([
        'status' => 'CAPTURED', 'totalCapturedAmount' => '15.00', 'currency' => 'USD',
        'transaction' => [['result' => 'SUCCESS', 'response' => ['gatewayCode' => 'APPROVED'], 'transaction' => ['id' => 'txn-pay', 'type' => 'PAYMENT']]],
    ], 200)]);

    (new HandleMpgsWebhookJob($attempt->id))->handle();

    expect($payment->refresh()->status)->toBe('completed');
    expect($attempt->refresh()->status)->toBe('approved');
    Queue::assertPushed(SendPaymentNotification::class, 1);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET');
});

it('does not complete the payment when the notification claims success but the gateway reports a decline', function () {
    [, $payment, $attempt] = mpgsWebhookSetup();

    Http::fake(['*/order/*' => Http::response([
        'status' => 'FAILED', 'totalCapturedAmount' => '0.00', 'currency' => 'USD',
        'transaction' => [['result' => 'FAILURE', 'response' => ['gatewayCode' => 'DECLINED'], 'transaction' => ['id' => 'txn-pay', 'type' => 'PAYMENT']]],
    ], 200)]);

    (new HandleMpgsWebhookJob($attempt->id))->handle();

    expect($payment->refresh()->status)->toBe('failed');
    expect($attempt->refresh()->status)->toBe('declined');
});

it('throws so it retries when the gateway shows no settled PAY yet, leaving everything untouched', function () {
    [, $payment, $attempt] = mpgsWebhookSetup();

    Http::fake(['*/order/*' => Http::response(['result' => 'ERROR'], 404)]);

    expect(fn () => (new HandleMpgsWebhookJob($attempt->id))->handle())->toThrow(RuntimeException::class);

    expect($payment->refresh()->status)->toBe('pending');
    expect($attempt->refresh()->status)->toBe('pending');
});

it('does nothing for an attempt already resolved, and leaves it to the resolver when retries run out', function () {
    Http::fake();
    [, $payment, $attempt] = mpgsWebhookSetup();
    $attempt->update(['status' => 'approved']);

    (new HandleMpgsWebhookJob($attempt->id))->handle();
    Http::assertNothingSent();

    $attempt->update(['status' => 'pending']);
    (new HandleMpgsWebhookJob($attempt->id))->failed(new RuntimeException('x'));
    expect($attempt->refresh()->status)->toBe('pending'); // not abandoned here
});
