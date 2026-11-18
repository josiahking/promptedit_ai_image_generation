<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PricingService;
use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyPricingTest extends TestCase
{
    private const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    public function test_exact_maximum_provider_and_customer_cost(): void
    {
        $pricing = new PricingService;
        $usage = [
            'input_text_tokens' => 115,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 8000,
        ];

        self::assertSame('0.373875000000', $pricing->providerCost($usage, self::RATES)->toDecimal());
        self::assertSame('0.467343750000', $pricing->customerCost($usage, self::RATES)->toDecimal());
    }

    public function test_one_reasoning_token_is_exact(): void
    {
        self::assertSame('0.000012500000', Money::tokenCost(1, '12.50')->toDecimal());
        self::assertSame('0.000015625000', Money::tokenCost(1, '12.50')->withMarkup()->toDecimal());
    }

    public function test_fractional_digits_are_rejected_without_rounding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimal('0.0000000000001');
    }

    public function test_missing_token_count_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PricingService)->providerCost(['input_text_tokens' => 1], self::RATES);
    }

    public function test_estimate_uses_provider_metadata_and_maximum(): void
    {
        $pricing = new PricingService;
        $data = [
            'rates' => self::RATES,
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => ['high' => ['1536x1024' => 6450]],
                'reasoning' => ['high' => ['min' => 1000, 'max' => 8000]],
            ],
        ];
        $estimate = $pricing->estimate(str_repeat('A', 400), '1536x1024', 'high', 2, $data);

        self::assertSame('0.467343750000', $estimate['maximum_usd']);
        self::assertLessThan(
            Money::fromDecimal($estimate['maximum_usd'])->toDecimal(),
            $estimate['minimum_usd']
        );
    }
}
