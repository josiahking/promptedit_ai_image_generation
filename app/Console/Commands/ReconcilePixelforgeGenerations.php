<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ApplyPixelforgeGeneration;
use App\Jobs\ProcessPixelforgeWebhook;
use App\Jobs\SubmitPixelforgeGeneration;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\PixelforgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReconcilePixelforgeGenerations extends Command
{
    protected $signature = 'pixelforge:reconcile';

    protected $description = 'Recover missing Pixelforge webhook events and stale generation statuses.';

    public function handle(PixelforgeClient $provider, ApplyPixelforgeGeneration $apply): int
    {
        // Recover event-processing jobs lost after durable inbox insertion.
        WebhookEvent::query()
            ->whereNull('processed_at')
            ->where('created_at', '<=', now()->subMinute())
            ->orderBy('created_at')
            ->limit(100)
            ->pluck('id')
            ->each(static fn (string $id) => ProcessPixelforgeWebhook::dispatch($id));

        Generation::query()
            ->whereIn('status', ['submitting', 'submission_unknown', 'queued', 'processing'])
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->orderBy('updated_at')
            ->limit(50)
            ->get()
            ->each(function (Generation $generation) use ($provider, $apply): void {
                if ($generation->provider_generation_id === null) {
                    // Recover an ambiguous create with exactly its original Idempotency-Key.
                    // The submit job will not create another local request.
                    SubmitPixelforgeGeneration::dispatch($generation->id);

                    return;
                }

                try {
                    $snapshot = $provider->findGeneration($generation->provider_generation_id);

                    DB::transaction(function () use ($generation, $snapshot, $apply): void {
                        $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);
                        $apply->execute($locked, $snapshot);
                    });
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        return self::SUCCESS;
    }
}
