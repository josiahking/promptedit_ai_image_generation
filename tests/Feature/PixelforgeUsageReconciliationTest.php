<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\PersistGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\UsageReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PixelforgeUsageReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.pixelforge.key' => 'reconcile-test-key',
            'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
        ]);
    }

    /** @return array<string,string> */
    private function rates(): array
    {
        return [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];
    }

    /** @return array<string,int> */
    private function usage(): array
    {
        return [
            'input_text_tokens' => 12,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 400,
        ];
    }

    private function createSuccess(): Generation
    {
        $generation = app(PersistGeneration::class)->execute(User::factory()->create(), [
            'prompt' => 'A lantern beside a mountain',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ], [
            'rates' => $this->rates(),
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => ['medium' => ['1024x1024' => 1100]],
                'reasoning' => ['medium' => ['min' => 200, 'max' => 2000]],
            ],
        ]);

        $generation->update([
            'status' => 'succeeded',
            'usage' => $this->usage(),
            'rates_snapshot' => $this->rates(),
            'provider_cost_usd' => '0.049060000000',
            'customer_cost_usd' => '0.061325000000',
            'completed_at' => '2026-11-17T00:30:00Z',
        ]);

        return $generation;
    }

    /** @return array<string,mixed> */
    private function report(string $cost = '0.049060000', int $generations = 1): array
    {
        $data = [
            'generations' => $generations,
            'input_text_tokens' => $generations * 12,
            'input_image_tokens' => 0,
            'output_tokens' => $generations * 1100,
            'reasoning_tokens' => $generations * 400,
            'cost_usd' => $cost,
        ];

        return [
            'object' => 'usage',
            'start_date' => '2026-11-16',
            'end_date' => '2026-11-17',
            'data' => [
                [
                    'date' => '2026-11-16',
                    'generations' => 0,
                    'input_text_tokens' => 0,
                    'input_image_tokens' => 0,
                    'output_tokens' => 0,
                    'reasoning_tokens' => 0,
                    'cost_usd' => '0.000000000',
                ],
                ['date' => '2026-11-17', ...$data],
            ],
            'total' => $data,
        ];
    }

    public function test_reconciliation_matches_utc_completion_day_and_exact_provider_price(): void
    {
        $this->createSuccess();
        Http::fake(['*/v1/usage*' => Http::response($this->report(), 200)]);

        $result = app(UsageReconciliationService::class)->reconcile(
            CarbonImmutable::parse('2026-11-16', 'UTC'),
            CarbonImmutable::parse('2026-11-17', 'UTC'),
        );

        self::assertTrue($result['matched']);
        self::assertCount(2, $result['days']);
        self::assertSame('0.049060000000', $result['days'][1]['local']['cost_usd']);
        self::assertSame('0.049060000000', $result['days'][1]['provider']['cost_usd']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'start_date=2026-11-16')
            && str_contains($request->url(), 'end_date=2026-11-17')
            && $request->hasHeader('Authorization', 'Bearer reconcile-test-key'));
    }

    public function test_provider_daily_mismatch_returns_failure_and_nonzero_command_exit(): void
    {
        $this->createSuccess();
        Http::fake(['*/v1/usage*' => Http::response($this->report('0.049070000'), 200)]);

        $this->artisan('pixelforge:usage-reconcile', [
            '--start' => '2026-11-16',
            '--end' => '2026-11-17',
        ])->assertFailed();
    }

    public function test_tampered_local_charge_is_flagged_even_when_provider_daily_total_matches(): void
    {
        $generation = $this->createSuccess();
        $generation->setAttribute('customer_cost_usd', '0.070000000000');
        $generation->save();

        Http::fake(['*/v1/usage*' => Http::response($this->report(), 200)]);

        $result = app(UsageReconciliationService::class)->reconcile(
            CarbonImmutable::parse('2026-11-16', 'UTC'),
            CarbonImmutable::parse('2026-11-17', 'UTC'),
        );

        self::assertFalse($result['matched']);
        self::assertNotEmpty($result['errors']);
    }

    public function test_invalid_range_is_rejected_before_external_api_request(): void
    {
        Http::fake();

        $this->artisan('pixelforge:usage-reconcile', [
            '--start' => '2026-11-31',
            '--end' => '2026-12-02',
        ])->assertFailed();

        $this->artisan('pixelforge:usage-reconcile', [
            '--start' => '2026-01-01',
            '--end' => '2026-06-01',
        ])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_empty_days_reconcile_without_generations(): void
    {
        Http::fake(['*/v1/usage*' => Http::response($this->report('0.000000000', 0), 200)]);

        $result = app(UsageReconciliationService::class)->reconcile(
            CarbonImmutable::parse('2026-11-16', 'UTC'),
            CarbonImmutable::parse('2026-11-17', 'UTC'),
        );

        self::assertTrue($result['matched']);
        self::assertSame(0, $result['days'][1]['local']['generations']);
    }

    public function test_provider_error_is_not_reported_as_a_successful_reconciliation(): void
    {
        Http::fake(['*/v1/usage*' => Http::response([
            'error' => ['code' => 'invalid_api_key', 'message' => 'Unauthorized'],
        ], 401)]);

        $this->artisan('pixelforge:usage-reconcile', [
            '--start' => '2026-11-17',
            '--end' => '2026-11-17',
        ])->assertFailed();
    }
}
