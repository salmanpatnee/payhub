<x-mail::message>

# {{ $payment->brand->name }}

**Payment Received** · {{ ($payment->paid_at ?? now())->format('M j, Y · g:i A') }}

---

<x-mail::panel>

**{{ $payment->client_name }}**
{{ $payment->client_email }}

</x-mail::panel>

<x-mail::table>

| | |
|:--|:--|
| **Amount** | {{ strtolower($payment->currency) === 'gbp' ? '£' : '$' }}{{ number_format($payment->amount / 100, 2) }} {{ strtoupper($payment->currency) }} |
| **Brand** | {{ $payment->brand->name }} |
| **{{ $payment->provider->label() }} Account** | {{ $payment->providerAccountName() }} |
| **Service** | {{ $payment->service }} |
| **Package** | {{ ucfirst($payment->package) }} |
@if($payment->note)
| **Note** | {{ $payment->note }} |
@endif

</x-mail::table>

<x-mail::button :url="route('payments.show', $payment)">
View Payment
</x-mail::button>

{{ config('app.name') }}

<x-mail::subcopy>
Automated notification · Admins only
</x-mail::subcopy>

</x-mail::message>
