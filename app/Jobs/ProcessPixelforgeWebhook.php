<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyPixelforgeGeneration;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class ProcessPixelforgeWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $eventId) {}

    public function handle(ApplyPixelforgeGeneration $apply): void
    {
        DB::transaction(function () use ($apply): void {
            $event = WebhookEvent::query()->lockForUpdate()->findOrFail($this->eventId);

            if ($event->processed_at !== null) {
                return;
            }

            if ($event->type === 'ping') {
                $event->processed_at = now();
                $event->save();

                return;
            }

            $snapshot = $event->payload['data']['object'] ?? null;

            if (! is_array($snapshot) || ! is_string($event->generation_id)) {
                throw new UnexpectedValueException('The stored event has no matching generation data.');
            }

            $generation = Generation::query()->lockForUpdate()->findOrFail($event->generation_id);

            // The provider ID must match if the local submission has already been acknowledged.
            $apply->execute($generation, $snapshot);

            $event->processed_at = now();
            $event->processing_error = null;
            $event->save();
        });
    }

    /** Backoff for transient missing local records or database contention. */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }
}
