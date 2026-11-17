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
     * The address must be https and on a host PayHub knows, so a bad reply cannot become an open redirect.
     */
    public function hasAllowedHost(string $environment): bool
    {
        $parts = parse_url($this->url);

        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            return false;
        }

        $allowed = (array) config('services.worldpay.hpp_hosts.'.($environment === 'live' ? 'live' : 'try'), []);

        return in_array(strtolower($parts['host']), array_map('strtolower', $allowed), true);
    }
}
