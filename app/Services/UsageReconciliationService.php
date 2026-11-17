<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Generation;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use UnexpectedValueException;

final readonly class UsageReconciliationService
{
    private const CATEGORIES = [
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    public function __construct(
        private PixelforgeClient $provider,
        private PricingService $pricing,
    ) {}

    /**
     * Compare persisted generation charges against one API key's UTC completion-day report.
     *
     * @return array{
     *     matched: bool,
     *     days: list<array{date:string,matched:bool,local:array<string,int|string>,provider:array<string,int|string>}>,
     *     errors: list<string>
     * }
     */
    public function reconcile(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $start = $start->utc()->startOfDay();
        $end = $end->utc()->startOfDay();

        if ($end->lessThan($start) || $end->greaterThan($start->addDays(91))) {
            throw new InvalidArgumentException('Usage reconciliation requires 1 to 92 inclusive UTC days.');
        }

        $first = $start->toDateString();
        $last = $end->toDateString();
        $report = $this->provider->usage($first, $last);
        $local = [];
        $remote = [];
        $errors = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $local[$date] = $this->emptyDay();
        }

        Generation::query()
            ->where('status', 'succeeded')
            ->where('completed_at', '>=', $start)
            ->where('completed_at', '<', $end->addDay())
            ->chunkById(100, function (Collection $generations) use (&$local, &$errors): void {
                foreach ($generations as $generation) {
                    $completed = $generation->getAttribute('completed_at');
                    $usage = $generation->getAttribute('usage');
                    $rates = $generation->getAttribute('rates_snapshot');
                    $providerAmount = $generation->getAttribute('provider_cost_usd');
                    $customerAmount = $generation->getAttribute('customer_cost_usd');

                    if (! $completed instanceof CarbonImmutable || ! is_array($usage)
                        || ! is_array($rates) || ! is_string($providerAmount)
                        || ! is_string($customerAmount)) {
                        $errors[] = "Incomplete successful generation: {$generation->id}";

                        continue;
                    }

                    $date = $completed->utc()->toDateString();

                    if (! isset($local[$date])) {
                        $errors[] = "Generation completed outside requested UTC range: {$generation->id}";

                        continue;
                    }

                    try {
                        $cost = $this->pricing->providerCost($usage, $rates);
                        $charge = $cost->withMarkup();

                        if ($cost->toDecimal() !== Money::fromDecimal($providerAmount)->toDecimal()
                            || $charge->toDecimal() !== Money::fromDecimal($customerAmount)->toDecimal()) {
                            $errors[] = "Stored cost disagrees with token usage for generation {$generation->id}";
                        }
                    } catch (InvalidArgumentException|\OverflowException $exception) {
                        $errors[] = "Invalid token accounting for generation {$generation->id}: {$exception->getMessage()}";

                        continue;
                    }

                    $row = $local[$date];
                    $row['generations']++;

                    foreach (self::CATEGORIES as $category) {
                        if (! is_int($usage[$category] ?? null) || $usage[$category] < 0) {
                            $errors[] = "Invalid {$category} for generation {$generation->id}";

                            continue 2;
                        }

                        $row[$category] += $usage[$category];
                    }

                    $row['cost_usd'] = Money::fromDecimal((string) $row['cost_usd'])
                        ->add(Money::fromDecimal($providerAmount))->toDecimal();
                    $local[$date] = $row;
                }
            });

        foreach ($report['data'] as $item) {
            if (! is_array($item) || ! is_string($item['date'] ?? null) || ! isset($local[$item['date']])
                || isset($remote[$item['date']])) {
                throw new UnexpectedValueException('Pixelforge usage report has an unexpected or duplicate day.');
            }

            $remote[$item['date']] = $this->parseProviderRow($item);
        }

        foreach (array_keys($local) as $date) {
            if (! isset($remote[$date])) {
                throw new UnexpectedValueException("Pixelforge usage report omitted UTC date {$date}.");
            }
        }

        $providerTotal = $this->parseProviderRow($report['total']);
        $summedProvider = $this->emptyDay();

        foreach ($remote as $row) {
            $summedProvider = $this->addRows($summedProvider, $row);
        }

        if (! $this->rowsMatch($providerTotal, $summedProvider)) {
            $errors[] = 'Pixelforge report total does not equal its daily rows.';
        }

        $days = [];

        foreach ($local as $date => $row) {
            $matched = $this->rowsMatch($row, $remote[$date]);

            $days[] = [
                'date' => $date,
                'matched' => $matched,
                'local' => $row,
                'provider' => $remote[$date],
            ];
        }

        return [
            'matched' => $errors === [] && ! in_array(false, array_column($days, 'matched'), true),
            'days' => $days,
            'errors' => $errors,
        ];
    }

    /** @return array<string, int|string> */
    private function emptyDay(): array
    {
        return [
            'generations' => 0,
            'input_text_tokens' => 0,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'cost_usd' => Money::zero()->toDecimal(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, int|string>
     */
    private function parseProviderRow(array $row): array
    {
        $parsed = $this->emptyDay();

        foreach (['generations', ...self::CATEGORIES] as $field) {
            if (! is_int($row[$field] ?? null) || $row[$field] < 0) {
                throw new UnexpectedValueException("Invalid Pixelforge usage field: {$field}.");
            }

            $parsed[$field] = $row[$field];
        }

        if (! is_string($row['cost_usd'] ?? null)) {
            throw new UnexpectedValueException('Pixelforge usage cost must be an exact decimal string.');
        }

        $parsed['cost_usd'] = Money::fromDecimal($row['cost_usd'])->toDecimal();

        return $parsed;
    }

    /**
     * @param array<string, int|string> $left
     * @param array<string, int|string> $right
     */
    private function rowsMatch(array $left, array $right): bool
    {
        foreach (['generations', ...self::CATEGORIES, 'cost_usd'] as $field) {
            if ($left[$field] !== $right[$field]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, int|string> $left
     * @param array<string, int|string> $right
     * @return array<string, int|string>
     */
    private function addRows(array $left, array $right): array
    {
        foreach (['generations', ...self::CATEGORIES] as $field) {
            $left[$field] += $right[$field];
        }

        $left['cost_usd'] = Money::fromDecimal((string) $left['cost_usd'])
            ->add(Money::fromDecimal((string) $right['cost_usd']))->toDecimal();

        return $left;
    }
}
