<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Generation;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Support\Str;

/** Local record is committed before external POST; retries reuse idempotency_key. */
final readonly class PersistGeneration
{
    public function __construct(private PricingService $pricing) {}

    /**
     * @param  array{prompt:string,size:string,quality:string,reference_images:array<int,string>}  $input
     * @param  array<string,mixed>  $pricingSnapshot
     */
    public function execute(User $user, array $input, array $pricingSnapshot): Generation
    {
        $estimate = $this->pricing->estimate(
            $input['prompt'],
            $input['size'],
            $input['quality'],
            count($input['reference_images']),
            $pricingSnapshot
        );

        return Generation::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => $input['prompt'],
            'size' => $input['size'],
            'quality' => $input['quality'],
            'reference_images' => $input['reference_images'],
            'status' => 'submitting',
            'estimated_customer_usd' => $estimate['estimated_usd'],
            'minimum_customer_usd' => $estimate['minimum_usd'],
            'maximum_customer_usd' => $estimate['maximum_usd'],
        ]);
    }
}
