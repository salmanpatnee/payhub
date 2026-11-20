<?php

namespace App\Services\Worldpay;

use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;

/**
 * Sends one Worldpay finding to Sentry with tags, a `worldpay` context and a fingerprint so the same kind of
 * failure groups into one issue. Resolved from the container so tests can swap it. It never throws: a Sentry
 * problem must not break a payment page. Callers pass no card values, hosted page URL, credentials or email.
 */
class WorldpaySentry
{
    /**
     * @param  array<string, string|int|null>  $tags
     * @param  array<string, mixed>  $context
     * @param  list<string>  $fingerprint
     */
    public function capture(string $message, array $tags, array $context, array $fingerprint, string $level = 'warning'): void
    {
        if (! class_exists(\Sentry\SentrySdk::class)) {
            return;
        }

        try {
            \Sentry\withScope(function (Scope $scope) use ($message, $tags, $context, $fingerprint, $level): void {
                foreach ($tags as $key => $value) {
                    if ($value !== null && $value !== '') {
                        $scope->setTag((string) $key, (string) $value);
                    }
                }

                $scope->setContext('worldpay', $context);
                $scope->setFingerprint($fingerprint);
                $scope->setLevel(new Severity($level));

                \Sentry\captureMessage($message);
            });
        } catch (Throwable) {
            // Sentry must never break the payment flow.
        }
    }
}
