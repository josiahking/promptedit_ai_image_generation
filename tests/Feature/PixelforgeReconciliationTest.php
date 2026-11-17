<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\PersistGeneration;
use App\Jobs\SubmitPixelforgeGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PixelforgeReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.pixelforge.key' => 'test-key',
            'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
        ]);
    }

    /** @return array<string, string> */
    private function rates(): array
    {
        return [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];
    }

    private function generation(): Generation
    {
        return app(PersistGeneration::class)->execute(User::factory()->create(), [
            'prompt' => 'A landscape',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ], [
            'rates' => $this->rates(),
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => ['medium' => ['1024x1024' => 1100]],
                'reasoning' => ['medium' => ['min' => 200, 'max' => 2000]],
            ],
        ]);
    }

    public function test_polling_repairs_a_stale_processing_generation(): void
    {
        Queue::fake();
        $generation = $this->generation();
        $generation->provider_generation_id = 'gen_poll_123';
        $generation->status = 'processing';
        $generation->save();
        $generation->timestamps = false;
        $generation->updated_at = now()->subMinutes(4);
        $generation->save();

        Http::fake(['*/v1/generations/gen_poll_123' => Http::response([
            'id' => 'gen_poll_123',
            'status' => 'succeeded',
            'metadata' => ['order_id' => $generation->id],
            'rates' => $this->rates(),
            'created_at' => '2026-11-17T10:00:00Z',
            'started_at' => '2026-11-17T10:00:01Z',
            'completed_at' => '2026-11-17T10:00:12Z',
            'usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 400,
            ],
            'image_url' => 'https://pixelforge.promptedit.dev/images/poll.png',
        ], 200)]);

        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://pixelforge.promptedit.dev/v1/generations/gen_poll_123'
                && $request->hasHeader('Authorization', 'Bearer test-key');
        });

        self::assertSame('succeeded', $generation->fresh()->status);
        self::assertSame('0.049060000000', $generation->fresh()->provider_cost_usd);
        self::assertSame('0.061325000000', $generation->fresh()->customer_cost_usd);
    }

    public function test_uncertain_creation_is_retried_using_saved_local_identity(): void
    {
        Queue::fake();
        $generation = $this->generation();
        $originalKey = $generation->idempotency_key;
        $generation->status = 'submission_unknown';
        $generation->save();
        $generation->timestamps = false;
        $generation->updated_at = now()->subMinutes(4);
        $generation->save();

        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        Queue::assertPushed(SubmitPixelforgeGeneration::class, fn (SubmitPixelforgeGeneration $job): bool => $job->generationId === $generation->id);
        self::assertSame($originalKey, $generation->fresh()->idempotency_key);
        self::assertSame(1, Generation::query()->count());
    }
}
