<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Generation;
use Illuminate\Support\Facades\DB;
use LogicException;
use UnexpectedValueException;

final class RecordPixelforgeAcknowledgement
{
    /** @param array<string,mixed> $providerGeneration */
    public function execute(string $localId, array $providerGeneration): void
    {
        DB::transaction(function () use ($localId, $providerGeneration): void {
            $local = Generation::query()->lockForUpdate()->findOrFail($localId);
            $providerId = $providerGeneration['id'] ?? null;
            $providerStatus = $providerGeneration['status'] ?? null;
            $rates = $providerGeneration['rates'] ?? null;

            if (! is_string($providerId) || ! str_starts_with($providerId, 'gen_')
                || ! in_array($providerStatus, ['queued', 'processing', 'succeeded', 'failed'], true)
                || ! is_array($rates)) {
                throw new UnexpectedValueException('Pixelforge acknowledgement contains invalid data.');
            }

            if ($local->provider_generation_id !== null && $local->provider_generation_id !== $providerId) {
                throw new LogicException('A local generation cannot change its provider ID.');
            }

            $local->provider_generation_id = $providerId;

            if ($local->rates_snapshot === null) {
                $local->rates_snapshot = $rates;
            }

            // Webhooks may arrive before the original POST returns.
            // Acknowledgements must never reverse a terminal or processing state.
            if (! $local->isTerminal() && in_array($local->status, ['submitting', 'queued'], true)) {
                $local->status = $providerStatus === 'processing' ? 'processing' : 'queued';
            }

            if (! $local->isTerminal()) {
                $local->error_code = null;
                $local->error_message = null;
            }

            $local->provider_created_at ??= $providerGeneration['created_at'] ?? null;
            $local->save();
        });
    }
}
