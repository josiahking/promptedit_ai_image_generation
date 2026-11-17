<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\RecordPixelforgeAcknowledgement;
use App\Exceptions\PixelforgeApiException;
use App\Models\Generation;
use App\Services\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

final class SubmitPixelforgeGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly string $generationId) {}

    public function handle(PixelforgeClient $provider, RecordPixelforgeAcknowledgement $acknowledgement): void
    {
        $generation = Generation::query()->findOrFail($this->generationId);

        if ($generation->provider_generation_id !== null || $generation->isTerminal()
            || $generation->status === 'rejected') {
            return;
        }

        try {
            $response = $provider->createGeneration($generation);
            $acknowledgement->execute($generation->id, $response);
        } catch (PixelforgeApiException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($e->retryAfterSeconds ?? $this->backoffSeconds());
                return;
            }

            $this->recordFailure($e->apiCode, $e->getMessage(), $e->acceptanceUncertain);

            return;
        } catch (ConnectionException $e) {
            // A timed-out POST might have been accepted. Retry the same immutable request
            // and Idempotency-Key, never create a second local generation.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoffSeconds());
                return;
            }

            $this->recordFailure('submission_unconfirmed', 'The provider did not confirm whether the request was accepted.', true);

            return;
        } catch (UnexpectedValueException $e) {
            // Unknown acknowledgements cannot safely be treated as a rejection.
            $this->recordFailure('submission_unconfirmed', 'The provider returned an unexpected acknowledgement.', true);
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Queue-level exceptions must not make an uncertain POST look like a free failed generation.
        $this->recordFailure(
            'submission_unconfirmed',
            'Submission could not be confirmed. The request must be reconciled.',
            true,
        );
    }

    private function backoffSeconds(): int
    {
        return min(30, 2 ** max(1, $this->attempts()));
    }

    private function recordFailure(string $code, string $message, bool $uncertain): void
    {
        DB::transaction(function () use ($code, $message, $uncertain): void {
            $generation = Generation::query()->lockForUpdate()->find($this->generationId);

            if ($generation === null || $generation->provider_generation_id !== null
                || $generation->isTerminal()) {
                return;
            }

            // An unknown submission outcome is NOT a failed provider generation.
            // Leave it awaiting reconciliation instead of charging zero.
            $generation->status = $uncertain ? 'submission_unknown' : 'rejected';
            $generation->error_code = $code;
            $generation->error_message = $message;
            $generation->save();
        });
    }
}
