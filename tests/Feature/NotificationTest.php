<?php

use App\Jobs\SendPaymentNotification;
use App\Mail\PaymentSucceeded;
use App\Models\Brand;
use App\Models\Payment;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

// NOTIFY-02: payment_intent.succeeded dispatches SendPaymentNotification job
it('payment_intent.succeeded dispatches SendPaymentNotification job', function () {
    Queue::fake();

    $account = StripeAccount::factory()->create(['webhook_secret' => 'whsec_test123']);
    $payment = Payment::factory()->create([
        'status' => 'pending',
        'stripe_payment_intent_id' => 'pi_notify_test',
        'stripe_account_id' => $account->id,
    ]);

    // Run HandleStripeWebhookJob synchronously to test the dispatch chain.
    // Queue::fake() intercepts HandleStripeWebhookJob from the HTTP layer, so we test
    // the job's handle() method directly — this is the correct pattern for two-job chains.
    $job = new \App\Jobs\HandleStripeWebhookJob(
        $account->id,
        'payment_intent.succeeded',
        ['id' => 'pi_notify_test'],
    );
    $job->handle();

    Queue::assertPushed(SendPaymentNotification::class);
});

// NOTIFY-01/02 guard: payment_intent.payment_failed does NOT dispatch SendPaymentNotification
it('payment_intent.payment_failed does NOT dispatch SendPaymentNotification', function () {
    Queue::fake();

    $account = StripeAccount::factory()->create(['webhook_secret' => 'whsec_test123']);
    Payment::factory()->create([
        'status' => 'pending',
        'stripe_payment_intent_id' => 'pi_notify_failed',
        'stripe_account_id' => $account->id,
    ]);

    $payload = json_encode([
        'type' => 'payment_intent.payment_failed',
        'data' => ['object' => ['id' => 'pi_notify_failed']],
    ]);

    stripePost("/webhook/stripe/{$account->id}", $payload, fakeStripeSignature($payload, 'whsec_test123'))
        ->assertStatus(200);

    Queue::assertNotPushed(SendPaymentNotification::class);
});

// NOTIFY-01: SendPaymentNotification queues PaymentSucceeded mail to all admins only
it('SendPaymentNotification queues PaymentSucceeded mail to all admins only', function () {
    Mail::fake();

    $admin1 = User::factory()->create();
    $admin1->syncRoles(['admin']);

    $admin2 = User::factory()->create();
    $admin2->syncRoles(['admin']);

    // Regular user — should NOT receive mail
    User::factory()->create()->syncRoles(['user']);

    $payment = Payment::factory()
        ->for(Brand::factory())
        ->for(StripeAccount::factory(), 'stripeAccount')
        ->create(['status' => 'completed']);

    SendPaymentNotification::dispatchSync($payment);

    Mail::assertQueued(PaymentSucceeded::class, 2);
    Mail::assertQueued(PaymentSucceeded::class, fn ($mail) => $mail->hasTo($admin1->email));
    Mail::assertQueued(PaymentSucceeded::class, fn ($mail) => $mail->hasTo($admin2->email));
});

// NOTIFY-01 content (D-03): PaymentSucceeded mailable contains required payment details
it('PaymentSucceeded mailable contains required payment details', function () {
    $brand = Brand::factory()->create(['name' => 'Acme Corp']);
    $stripeAccount = StripeAccount::factory()->create(['account_name' => 'Acme Stripe']);

    $payment = Payment::factory()
        ->for($brand)
        ->for($stripeAccount, 'stripeAccount')
        ->create([
            'client_name' => 'Jane Doe',
            'client_email' => 'jane@example.com',
            'amount' => 10000,
            'currency' => 'usd',
            'service' => 'Web Design',
            'package' => 'standard',
        ]);

    $mailable = new PaymentSucceeded($payment);

    $mailable->assertSeeInHtml('Jane Doe');
    $mailable->assertSeeInHtml('jane@example.com');
    $mailable->assertSeeInHtml('Acme Corp');
    $mailable->assertSeeInHtml('Acme Stripe');
    $mailable->assertSeeInHtml('100.00');
    $mailable->assertSeeInHtml('Web Design');
});
