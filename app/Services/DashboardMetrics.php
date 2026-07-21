<?php

namespace App\Services;

use App\Enums\PaymentProvider;
use App\Models\Brand;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\RevolutAccount;
use App\Models\SquareAccount;
use App\Models\StripeAccount;
use App\Models\VivaAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes aggregated analytics for the payments dashboard.
 *
 * All money is kept as integer cents and never summed across currencies —
 * USD and GBP are reported on separate keys. Formatting happens on the client.
 *
 * @phpstan-type Filters array{from?: string|null, to?: string|null, brand_id?: int|string|null, relationship_manager_id?: int|string|null, provider?: string|null, account?: string|null, currency?: string|null}
 */
class DashboardMetrics
{
    private const COMPLETED = 'completed';

    private const PENDING = 'pending';

    private const FAILED = 'failed';

    private const CANCELLED = 'cancelled';

    /**
     * @param  Filters  $filters
     */
    public function __construct(private array $filters = []) {}

    /**
     * @param  Filters  $filters
     * @return array<string, mixed>
     */
    public static function for(array $filters): array
    {
        return (new self($filters))->build();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'kpis' => $this->kpis(),
            'revenueTrend' => $this->revenueTrend(),
            'funnel' => $this->funnel(),
            'brandPerformance' => $this->brandPerformance(),
            'rmLeaderboard' => $this->rmLeaderboard(),
            'currencySplit' => $this->currencySplit(),
            'accountsToday' => $this->accountsToday(),
            'worklist' => $this->worklist(),
            'insights' => $this->insights(),
            'filters' => $this->resolvedFilters(),
            'filterOptions' => $this->filterOptions(),
        ];
    }

    /**
     * Non-date filters applied (status excluded — callers add it).
     */
    private function filteredQuery(): Builder
    {
        [$accountProvider, $accountId] = $this->parsedAccountFilter();

        return Payment::query()
            ->when($this->filters['brand_id'] ?? null, fn ($q, $v) => $q->where('brand_id', $v))
            ->when($this->filters['relationship_manager_id'] ?? null, fn ($q, $v) => $q->where('relationship_manager_id', $v))
            ->when($this->filters['provider'] ?? null, fn ($q, $v) => $q->where('provider', $v))
            ->when($accountProvider === 'stripe', fn ($q) => $q->where('stripe_account_id', $accountId))
            ->when($accountProvider === 'revolut', fn ($q) => $q->where('revolut_account_id', $accountId))
            ->when($accountProvider === 'square', fn ($q) => $q->where('square_account_id', $accountId))
            ->when($accountProvider === 'viva', fn ($q) => $q->where('viva_account_id', $accountId))
            ->when($this->filters['currency'] ?? null, fn ($q, $v) => $q->where('currency', $v));
    }

    /**
     * filteredQuery() plus the created_at date-range filter (status excluded — callers add it).
     */
    private function baseQuery(): Builder
    {
        return $this->filteredQuery()
            ->when($this->filters['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($this->filters['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));
    }

    /**
     * Split the combined "{provider}:{id}" account filter into [provider, id].
     * Returns [null, null] when no (or a malformed) account filter is set.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function parsedAccountFilter(): array
    {
        $value = $this->filters['account'] ?? null;

        if (! is_string($value) || ! str_contains($value, ':')) {
            return [null, null];
        }

        [$provider, $id] = explode(':', $value, 2);

        return in_array($provider, ['stripe', 'revolut', 'square', 'viva'], true) && ctype_digit($id)
            ? [$provider, (int) $id]
            : [null, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function kpis(): array
    {
        $counts = (clone $this->baseQuery())
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $completedCount = (int) ($counts[self::COMPLETED] ?? 0);
        $failedCount = (int) ($counts[self::FAILED] ?? 0);
        $totalCount = (int) $counts->sum();

        $collected = $this->revenueByCurrency(self::COMPLETED);
        $pending = $this->revenueByCurrency(self::PENDING);

        $avgByCurrency = (clone $this->baseQuery())
            ->where('status', self::COMPLETED)
            ->selectRaw('currency, avg(amount) as a')
            ->groupBy('currency')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->currency => (int) round((float) $r->a)])
            ->all();

        $activeBrands = (clone $this->baseQuery())
            ->where('status', self::COMPLETED)
            ->distinct()
            ->count('brand_id');

        return [
            'collected' => $collected,
            'conversionRate' => $totalCount > 0 ? round($completedCount / $totalCount * 100, 1) : 0.0,
            'pendingPipeline' => [
                'amounts' => $pending,
                'count' => (int) ($counts[self::PENDING] ?? 0),
            ],
            'successRate' => ($completedCount + $failedCount) > 0
                ? round($completedCount / ($completedCount + $failedCount) * 100, 1)
                : 0.0,
            'avgPaymentValue' => $avgByCurrency,
            'activeBrands' => $activeBrands,
            'completedCount' => $completedCount,
            'totalCount' => $totalCount,
        ];
    }

    /**
     * Completed-revenue cents keyed by currency, e.g. ['usd' => 1411600, 'gbp' => 23000].
     *
     * @return array<string, int>
     */
    private function revenueByCurrency(string $status): array
    {
        return (clone $this->baseQuery())
            ->where('status', $status)
            ->selectRaw('currency, sum(amount) as s')
            ->groupBy('currency')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->currency => (int) $r->s])
            ->all();
    }

    /**
     * Daily completed revenue per currency, based on paid_at (not created_at —
     * a payment created within range can still pay outside it, and vice versa).
     *
     * @return array<int, array{date: string, currency: string, total: int}>
     */
    private function revenueTrend(): array
    {
        return (clone $this->filteredQuery())
            ->where('status', self::COMPLETED)
            ->whereNotNull('paid_at')
            ->when($this->filters['from'] ?? null, fn ($q, $v) => $q->whereDate('paid_at', '>=', $v))
            ->when($this->filters['to'] ?? null, fn ($q, $v) => $q->whereDate('paid_at', '<=', $v))
            ->selectRaw('DATE(paid_at) as d, currency, sum(amount) as s')
            ->groupBy('d', 'currency')
            ->orderBy('d')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->d,
                'currency' => (string) $r->currency,
                'total' => (int) $r->s,
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function funnel(): array
    {
        $counts = (clone $this->baseQuery())
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $now = Carbon::now();
        $expired = (clone $this->baseQuery())
            ->where('status', self::PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->count();

        return [
            'total' => (int) $counts->sum(),
            'pending' => (int) ($counts[self::PENDING] ?? 0),
            'completed' => (int) ($counts[self::COMPLETED] ?? 0),
            'failed' => (int) ($counts[self::FAILED] ?? 0),
            'cancelled' => (int) ($counts[self::CANCELLED] ?? 0),
            'expired' => $expired,
        ];
    }

    /**
     * Per-brand performance: completed revenue (per currency), counts, conversion.
     *
     * @return array<int, array<string, mixed>>
     */
    private function brandPerformance(): array
    {
        $names = Brand::query()->pluck('name', 'id');

        return $this->groupedPerformance('brand_id')
            ->map(function (array $row) use ($names) {
                $row['name'] = $names[$row['id']] ?? "Brand #{$row['id']}";

                return $row;
            })
            ->sortByDesc(fn (array $r) => $this->primaryRevenue($r['revenue']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rmLeaderboard(): array
    {
        $names = RelationshipManager::query()->pluck('name', 'id');

        return $this->groupedPerformance('relationship_manager_id')
            ->map(function (array $row) use ($names) {
                $row['name'] = $row['id'] !== null
                    ? ($names[$row['id']] ?? "RM #{$row['id']}")
                    : 'Unassigned';

                return $row;
            })
            ->sortByDesc(fn (array $r) => $this->primaryRevenue($r['revenue']))
            ->values()
            ->all();
    }

    /**
     * Shared group-by aggregation for brand/RM performance.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function groupedPerformance(string $column): Collection
    {
        $rows = (clone $this->baseQuery())
            ->selectRaw("{$column} as gid, currency, status, count(*) as c, sum(amount) as s")
            ->groupBy('gid', 'currency', 'status')
            ->get();

        return $rows
            ->groupBy('gid')
            ->map(function (Collection $group, $gid) {
                $revenue = [];
                $completed = 0;
                $total = 0;

                foreach ($group as $r) {
                    $total += (int) $r->c;
                    if ($r->status === self::COMPLETED) {
                        $completed += (int) $r->c;
                        $revenue[$r->currency] = ($revenue[$r->currency] ?? 0) + (int) $r->s;
                    }
                }

                return [
                    'id' => $gid === '' ? null : (int) $gid,
                    'revenue' => $revenue,
                    'completedCount' => $completed,
                    'totalCount' => $total,
                    'conversionRate' => $total > 0 ? round($completed / $total * 100, 1) : 0.0,
                ];
            })
            ->values();
    }

    /**
     * @param  array<string, int>  $revenue
     */
    private function primaryRevenue(array $revenue): int
    {
        return $revenue['usd'] ?? (array_sum($revenue) > 0 ? max($revenue) : 0);
    }

    /**
     * @return array<string, int>
     */
    private function currencySplit(): array
    {
        return $this->revenueByCurrency(self::COMPLETED);
    }

    /**
     * Per-account "right now" intake. Respects an explicit dashboard date
     * filter same as every other panel, but — unlike the rest of the
     * dashboard, which defaults to all-time — this panel's own default
     * (no filter applied) is "today", since it's an operational, right-now
     * view rather than a historical total.
     *
     * Accepted = completed payments paid within the range. Pending = pending
     * links created within the range (still live, may convert). Currencies
     * never merged.
     *
     * @return array<int, array{id: int, provider: string, name: string, accepted: array<string, int>, pending: array<string, int>}>
     */
    private function accountsToday(): array
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;

        if (! $from && ! $to) {
            $from = $to = Carbon::today()->toDateString();
        }

        $accepted = $this->accountCurrencyTotals(
            (clone $this->filteredQuery())
                ->where('status', self::COMPLETED)
                ->whereNotNull('paid_at')
                ->when($from, fn ($q, $v) => $q->whereDate('paid_at', '>=', $v))
                ->when($to, fn ($q, $v) => $q->whereDate('paid_at', '<=', $v))
        );

        $pending = $this->accountCurrencyTotals(
            (clone $this->filteredQuery())
                ->where('status', self::PENDING)
                ->when($from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                ->when($to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
        );

        $stripeNames = StripeAccount::query()->pluck('account_name', 'id');
        $revolutNames = RevolutAccount::query()->pluck('account_name', 'id');
        $squareNames = SquareAccount::query()->pluck('account_name', 'id');
        $vivaNames = VivaAccount::query()->pluck('account_name', 'id');

        return collect(array_keys($accepted + $pending))
            ->map(function (string $key) use ($accepted, $pending, $stripeNames, $revolutNames, $squareNames, $vivaNames) {
                [$provider, $id] = explode(':', $key);
                $id = (int) $id;
                $name = match ($provider) {
                    'revolut' => $revolutNames[$id] ?? "Account #{$id}",
                    'square' => $squareNames[$id] ?? "Account #{$id}",
                    'viva' => $vivaNames[$id] ?? "Account #{$id}",
                    default => $stripeNames[$id] ?? "Account #{$id}",
                };

                return [
                    'id' => $id,
                    'provider' => $provider,
                    'name' => $name,
                    'accepted' => $accepted[$key] ?? [],
                    'pending' => $pending[$key] ?? [],
                ];
            })
            ->sortByDesc(fn (array $row) => $this->primaryRevenue($row['accepted']) + $this->primaryRevenue($row['pending']))
            ->values()
            ->all();
    }

    /**
     * Reduce a grouped query to ["{provider}:{accountId}" => [currency => cents]].
     * The account id is resolved per provider; rows with no account are skipped.
     *
     * @return array<string, array<string, int>>
     */
    private function accountCurrencyTotals(Builder $query): array
    {
        $totals = [];

        $query->selectRaw('provider, stripe_account_id, revolut_account_id, square_account_id, viva_account_id, currency, sum(amount) as s')
            ->groupBy('provider', 'stripe_account_id', 'revolut_account_id', 'square_account_id', 'viva_account_id', 'currency')
            ->get()
            ->each(function ($r) use (&$totals) {
                $provider = $r->provider instanceof PaymentProvider ? $r->provider->value : (string) $r->provider;
                $id = match ($provider) {
                    'revolut' => $r->revolut_account_id,
                    'square' => $r->square_account_id,
                    'viva' => $r->viva_account_id,
                    default => $r->stripe_account_id,
                };

                if ($id === null) {
                    return;
                }

                $totals["{$provider}:".(int) $id][$r->currency] = (int) $r->s;
            });

        return $totals;
    }

    /**
     * Stale-pending links (recoverable money) and top high-value payments.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function worklist(): array
    {
        $stalePending = (clone $this->baseQuery())
            ->with(['brand', 'relationshipManager'])
            ->where('status', self::PENDING)
            ->orderByDesc('amount')
            ->limit(10)
            ->get()
            ->map(fn (Payment $p) => $this->paymentRow($p))
            ->all();

        $highValue = (clone $this->baseQuery())
            ->with(['brand', 'relationshipManager'])
            ->orderByDesc('amount')
            ->limit(10)
            ->get()
            ->map(fn (Payment $p) => $this->paymentRow($p))
            ->all();

        return [
            'stalePending' => $stalePending,
            'highValue' => $highValue,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentRow(Payment $p): array
    {
        return [
            'uuid' => $p->uuid,
            'reference_code' => $p->reference_code,
            'client_name' => $p->client_name,
            'brand_name' => $p->brand?->name,
            'rm_name' => $p->relationshipManager?->name,
            'package' => $p->package,
            'amount' => (int) $p->amount,
            'currency' => $p->currency,
            'status' => $p->status,
            'expires_at' => $p->expires_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    /**
     * Pre-computed plain-language insight sentences.
     *
     * @return array<int, string>
     */
    private function insights(): array
    {
        $insights = [];
        $kpis = $this->kpis();
        $brands = $this->brandPerformance();

        $collectedUsd = $kpis['collected']['usd'] ?? 0;
        $pendingUsd = $kpis['pendingPipeline']['amounts']['usd'] ?? 0;
        $pendingCount = $kpis['pendingPipeline']['count'];

        if ($pendingUsd > 0) {
            $compare = $pendingUsd > $collectedUsd ? 'larger than' : 'on top of';
            $insights[] = sprintf(
                '$%s in pending links (%d payments) is unconverted — %s the $%s already collected.',
                number_format($pendingUsd / 100),
                $pendingCount,
                $compare,
                number_format($collectedUsd / 100),
            );
        }

        $totalUsd = collect($brands)->sum(fn (array $b) => $b['revenue']['usd'] ?? 0);
        if ($totalUsd > 0 && ! empty($brands)) {
            $top = $brands[0];
            $share = round(($top['revenue']['usd'] ?? 0) / $totalUsd * 100);
            $insights[] = sprintf('%s drives %d%% of completed USD revenue.', $top['name'], $share);
        }

        $rms = $this->rmLeaderboard();
        $totalRmUsd = collect($rms)->sum(fn (array $r) => $r['revenue']['usd'] ?? 0);
        if ($totalRmUsd > 0 && ! empty($rms)) {
            $topRm = $rms[0];
            $share = round(($topRm['revenue']['usd'] ?? 0) / $totalRmUsd * 100);
            $insights[] = sprintf('%s generated %d%% of completed USD revenue.', $topRm['name'], $share);
        }

        if ($kpis['totalCount'] > 0) {
            $insights[] = sprintf(
                'Conversion rate is %.1f%% — %d of %d links completed.',
                $kpis['conversionRate'],
                $kpis['completedCount'],
                $kpis['totalCount'],
            );
        }

        $insights[] = sprintf('Success rate of attempted payments is %.1f%%.', $kpis['successRate']);

        return $insights;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedFilters(): array
    {
        return [
            'from' => $this->filters['from'] ?? null,
            'to' => $this->filters['to'] ?? null,
            'brand_id' => $this->filters['brand_id'] ?? null,
            'relationship_manager_id' => $this->filters['relationship_manager_id'] ?? null,
            'provider' => $this->filters['provider'] ?? null,
            'account' => $this->filters['account'] ?? null,
            'currency' => $this->filters['currency'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'relationshipManagers' => RelationshipManager::query()
                ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $this->filters['relationship_manager_id'] ?? null))
                ->orderBy('name')
                ->get(['id', 'name']),
            'accounts' => $this->accountOptions(),
        ];
    }

    /**
     * Union of Stripe + Revolut accounts as { value, name, provider }, where
     * value is the provider-qualified "{provider}:{id}" used by the account
     * filter (ids collide across providers, so they must be namespaced).
     *
     * @return array<int, array{value: string, name: string, provider: string}>
     */
    private function accountOptions(): array
    {
        $stripe = StripeAccount::query()->orderBy('account_name')->get(['id', 'account_name'])
            ->map(fn (StripeAccount $a) => [
                'value' => "stripe:{$a->id}",
                'name' => $a->account_name,
                'provider' => 'stripe',
            ]);

        $revolut = RevolutAccount::query()->orderBy('account_name')->get(['id', 'account_name'])
            ->map(fn (RevolutAccount $a) => [
                'value' => "revolut:{$a->id}",
                'name' => $a->account_name,
                'provider' => 'revolut',
            ]);

        $square = SquareAccount::query()->orderBy('account_name')->get(['id', 'account_name'])
            ->map(fn (SquareAccount $a) => [
                'value' => "square:{$a->id}",
                'name' => $a->account_name,
                'provider' => 'square',
            ]);

        $viva = VivaAccount::query()->orderBy('account_name')->get(['id', 'account_name'])
            ->map(fn (VivaAccount $a) => [
                'value' => "viva:{$a->id}",
                'name' => $a->account_name,
                'provider' => 'viva',
            ]);

        return $stripe->concat($revolut)->concat($square)->concat($viva)->values()->all();
    }
}
