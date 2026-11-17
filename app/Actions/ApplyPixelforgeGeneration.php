<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Generation;
use App\Services\PricingService;
use UnexpectedValueException;

/**
 * Applies a provider snapshot to an exclusively locked local generation.
 * Webhooks and polling share this state transition and billing policy.
 */
final readonly class ApplyPixelforgeGeneration
{
    public function __construct(private PricingService $pricing) {}

    /** @param array<string, mixed> $snapshot */
    public function execute(Generation $generation, array $snapshot): void
    {
        $providerId = $snapshot['id'] ?? null;
        $status = $snapshot['status'] ?? null;
        $rates = $snapshot['rates'] ?? null;

        if (! is_string($providerId) || ! str_starts_with($providerId, 'gen_')
            || ! in_array($status, ['queued', 'processing', 'succeeded', 'failed'], true)
            || ! is_array($rates)
            || ($snapshot['metadata']['order_id'] ?? null) !== $generation->id
            || ($generation->provider_generation_id !== null && $generation->provider_generation_id !== $providerId)) {
            throw new UnexpectedValueException('Provider snapshot does not match the saved generation.');
        }

        $existingRates = $generation->rates_snapshot;
        if (is_array($existingRates)) {
            foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $category) {
                if (($rates[$category] ?? null) !== ($existingRates[$category] ?? null)) {
                    throw new UnexpectedValueException('The provider rate snapshot changed after creation.');
                }
            }
        }

        if ($generation->isTerminal()) {
            return;
        }

        $generation->provider_generation_id = $providerId;
        $generation->setAttribute('rates_snapshot', $rates);
        $generation->provider_created_at ??= $snapshot['created_at'] ?? null;

        if ($status === 'queued' || $status === 'processing') {
            if ($status === 'processing' || in_array($generation->status, ['submitting', 'submission_unknown'], true)) {
                $generation->status = $status;
            }

            $generation->started_at ??= $snapshot['started_at'] ?? null;
            $generation->error_code = null;
            $generation->error_message = null;
            $generation->save();

            return;
        }

        if (! is_string($snapshot['completed_at'] ?? null)) {
            throw new UnexpectedValueException('Completed generation is missing a completion timestamp.');
        }

        $generation->started_at ??= $snapshot['started_at'] ?? null;
        $generation->completed_at = $snapshot['completed_at'];

        if ($status === 'failed') {
            $error = $snapshot['error'] ?? null;

            if (! is_array($error) || ! is_string($error['code'] ?? null)) {
                throw new UnexpectedValueException('Failed generation has no provider error code.');
            }

            $generation->status = 'failed';
            $generation->provider_cost_usd = '0.000000000000';
            $generation->customer_cost_usd = '0.000000000000';
            $generation->error_code = $error['code'];
            $generation->error_message = is_string($error['message'] ?? null) ? $error['message'] : null;
            $generation->save();

            return;
        }

        $usage = $snapshot['usage'] ?? null;
        $image = $snapshot['image_url'] ?? null;

        if (! is_array($usage) || ! is_string($image) || $image === '') {
            throw new UnexpectedValueException('Succeeded generation is missing token usage or an image URL.');
        }

        $cost = $this->pricing->providerCost($usage, $rates);

        $generation->status = 'succeeded';
        $generation->setAttribute('usage', $usage);
        $generation->provider_cost_usd = $cost->toDecimal();
        $generation->customer_cost_usd = $cost->withMarkup()->toDecimal();
        $generation->image_url = $image;
        $generation->image_url_expires_at = $snapshot['image_url_expires_at'] ?? null;
        $generation->error_code = null;
        $generation->error_message = null;
        $generation->save();
    }
}
