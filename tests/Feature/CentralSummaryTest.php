<?php

use App\Models\Payment;
use App\Services\CentralSummary;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

const CENTRAL_TOKEN = 'a-test-token-value';
const CENTRAL_URL = '/api/central/v1/summary';

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('central-ip');
    config([
        'central.token_hash_current' => hash('sha256', CENTRAL_TOKEN),
        'central.token_hash_next' => null,
        'central.require_https' => false,
        'central.deployment_key' => 'payhub-test',
        'central.timezone' => 'Asia/Karachi',
        'app.timezone' => 'UTC',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-26 09:00:00', 'UTC'));
});

function centralGet(?string $token = CENTRAL_TOKEN)
{
    return test()->getJson(CENTRAL_URL, $token ? ['Authorization' => 'Bearer '.$token] : []);
}

function keyShape(array $data): array
{
    $keys = [];
    foreach ($data as $key => $value) {
        $keys[$key] = is_array($value) && array_is_list($value) === false ? keyShape($value) : null;
    }
    ksort($keys);

    return $keys;
}

it('returns link counts and money for each window', function () {
    // Karachi is UTC+5: today = 2026-10-25 19:00 UTC .. 2026-10-26 19:00 UTC
    Payment::factory()->create(['status' => 'completed', 'currency' => 'usd', 'amount' => 1000, 'created_at' => '2026-10-26 02:00:00', 'paid_at' => '2026-10-26 03:00:00']);
    Payment::factory()->create(['status' => 'completed', 'currency' => 'gbp', 'amount' => 500, 'created_at' => '2026-10-01 02:00:00', 'paid_at' => '2026-10-26 04:00:00']);
    Payment::factory()->create(['status' => 'pending', 'currency' => 'usd', 'amount' => 700, 'created_at' => '2026-10-26 01:00:00', 'expires_at' => '2026-10-26 02:00:00']);
    Payment::factory()->create(['status' => 'failed', 'currency' => 'usd', 'amount' => 300, 'created_at' => '2026-10-26 01:00:00']);
    Payment::factory()->create(['status' => 'cancelled', 'currency' => 'usd', 'amount' => 200, 'created_at' => '2026-03-01 01:00:00']);

    $response = centralGet()->assertOk();
    $response->assertJsonPath('contract_version', 'v1')
        ->assertJsonPath('deployment.key', 'payhub-test')
        ->assertJsonPath('timezone', 'Asia/Karachi')
        ->assertJsonPath('windows.all_time.from', null)
        ->assertJsonPath('windows.all_time.links', ['total' => 5, 'pending' => 1, 'completed' => 2, 'failed' => 1, 'cancelled' => 1, 'expired' => 1])
        ->assertJsonPath('windows.today.links.total', 3)
        ->assertJsonPath('windows.today.money.completed', ['usd' => 1000, 'gbp' => 500, 'pkr' => 0])
        ->assertJsonPath('windows.today.money.paid_count', 2)
        ->assertJsonPath('windows.today.money.pending', ['usd' => 700, 'gbp' => 0, 'pkr' => 0])
        ->assertJsonPath('windows.this_month.links.total', 4);
});

it('cuts windows in the central timezone whatever the app timezone is', function () {
    // 2026-10-25 18:30 UTC is 23:30 Karachi on the 25th: outside today (26th). 19:30 UTC is 00:30 on the 26th: inside.
    Payment::factory()->create(['status' => 'completed', 'currency' => 'usd', 'amount' => 100, 'paid_at' => '2026-10-25 18:30:00']);
    Payment::factory()->create(['status' => 'completed', 'currency' => 'usd', 'amount' => 250, 'paid_at' => '2026-10-25 19:30:00']);

    centralGet()->assertOk()
        ->assertJsonPath('windows.today.money.completed.usd', 250)
        ->assertJsonPath('windows.today.money.paid_count', 1)
        ->assertJsonPath('windows.today.from', '2026-10-26T00:00:00+05:00');
});

