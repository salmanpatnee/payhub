<?php

namespace App\Console\Commands;

use App\Models\RevolutAccount;
use App\Services\Revolut\RevolutClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class RegisterRevolutWebhook extends Command
{
    protected $signature = 'revolut:register-webhook
        {account? : RevolutAccount id (defaults to the only account when there is one)}
        {--url= : Override the webhook URL (use when APP_URL is not the public URL, e.g. a tunnel)}
        {--events=ORDER_COMPLETED,ORDER_PAYMENT_FAILED : Comma-separated event types}';

    protected $description = 'Register the Revolut Merchant webhook for an account and store its signing secret';

    public function handle(): int
    {
        $account = $this->resolveAccount();

        if (! $account instanceof RevolutAccount) {
            return self::FAILURE;
        }

        $url = $this->option('url') ?: route('webhook.revolut', $account);

        if (! str_starts_with($url, 'https://')) {
            $this->error("The webhook URL must be HTTPS and publicly reachable — got: {$url}");
            $this->line("Pass --url=https://your-public-domain/webhook/revolut/{$account->id}");

            return self::FAILURE;
        }

        $events = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('events')))));

        $client = app()->make(RevolutClient::class, ['secretKey' => $account->secret_key]);

        // The signing secret is returned ONLY on creation, so clear any existing
        // webhook for this exact URL and re-create to capture a fresh secret.
        foreach ($client->listWebhooks() as $hook) {
            if (($hook['url'] ?? null) === $url && isset($hook['id'])) {
                $client->deleteWebhook($hook['id']);
                $this->line("Removed existing webhook {$hook['id']} for this URL.");
            }
        }

        $webhook = $client->createWebhook($url, $events);

        // webhook_secret is not mass-assignable (encrypted cast) — set directly.
        $account->webhook_secret = $webhook['signing_secret'];
        $account->save();

        $this->info('Webhook registered and signing secret stored.');
        $this->table(['Field', 'Value'], [
            ['Account', "{$account->account_name} (#{$account->id})"],
            ['Webhook ID', $webhook['id'] ?? '—'],
            ['URL', $url],
            ['Events', implode(', ', $events)],
            ['Environment', config('services.revolut.environment')],
        ]);

        return self::SUCCESS;
    }

    private function resolveAccount(): ?RevolutAccount
    {
        $id = $this->argument('account');

        if ($id !== null) {
            $account = RevolutAccount::find($id);

            if (! $account) {
                $this->error("No Revolut account with id {$id}.");
            }

            return $account;
        }

        /** @var Collection<int, RevolutAccount> $accounts */
        $accounts = RevolutAccount::all();

        if ($accounts->isEmpty()) {
            $this->error('No Revolut accounts exist — create one first.');

            return null;
        }

        if ($accounts->count() > 1) {
            $this->error('Multiple Revolut accounts exist — pass the id, e.g. revolut:register-webhook 1');
            $this->table(
                ['id', 'name', 'active'],
                $accounts->map(fn (RevolutAccount $a): array => [$a->id, $a->account_name, $a->is_active ? 'yes' : 'no'])->all(),
            );

            return null;
        }

        return $accounts->first();
    }
}
