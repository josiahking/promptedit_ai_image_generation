<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\UsageReconciliationService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Throwable;

final class ReconcilePixelforgeUsage extends Command
{
    protected $signature = 'pixelforge:usage-reconcile
        {--start= : First UTC day (YYYY-MM-DD); defaults to yesterday}
        {--end= : Last UTC day (YYYY-MM-DD); defaults to start}';

    protected $description = 'Compare the Pixelforge API-key usage report against stored completed generation charges.';

    public function handle(UsageReconciliationService $reconciler): int
    {
        $default = CarbonImmutable::now('UTC')->subDay()->toDateString();
        $from = $this->option('start') ?: $default;
        $to = $this->option('end') ?: $from;

        if (! is_string($from) || ! is_string($to)) {
            $this->error('Dates must use YYYY-MM-DD.');

            return self::FAILURE;
        }

        $start = $this->parseDay($from);
        $end = $this->parseDay($to);

        if ($start === null || $end === null || $end->lessThan($start)
            || $end->greaterThan($start->addDays(91))) {
            $this->error('Supply valid YYYY-MM-DD dates in ascending order (maximum 92 days inclusive).');

            return self::FAILURE;
        }

        try {
            $result = $reconciler->reconcile($start, $end);

            foreach ($result['days'] as $day) {
                $local = $day['local'];
                $provider = $day['provider'];
                $this->line(sprintf(
                    '%s %s | generations: %d / %d | provider USD: %s / %s',
                    $day['matched'] ? 'MATCH' : 'MISMATCH',
                    $day['date'],
                    $local['generations'],
                    $provider['generations'],
                    $local['cost_usd'],
                    $provider['cost_usd'],
                ));

                if (! $day['matched']) {
                    foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $category) {
                        if ($local[$category] !== $provider[$category]) {
                            $this->warn("  {$category}: local {$local[$category]}, provider {$provider[$category]}");
                        }
                    }
                }
            }

            foreach ($result['errors'] as $error) {
                $this->error($error);
            }

            if (! $result['matched']) {
                $this->error('Provider usage does not reconcile.');

                return self::FAILURE;
            }

            $this->info('Provider usage matches locally stored successful generations.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Usage reconciliation failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function parseDay(string $date): ?CarbonImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return CarbonImmutable::instance($parsed);
    }
}
