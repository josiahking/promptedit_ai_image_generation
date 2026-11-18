<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Money;
use InvalidArgumentException;

final class PricingService
{
    private const CATEGORIES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $rates
     */
    public function providerCost(array $usage, array $rates): Money
    {
        $cost = Money::zero();
        foreach (self::CATEGORIES as $category) {
            if (! isset($usage[$category], $rates[$category]) || ! is_int($usage[$category]) || ! is_string($rates[$category])) {
                throw new InvalidArgumentException("Missing or invalid pricing category: {$category}");
            }
            $cost = $cost->add(Money::tokenCost($usage[$category], $rates[$category]));
        }

        return $cost;
    }

    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $rates
     */
    public function customerCost(array $usage, array $rates): Money
    {
        return $this->providerCost($usage, $rates)->withMarkup();
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @return array{estimated_usd:string,minimum_usd:string,maximum_usd:string}
     */
    public function estimate(string $prompt, string $size, string $quality, int $references, array $pricing): array
    {
        $tokens = $pricing['tokens'] ?? [];
        $rates = $pricing['rates'] ?? [];
        if (! is_array($tokens) || ! is_array($rates)) {
            throw new InvalidArgumentException('Invalid pricing payload.');
        }
        $characters = preg_match_all('/./us', $prompt);
        if ($characters === false || $characters < 1 || $characters > 4000 || $references < 0 || $references > 4) {
            throw new InvalidArgumentException('Invalid prompt or reference image count.');
        }
        $perText = $tokens['characters_per_text_token'] ?? null;
        $variance = $tokens['text_token_variance'] ?? null;
        $perImage = $tokens['per_reference_image'] ?? null;
        $output = $tokens['output'][$quality][$size] ?? null;
        $reason = $tokens['reasoning'][$quality] ?? null;
        if ($perText !== 4 || (string) $variance !== '0.15' || ! is_int($perImage) ||
            ! is_int($output) || ! is_int($reason['min'] ?? null) || ! is_int($reason['max'] ?? null)) {
            throw new InvalidArgumentException('Unsupported or missing Pixelforge pricing metadata.');
        }
        $text = intdiv($characters + 3, 4);
        $makeUsage = static fn (int $textCount, int $reasonCount): array => [
            'input_text_tokens' => $textCount,
            'input_image_tokens' => $references * $perImage,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasonCount,
        ];

        return [
            'estimated_usd' => $this->customerCost($makeUsage($text, intdiv($reason['min'] + $reason['max'], 2)), $rates)->toDecimal(),
            'minimum_usd' => $this->customerCost($makeUsage(intdiv($text * 85, 100), $reason['min']), $rates)->toDecimal(),
            'maximum_usd' => $this->customerCost($makeUsage(intdiv($text * 115 + 99, 100), $reason['max']), $rates)->toDecimal(),
        ];
    }
}
