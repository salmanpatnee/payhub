<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves the Apple Pay domain verification file for the provider this site uses
 * (config services.apple_pay.provider). Apple checks one fixed path per domain, so
 * a deployment can verify Apple Pay with Square or with Revolut, never both.
 */
class AppleDomainAssociationController extends Controller
{
    private const PROVIDERS = ['square', 'revolut'];

    public function __invoke(): Response
    {
        $provider = config('services.apple_pay.provider');

        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        return response((string) file_get_contents(resource_path("apple-pay/{$provider}")), 200, [
            'Content-Type' => 'text/plain',
        ]);
    }
}
