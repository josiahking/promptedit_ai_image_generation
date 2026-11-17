<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\RecordPixelforgeAcknowledgement;
use App\Actions\SubmitGeneration;
use App\Jobs\SubmitPixelforgeGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\PixelforgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PixelforgeSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
            'services.pixelforge.key' => 'test-secret',
        ]);
    }

    /** @return array<string, mixed> */
    private function pricing(): array
    {
        return [
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => ['medium' => ['1024x1024' => 1100]],
                'reasoning' => ['medium' => ['min' => 200, 'max' => 2000]],
            ],
        ];
    }

    private function makeGeneration(): Generation
    {
        return app(\App\Actions\PersistGeneration::class)->execute(User::factory()->create(), [
            'prompt' => 'A calm sea',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => ['https://example.org/reference.png'],
        ], $this->pricing());
    }

    /** @return array<string, mixed> */
    private function acknowledgement(Generation $generation, string $status = 'queued'): array
    {
        return [
            'id' => 'gen_test_123',
            'status' => $status,
            'rates' => $this->pricing()['rates'],
            'created_at' => '2026-11-17T10:00:00Z',
            'metadata' => ['order_id' => $generation->id],
        ];
    }

    public function test_submission_persists_record_and_queues_provider_post(): void
    {
        Queue::fake();

        Http::fake([
            '*/v1/pricing' => Http::response($this->pricing(), 200),
        ]);

        $generation = app(SubmitGeneration::class)->execute(User::factory()->create(), [
            'prompt' => 'A calm sea',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ]);

        self::assertSame('submitting', $generation->fresh()->status);
        self::assertNotEmpty($generation->idempotency_key);
        Queue::assertPushed(SubmitPixelforgeGeneration::class, fn (SubmitPixelforgeGeneration $job): bool => $job->generationId === $generation->id);
        Http::assertSentCount(1); // Pricing only; generation POST runs later in queue.
    }

    public function test_create_posts_exact_persisted_payload_and_records_acknowledgement(): void
    {
        $generation = $this->makeGeneration();
        $body = $this->acknowledgement($generation);

        Http::fake(['*/v1/generations' => Http::response($body, 202)]);

        app(SubmitPixelforgeGeneration::class, ['generationId' => $generation->id])->handle(
            app(PixelforgeClient::class),
            app(RecordPixelforgeAcknowledgement::class),
        );

        Http::assertSent(function (Request $request) use ($generation): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://pixelforge.promptedit.dev/v1/generations'
                && $request->hasHeader('Authorization', 'Bearer test-secret')
                && $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
                && $request['metadata']['order_id'] === $generation->id
                && $request['prompt'] === 'A calm sea'
                && $request['reference_images'] === ['https://example.org/reference.png'];
        });

        self::assertSame('gen_test_123', $generation->fresh()->provider_generation_id);
        self::assertSame('queued', $generation->fresh()->status);
        self::assertSame($this->pricing()['rates'], $generation->fresh()->rates_snapshot);
    }

    public function test_repeated_job_does_not_submit_an_already_acknowledged_generation(): void
    {
        $generation = $this->makeGeneration();
        Http::fake(['*/v1/generations' => Http::response($this->acknowledgement($generation), 202)]);

        $job = new SubmitPixelforgeGeneration($generation->id);
        $job->handle(app(PixelforgeClient::class), app(RecordPixelforgeAcknowledgement::class));
        $job->handle(app(PixelforgeClient::class), app(RecordPixelforgeAcknowledgement::class));

        Http::assertSentCount(1);
    }

    public function test_permanent_validation_rejection_is_not_a_billed_failure(): void
    {
        $generation = $this->makeGeneration();
        Http::fake(['*/v1/generations' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'code' => 'validation_failed', 'message' => 'Invalid size'],
        ], 422)]);

        (new SubmitPixelforgeGeneration($generation->id))->handle(
            app(PixelforgeClient::class),
            app(RecordPixelforgeAcknowledgement::class),
        );

        $fresh = $generation->fresh();
        self::assertSame('rejected', $fresh->status);
        self::assertSame('validation_failed', $fresh->error_code);
        self::assertNull($fresh->customer_cost_usd);
        self::assertNull($fresh->provider_generation_id);
    }

    public function test_overload_releases_job_without_creating_a_new_record(): void
    {
        $generation = $this->makeGeneration();
        Http::fake(['*/v1/generations' => Http::response([
            'error' => ['code' => 'overloaded', 'message' => 'Overloaded'],
        ], 503, ['Retry-After' => '2'])]);

        $job = (new SubmitPixelforgeGeneration($generation->id))->withFakeQueueInteractions();
        $job->handle(app(PixelforgeClient::class), app(RecordPixelforgeAcknowledgement::class));

        $job->assertReleased(2);
        self::assertSame('submitting', $generation->fresh()->status);
        self::assertSame(1, Generation::query()->count());
    }

    public function test_timeout_is_retried_without_marking_generation_failed(): void
    {
        $generation = $this->makeGeneration();
        Http::fake(function (Request $request): never {
            throw new ConnectionException('Timeout during request');
        });

        $job = (new SubmitPixelforgeGeneration($generation->id))->withFakeQueueInteractions();
        $job->handle(app(PixelforgeClient::class), app(RecordPixelforgeAcknowledgement::class));

        $job->assertReleased();
        self::assertSame('submitting', $generation->fresh()->status);
        self::assertNull($generation->fresh()->customer_cost_usd);
    }

    public function test_late_acknowledgement_never_reverses_terminal_webhook_state(): void
    {
        $generation = $this->makeGeneration();
        $generation->update([
            'status' => 'succeeded',
            'customer_cost_usd' => '0.137500000000',
            'provider_cost_usd' => '0.110000000000',
        ]);

        app(RecordPixelforgeAcknowledgement::class)->execute($generation->id, $this->acknowledgement($generation));

        self::assertSame('succeeded', $generation->fresh()->status);
        self::assertSame('0.137500000000', $generation->fresh()->customer_cost_usd);
    }
}
