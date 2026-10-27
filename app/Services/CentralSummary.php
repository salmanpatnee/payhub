<?php

namespace App\Services;

use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read only summary of payments for the central dashboard (contract v1,
 * docs/integrations/central-dashboard-api.md).
 *
 * Money is integer cents and never summed across currencies. Window edges are
 * cut in CENTRAL_TIMEZONE, then queried in the app timezone.
 */
class CentralSummary
{
    private const STATUSES = ['pending', 'completed', 'failed', 'cancelled'];

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $timezone = (string) config('central.timezone');
        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();

        $windows = [
            'all_time' => [null, null],
            'today' => [$today, $today->addDay()],
            'this_month' => [$now->startOfMonth(), $now->startOfMonth()->addMonth()],
            'last_30_days' => [$today->subDays(29), $today->addDay()],
        ];

        return [
            'contract_version' => 'v1',
            'deployment' => [
                'key' => config('central.deployment_key'),
                'name' => config('central.deployment_name') ?: config('app.name'),
                'app_release' => config('sentry.release') ?: null,
            ],
            'generated_at' => $now->toIso8601String(),
            'timezone' => $timezone,
            'windows' => collect($windows)
                ->map(fn (array $edges) => $this->window($edges[0], $edges[1], $now))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function window(?CarbonImmutable $from, ?CarbonImmutable $to, CarbonImmutable $now): array
    {
        $created = fn (): Builder => $this->between(Payment::query(), 'created_at', $from, $to);

        $counts = $created()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $expired = $created()
            ->where('status', 'pending')
            ->where('expires_at', '<', $now->setTimezone(config('app.timezone')))
            ->count();

        $paid = $this->between(Payment::query(), 'paid_at', $from, $to)
            ->where('status', 'completed')
            ->selectRaw('currency, sum(amount) as cents, count(*) as paid')
            ->groupBy('currency')
            ->get();

        $pending = $created()
            ->where('status', 'pending')
            ->selectRaw('currency, sum(amount) as cents')
            ->groupBy('currency')
            ->pluck('cents', 'currency');

        $links = ['total' => (int) $counts->sum()];
        foreach (self::STATUSES as $status) {
            $links[$status] = (int) ($counts[$status] ?? 0);
        }
        $links['expired'] = $expired;

        return [
            'from' => $from?->toIso8601String(),
            'to' => $to?->toIso8601String(),
            'links' => $links,
            'money' => [
                'completed' => $this->perCurrency($paid->pluck('cents', 'currency')),
                'paid_count' => (int) $paid->sum('paid'),
                'pending' => $this->perCurrency($pending),
            ],
        ];
    }

    private function between(Builder $query, string $column, ?CarbonImmutable $from, ?CarbonImmutable $to): Builder
    {
        $appTimezone = config('app.timezone');

        return $query
            ->when($from, fn (Builder $q) => $q->where($column, '>=', $from->setTimezone($appTimezone)))
            ->when($to, fn (Builder $q) => $q->where($column, '<', $to->setTimezone($appTimezone)));
    }

    /**
     * @param  Collection<array-key, mixed>  $sums
     * @return array<string, int>
     */
    private function perCurrency(Collection $sums): array
    {
        $result = array_fill_keys(config('central.currencies'), 0);

        foreach ($sums as $currency => $cents) {
            $result[strtolower((string) $currency)] = (int) $cents;
        }

        return $result;
    }
}
