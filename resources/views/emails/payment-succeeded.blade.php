<x-mail::message>
# Payment Received

A payment has been successfully processed.

| Field | Value |
|---|---|
| Client | {{ $payment->client_name }} |
| Email | {{ $payment->client_email }} |
| Amount | {{ number_format($payment->amount / 100, 2) }} {{ strtoupper($payment->currency) }} |
| Brand | {{ $payment->brand->name }} |
| Stripe Account | {{ $payment->stripeAccount->account_name }} |
| Service | {{ $payment->service }} |
| Package | {{ ucfirst($payment->package) }} |
@if($payment->note)
| Note | {{ $payment->note }} |
@endif

<x-mail::button :url="route('payments.show', $payment)">
View Payment
</x-mail::button>

Thanks,
{{ config('app.name') }}
</x-mail::message>
