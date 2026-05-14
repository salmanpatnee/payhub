<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentSucceeded extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Payment $payment,
    ) {}

    public function envelope(): Envelope
    {
        $formattedAmount = $this->formatAmount($this->payment->amount, $this->payment->currency);

        return new Envelope(
            subject: 'Payment received — '.$this->payment->client_name.' ('.$formattedAmount.')',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payment-succeeded',
        );
    }

    private function formatAmount(int $cents, string $currency): string
    {
        $symbol = strtolower($currency) === 'gbp' ? '£' : '$';

        return $symbol.number_format($cents / 100, 2).' '.strtoupper($currency);
    }
}
