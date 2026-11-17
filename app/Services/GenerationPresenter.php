<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Generation;
use Carbon\CarbonImmutable;

final class GenerationPresenter
{
    /** @return array<string, mixed> */
    public function toArray(Generation $generation): array
    {
        $completed = $generation->getAttribute('completed_at');
        $completedAt = $completed instanceof CarbonImmutable ? $completed : null;
        $expiration = $generation->getAttribute('image_url_expires_at');
        $expiresAt = $expiration instanceof CarbonImmutable ? $expiration : $completedAt?->addDay();

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
            'completed_at' => $completedAt?->toIso8601String(),
        ];
    }
}
