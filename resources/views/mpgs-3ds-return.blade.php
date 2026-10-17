<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Verifying your payment</title>
</head>
<body
    data-uuid="{{ $uuid }}"
    data-order-id="{{ $orderId }}"
    data-transaction-id="{{ $transactionId }}"
    data-recommendation="{{ $recommendation }}"
    data-result="{{ $result }}"
>
    <p style="font-family: sans-serif; text-align: center; margin-top: 2rem;">Verifying your payment…</p>
    {{-- External file, not inline: the pay page CSP allows script-src 'self' only. --}}
    <script src="{{ asset('mpgs-3ds-return.js') }}"></script>
</body>
</html>
