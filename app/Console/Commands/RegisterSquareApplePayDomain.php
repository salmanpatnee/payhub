<?php

namespace App\Console\Commands;

use App\Models\SquareAccount;
use Illuminate\Console\Command;
use Square\ApplePay\Requests\RegisterDomainRequest;
use Square\Environments;
use Square\Exceptions\SquareException;
use Square\SquareClient;

/**
 * Registers the pay page domain for Apple Pay with Square, once per Square account.
 *
 * Square's domain verification file must already be served at
 * /.well-known/apple-developer-merchantid-domain-association, or Square answers
 * "Domain is misconfigured for Apple Pay". Google Pay and Cash App Pay need no registration.
 */
class RegisterSquareApplePayDomain extends Command
{
    protected $signature = 'square:register-apple-pay-domain
        {account? : SquareAccount id (defaults to every active account)}
        {--domain= : Domain to register, without https:// (defaults to the APP_URL host)}';

    protected $description = 'Register the pay page domain for Apple Pay with Square';

    public function handle(): int
    {
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
            // Per-account client, never a global token.
            $square = app()->make(SquareClient::class, [
                'token' => $account->access_token,
                'options' => ['baseUrl' => $account->environment === 'production'
                    ? Environments::Production->value
                    : Environments::Sandbox->value],
            ]);

            try {
                $reply = $square->applePay->registerDomain(new RegisterDomainRequest(['domainName' => $domain]));
                $status = $reply->getStatus() ?? 'unknown';
            } catch (SquareException) {
                // Square's own text is not shown; it can echo account details.
                $status = 'failed (check the verification file and the access token)';
                $failed = true;
            }

            $rows[] = ["{$account->account_name} (#{$account->id})", $account->environment, $status];
        }

        $this->table(['Account', 'Environment', 'Status'], $rows);
        $this->line("Domain: {$domain}");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<SquareAccount>
     */
    private function resolveAccounts(): array
    {
        $id = $this->argument('account');

        if ($id !== null) {
            $account = SquareAccount::find($id);

            if (! $account) {
                $this->error("No Square account with id {$id}.");

                return [];
            }

            return [$account];
        }

        $accounts = SquareAccount::where('is_active', true)->get()->all();

        if ($accounts === []) {
            $this->error('No active Square accounts exist.');
        }

        return array_values($accounts);
    }
}
