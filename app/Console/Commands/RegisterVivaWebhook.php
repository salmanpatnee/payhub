<?php

namespace App\Console\Commands;

use App\Models\VivaAccount;
use App\Services\Viva\VivaClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class RegisterVivaWebhook extends Command
{
    protected $signature = 'viva:register-webhook
        {account? : VivaAccount id (defaults to the only account when there is one)}
        {--url= : Override the webhook URL (use when APP_URL is not the public URL, e.g. a tunnel)}
        {--events=1796 : Comma-separated Viva EventTypeIds (1796 = TransactionPaymentCreated)}';

    protected $description = 'Register the Viva webhook subscription for an account and store its verification key';

    /**
     * NOTE: VivaClient::registerWebhook()'s exact request/response shape is
     * unconfirmed against a live sandbox — see .planning/research/VIVA_PAYMENTS.md
     * §2 and the TODO on VivaClient::registerWebhook(). Confirm and adjust the
     * response-key extraction below before relying on this command.
     */
    public function handle(): int
    {
        $account = $this->resolveAccount();

        if (! $account instanceof VivaAccount) {
            return self::FAILURE;
        }

        $url = $this->option('url') ?: route('webhook.viva', $account);

        if (! str_starts_with($url, 'https://')) {
            $this->error("The webhook URL must be HTTPS and publicly reachable — got: {$url}");
            $this->line("Pass --url=https://your-public-domain/webhook/viva/{$account->id}");

            return self::FAILURE;
        }

        $eventTypeIds = array_values(array_filter(array_map(
            fn (string $id): int => (int) trim($id),
            explode(',', (string) $this->option('events')),
        )));

        $client = new VivaClient(
            $account->client_id,
            $account->client_secret,
            $account->merchant_id,
            $account->api_key,
            $account->source_code,
            $account->environment,
        );

        $webhook = $client->registerWebhook($url, $eventTypeIds);

        // webhook_verification_key is not mass-assignable (encrypted cast) — set directly.
        $account->webhook_verification_key = $webhook['Key'] ?? $webhook['verificationKey'] ?? null;
        $account->save();

        $this->info('Webhook registered and verification key stored.');
        $this->table(['Field', 'Value'], [
            ['Account', "{$account->account_name} (#{$account->id})"],
            ['URL', $url],
            ['Event Type IDs', implode(', ', $eventTypeIds)],
            ['Environment', $account->environment],
        ]);

        return self::SUCCESS;
    }

    private function resolveAccount(): ?VivaAccount
    {
        $id = $this->argument('account');

        if ($id !== null) {
            $account = VivaAccount::find($id);

            if (! $account) {
                $this->error("No Viva account with id {$id}.");
            }

            return $account;
        }

        /** @var Collection<int, VivaAccount> $accounts */
        $accounts = VivaAccount::all();

        if ($accounts->isEmpty()) {
            $this->error('No Viva accounts exist — create one first.');

            return null;
        }

        if ($accounts->count() > 1) {
            $this->error('Multiple Viva accounts exist — pass the id, e.g. viva:register-webhook 1');
            $this->table(
                ['id', 'name', 'active'],
                $accounts->map(fn (VivaAccount $a): array => [$a->id, $a->account_name, $a->is_active ? 'yes' : 'no'])->all(),
            );

            return null;
        }

        return $accounts->first();
    }
}
