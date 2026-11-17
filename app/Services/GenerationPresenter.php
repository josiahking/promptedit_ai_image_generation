<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Generation;

final class GenerationPresenter
{
    /** @return array<string, mixed> */
    public function toArray(Generation $generation): array
    {
        $expiresAt = $generation->image_url_expires_at
            ?? $generation->completed_at?->addDay();

        $expired = $generation->status === 'succeeded'
            && $expiresAt !== null
            && $expiresAt->isPast();

        return [
            'id' => $generation->id,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images,
            'status' => $generation->status,
            'estimated_customer_usd' => $generation->getAttribute('estimated_customer_usd'),
            'minimum_customer_usd' => $generation->getAttribute('minimum_customer_usd'),
            'maximum_customer_usd' => $generation->getAttribute('maximum_customer_usd'),
            'provider_cost_usd' => $generation->getAttribute('provider_cost_usd'),
            'customer_cost_usd' => $generation->getAttribute('customer_cost_usd'),
            'image_url' => $expired ? null : $generation->image_url,
            'image_expired' => $expired,
            'error_code' => $generation->error_code,
            'error_message' => $generation->error_message,
            'created_at' => $generation->created_at?->toIso8601String(),
            'completed_at' => $generation->completed_at?->toIso8601String(),
        ];
    }
}
