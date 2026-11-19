<?php

namespace App\Services\Worldpay;

use UnexpectedValueException;

/**
 * What Worldpay returns when a hosted payment page is created (spec 0014, AC-6).
 * One of the only classes that know Worldpay field names. The page address is the field `url`.
 */
final class WorldpayPageReply
{
    public function __construct(public readonly string $url) {}

    /**
     * @param  array<string, mixed>|null  $json
     */
    public static function fromArray(?array $json): self
    {
        $url = $json['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new UnexpectedValueException('Worldpay page reply has no url.');
        }

        return new self($url);
    }

    /**
     * The address must be https, on the default port, on a subdomain of a Worldpay domain. Worldpay moves the
     * hosted page between domains and says not to list hosts, so only the domain is checked. A bad reply
     * (another domain, a lookalike, a userinfo trick) still cannot become an open redirect.
     */
    public function hasAllowedHost(string $environment): bool
    {
        $parts = parse_url($this->url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            return false;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        $host = strtolower($parts['host']);
        $domains = (array) config('services.worldpay.hpp_domains.'.($environment === 'live' ? 'live' : 'try'), []);

        foreach ($domains as $domain) {
            if (str_ends_with($host, '.'.strtolower((string) $domain))) {
                return true;
            }
        }

        return false;
    }
}
