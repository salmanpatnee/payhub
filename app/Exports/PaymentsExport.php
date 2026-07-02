<?php

namespace App\Exports;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PaymentsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    /**
     * @param  Builder<Payment>  $query  The role-scoped, filtered payments query.
     */
    public function __construct(private Builder $query) {}

    /**
     * @return Builder<Payment>
     */
    public function query(): Builder
    {
        return $this->query;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Reference Code',
            'Client Name',
            'Client Email',
            'Amount',
            'Currency',
            'Brand',
            'Provider',
            'Payment Account',
            'Provider Reference',
            'Relationship Manager',
            'Status',
            'Service',
            'Package',
            'Note',
            'Created At',
            'Paid At',
        ];
    }

    /**
     * @param  Payment  $payment
     * @return list<string|null>
     */
    public function map($payment): array
    {
        return [
            $payment->formattedReferenceCode(),
            $payment->client_name,
            $payment->client_email,
            number_format($payment->amount / 100, 2, '.', ''),
            strtoupper($payment->currency),
            $payment->brand?->name,
            $payment->provider->label(),
            $payment->providerAccountName(),
            match ($payment->provider) {
                PaymentProvider::Revolut => $payment->revolut_order_id,
                PaymentProvider::Square => $payment->square_payment_id,
                default => $payment->stripe_payment_intent_id,
            },
            $payment->relationshipManager?->name,
            ucfirst($payment->status),
            $payment->service,
            $payment->package !== null ? ucfirst($payment->package) : null,
            $payment->note,
            $payment->created_at?->format('Y-m-d H:i:s'),
            $payment->paid_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
