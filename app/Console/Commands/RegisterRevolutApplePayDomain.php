<?php

namespace App\Console\Commands;

use App\Models\RevolutAccount;
use App\Services\Revolut\RevolutClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Registers this site's domain for Apple Pay with Revolut, once per Revolut account.
 *
 * Only for a site with APPLE_PAY_PROVIDER=revolut: Revolut's verification file must already be
 * served at /.well-known/apple-developer-merchantid-domain-association. Google Pay needs no registration.
 */
class RegisterRevolutApplePayDomain extends Command
{
    protected $signature = 'revolut:register-apple-pay-domain
        {account? : RevolutAccount id (defaults to every active account)}
        {--domain= : Domain to register, without https:// (defaults to the APP_URL host)}';

    protected $description = 'Register this site\'s domain for Apple Pay with Revolut';

    public function handle(): int
    {
        if (config('services.apple_pay.provider') !== 'revolut') {
            $this->error('This site serves another provider\'s Apple Pay file. Set APPLE_PAY_PROVIDER=revolut first.');

            return self::FAILURE;
        }

        $domain = $this->option('domain') ?: parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($domain) || $domain === '' || str_contains($domain, '/')) {
            $this->error('Pass a plain domain, e.g. --domain=pay.example.com');

            return self::FAILURE;
        }

        $accounts = $this->resolveAccounts();

        if ($accounts === []) {
            return self::FAILURE;
        }

        $failed = false;
        $rows = [];

        foreach ($accounts as $account) {
            // Per-account client, never a global key.
            $client = app()->make(RevolutClient::class, ['secretKey' => $account->secret_key]);

            try {
                $client->registerApplePayDomain($domain);
                $status = 'registered';
            } catch (RequestException|ConnectionException) {
                // Revolut's own text is not shown; it can echo account details.
                $status = 'failed (check the verification file and the secret key)';
                $failed = true;
            }

            $rows[] = ["{$account->account_name} (#{$account->id})", $status];
        }

        $this->table(['Account', 'Status'], $rows);
        $this->line("Domain: {$domain}");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<RevolutAccount>
     */
    private function resolveAccounts(): array
    {
        $id = $this->argument('account');

        if ($id !== null) {
            $account = RevolutAccount::find($id);

            if (! $account) {
                $this->error("No Revolut account with id {$id}.");

                return [];
            }

            return [$account];
        }

        $accounts = RevolutAccount::where('is_active', true)->get()->all();

        if ($accounts === []) {
            $this->error('No active Revolut accounts exist.');
        }

        return array_values($accounts);
    }
}
