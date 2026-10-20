<?php

/**
 * THROWAWAY sandbox spike for spec 0005 (AC-13). Not app code, not autoloaded.
 *
 * Calls the real MPGS test gateway and prints the RAW responses so you can
 * write the answers to the spec's Open questions. Field names in the request
 * bodies below are the spec's PROVISIONAL guesses: if the gateway rejects one,
 * that rejection IS the finding. Note it, fix the body, re-run.
 *
 * Credentials come from environment variables only. The integration password
 * is never printed.
 *
 *   MPGS_SPIKE_HOST      e.g. https://ap-gateway.mastercard.com (no trailing slash)
 *   MPGS_SPIKE_MID       merchant id
 *   MPGS_SPIKE_PASSWORD  integration password
 *   MPGS_SPIKE_VERSION   api version (default 66)
 *
 * Usage (from the repo root):
 *   php docs/specs/0005-mpgs-hosted-session-provider/spike/spike.php session
 *   php .../spike.php pay <sessionId> <orderId> <authTxnId> <amount> <currency>
 *   php .../spike.php order <orderId>
 *   php .../spike.php webhook-url
 *
 * `pay` is the only command that moves money (test cards only). Use a TEST
 * merchant. Never point this at a live merchant id.
 */
$host = rtrim((string) getenv('MPGS_SPIKE_HOST'), '/');
$mid = (string) getenv('MPGS_SPIKE_MID');
$password = (string) getenv('MPGS_SPIKE_PASSWORD');
$version = (int) (getenv('MPGS_SPIKE_VERSION') ?: 66);

if ($host === '' || $mid === '' || $password === '') {
    fwrite(STDERR, "Set MPGS_SPIKE_HOST, MPGS_SPIKE_MID and MPGS_SPIKE_PASSWORD first.\n");
    exit(1);
}

$base = "{$host}/api/rest/version/{$version}/merchant/{$mid}";

function call(string $method, string $url, string $mid, string $password, ?array $body = null): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERPWD => "merchant.{$mid}:{$password}",
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $started = microtime(true);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ms = (int) ((microtime(true) - $started) * 1000);

    echo "\n=== {$method} {$url}\n";
    if ($body !== null) {
        echo "--- request body\n".json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }
    echo "--- HTTP {$status} in {$ms} ms".($raw === false ? ' (curl error: '.curl_error($ch).')' : '')."\n";
    $decoded = json_decode((string) $raw, true);
    echo ($decoded === null ? (string) $raw : json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))."\n";
}

$command = $argv[1] ?? '';

switch ($command) {
    case 'session':
        // Open questions 1, 3, 6, 7, 12. Create a session, then write amount/currency onto it.
        call('POST', "{$base}/session", $mid, $password);
        echo "\nNext: copy the session id from above, then run:\n";
        echo "  php spike.php session-update <sessionId> <orderId> 10.50 USD\n";
        break;

    case 'session-update':
        [, , $sessionId, $orderId, $amount, $currency] = array_pad($argv, 6, null);
        call('PUT', "{$base}/session/{$sessionId}", $mid, $password, [
            'order' => ['id' => $orderId, 'amount' => $amount, 'currency' => $currency],
        ]);
        break;

    case 'pay':
        // Open questions 2, 4, 5, 9, 11, 12. Run AFTER the browser page has put a test card
        // into the session and (if needed) completed 3DS with the same auth transaction id.
        [, , $sessionId, $orderId, $authTxnId, $amount, $currency] = array_pad($argv, 7, null);
        $txnId = 'pay-'.bin2hex(random_bytes(4));
        echo "PAY transaction id for this call: {$txnId}\n";
        call('PUT', "{$base}/order/{$orderId}/transaction/{$txnId}", $mid, $password, [
            'apiOperation' => 'PAY',
            'order' => [
                'amount' => $amount,
                'currency' => $currency,
                'reference' => 'SPIKE-'.$orderId,
                'notificationUrl' => getenv('MPGS_SPIKE_NOTIFY_URL') ?: 'https://example.invalid/webhook/mpgs/1',
            ],
            'session' => ['id' => $sessionId],
            'authentication' => ['transactionId' => $authTxnId],
            'sourceOfFunds' => ['type' => 'CARD'],
        ]);
        echo "\nNext: php spike.php order {$orderId}   (and keep the PAY transaction id: {$txnId})\n";
        break;

    case 'order':
        // Open question 8. Find the PAY transaction in the list by its id.
        call('GET', "{$base}/order/".($argv[2] ?? ''), $mid, $password);
        break;

    case 'webhook-url':
        echo "Open question 5: expose a public HTTPS URL (e.g. a tunnel), set MPGS_SPIKE_NOTIFY_URL to it,\n";
        echo "run `pay`, and record the headers (X-Notification-Secret) and body of each delivery.\n";
        echo "Count the deliveries on a 3DS payment: the spec expects two.\n";
        break;

    default:
        fwrite(STDERR, "Commands: session | session-update | pay | order | webhook-url\n");
        exit(1);
}
