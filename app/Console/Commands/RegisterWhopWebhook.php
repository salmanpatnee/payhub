<?php

namespace App\Console\Commands;

use App\Models\WhopAccount;
use App\Services\Whop\WhopClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;

class RegisterWhopWebhook extends Command
{
    protected $signature = 'whop:register-webhook
        {account? : WhopAccount id (defaults to the only account when there is one)}
        {--url= : Public https webhook URL, for example https://your-domain/webhook/whop/1}';

    protected $description = 'Register the Whop webhook for an account and store its signing secret';

    private const EVENTS = ['payment.succeeded', 'payment.failed'];

    public function handle(): int
    {
        $url = (string) $this->option('url');

        if (! str_starts_with($url, 'https://')) {
            $this->error('The --url option is required and must be an https URL that Whop can reach.');

            return self::FAILURE;
        }

        $account = $this->resolveAccount();

        if (! $account instanceof WhopAccount) {
            return self::FAILURE;
        }

        if (! empty($account->webhook_secret)
            && ! $this->confirm('This account already has a webhook secret. Registering again replaces it. Continue?')) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        $client = app()->make(WhopClient::class, [
            'apiKey' => $account->api_key,
            'companyId' => $account->company_id,
            'environment' => $account->environment,
        ]);

        try {
            $reply = WhopClient::readWebhookReply($client->createWebhook($url, self::EVENTS));
        } catch (RequestException $e) {
            $this->error('Whop refused the request (HTTP '.$e->response->status().'). Check the API key and its permissions.');

            return self::FAILURE;
        }

        if ($reply['secret'] === null) {
            $this->error('Whop did not return a signing secret. The webhook may exist in Whop; check the dashboard.');

            return self::FAILURE;
        }

        // webhook_secret is not mass-assignable (encrypted cast), so set it directly.
        $account->webhook_secret = $reply['secret'];
        $account->save();

        $this->info('Webhook registered and signing secret stored.');
        $this->table(['Field', 'Value'], [
            ['Account', "{$account->account_name} (#{$account->id})"],
            ['Webhook ID', $reply['id'] ?? '-'],
            ['URL', $url],
            ['Events', implode(', ', self::EVENTS)],
            ['Environment', $account->environment],
        ]);

        return self::SUCCESS;
    }

    private function resolveAccount(): ?WhopAccount
    {
        $id = $this->argument('account');

        if ($id !== null) {
            $account = WhopAccount::find($id);

            if (! $account) {
                $this->error("No Whop account with id {$id}.");
            }

            return $account;
        }

        $accounts = WhopAccount::all();

        if ($accounts->isEmpty()) {
            $this->error('No Whop accounts exist. Create one first.');

            return null;
        }

        if ($accounts->count() > 1) {
            $this->error('Multiple Whop accounts exist. Pass the id, for example whop:register-webhook 1 --url=...');
            $this->table(
                ['id', 'name', 'active'],
                $accounts->map(fn (WhopAccount $a): array => [$a->id, $a->account_name, $a->is_active ? 'yes' : 'no'])->all(),
            );

            return null;
        }

        return $accounts->first();
    }
}