it('skips deleted payments and leaks no personal data', function () {
    $kept = Payment::factory()->create(['status' => 'pending', 'client_email' => 'secret@example.com', 'client_name' => 'Secret Person']);
    Payment::factory()->create(['status' => 'pending'])->delete();

    $response = centralGet()->assertOk()->assertJsonPath('windows.all_time.links.total', 1);

    expect($response->getContent())
        ->not->toContain('secret@example.com')
        ->not->toContain('Secret Person')
        ->not->toContain((string) $kept->uuid);
});

it('rejects a missing or wrong token', function (?string $token) {
    centralGet($token)->assertUnauthorized()->assertExactJson(['error' => 'unauthenticated']);
})->with([null, 'wrong-token']);

it('accepts the next token slot', function () {
    config(['central.token_hash_current' => hash('sha256', 'other'), 'central.token_hash_next' => hash('sha256', 'next-token')]);

    centralGet('next-token')->assertOk();
});

it('answers 404 when no token hash is configured', function () {
    config(['central.token_hash_current' => null, 'central.token_hash_next' => null]);

    centralGet()->assertNotFound();
});

it('answers 403 on plain http when https is required, before the token check', function () {
    config(['central.require_https' => true]);

    $this->getJson('http://localhost'.CENTRAL_URL, ['Authorization' => 'Bearer wrong'])->assertForbidden()->assertExactJson(['error' => 'https_required']);
    $this->getJson('https://localhost'.CENTRAL_URL, ['Authorization' => 'Bearer '.CENTRAL_TOKEN])->assertOk();
});

it('limits each token to 6 calls a minute', function () {
    foreach (range(1, 6) as $i) {
        centralGet()->assertOk();
    }

    centralGet()->assertStatus(429)->assertHeader('Retry-After');
});

it('limits each IP to 30 calls a minute before the token check', function () {
    foreach (range(1, 30) as $i) {
        centralGet('wrong')->assertUnauthorized();
    }

    centralGet('wrong')->assertStatus(429);
});

it('serves a cached copy with no payment queries', function () {
    $first = centralGet()->assertOk()->json('generated_at');
    $this->travel(20)->seconds();

    DB::enableQueryLog();
    $second = centralGet()->json('generated_at');
    $paymentQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"payments"'));

    expect($second)->toBe($first)->and($paymentQueries)->toBeEmpty();
});

it('writes the audit context with result, slot and ip but never the token', function () {
    $captured = [];
    $channel = Mockery::mock();
    $channel->shouldReceive('info')->andReturnUsing(function ($message, $context) use (&$captured) {
        $captured[] = $context;
    });
    Log::shouldReceive('channel')->with('central_audit')->andReturn($channel);

    centralGet()->assertOk();
    centralGet('wrong')->assertUnauthorized();

    expect($captured[0])->toMatchArray(['result' => 'ok', 'slot' => 'current'])->toHaveKeys(['ip', 'duration_ms'])
        ->and($captured[1])->toMatchArray(['result' => 'unauthenticated', 'slot' => null])
        ->and(json_encode($captured))->not->toContain(CENTRAL_TOKEN);
});

it('answers 503 json when the summary cannot be built', function () {
    $this->mock(CentralSummary::class)->shouldReceive('build')->andThrow(new RuntimeException('db down'));

    centralGet()->assertStatus(503)->assertExactJson(['error' => 'summary_unavailable']);
});

it('keeps the real reply in step with the sample json', function () {
    $sample = json_decode(file_get_contents(base_path('docs/integrations/central-dashboard-sample.json')), true);
    $real = centralGet()->assertOk()->json();

    expect(keyShape($real))->toBe(keyShape($sample));
});

it('prints a token and a matching hash', function () {
    Artisan::call('central:make-token');
    $output = Artisan::output();

    preg_match('/^([0-9a-f]{64})\s*$/m', $output, $token);
    preg_match('/CENTRAL_API_TOKEN_HASH_CURRENT=([0-9a-f]{64})/', $output, $hash);

    expect($token)->toHaveCount(2)->and($hash[1])->toBe(hash('sha256', $token[1]));
});
