<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ApplyPixelforgeGeneration;
use App\Actions\PersistGeneration;
use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use UnexpectedValueException;

final class PixelforgeWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.webhook_secret' => 'whsec_test']);
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
            'prompt' => 'A sea at sunrise',
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

    /** @return array<string, mixed> */
    private function snapshot(Generation $generation, string $status): array
    {
        return [
            'id' => 'gen_abc123',
            'object' => 'generation',
            'status' => $status,
            'metadata' => ['order_id' => $generation->id],
            'rates' => $this->rates(),
            'created_at' => '2026-11-17T10:00:00Z',
            'started_at' => '2026-11-17T10:00:01Z',
            'completed_at' => in_array($status, ['succeeded', 'failed'], true) ? '2026-11-17T10:00:10Z' : null,
            'usage' => $status === 'succeeded' ? [
                'input_text_tokens' => 12,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 400,
                'total_tokens' => 1512,
            ] : null,
            'image_url' => $status === 'succeeded' ? 'https://pixelforge.promptedit.dev/images/abc.png' : null,
            'image_url_expires_at' => $status === 'succeeded' ? '2026-11-18T10:00:10Z' : null,
            'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'Generation failed.'] : null,
        ];
    }

    /** @param array<string,mixed> $event */
    private function send(array $event, ?int $timestamp = null, bool $tamper = false): \Illuminate\Testing\TestResponse
    {
        $json = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $hash = hash_hmac('sha256', $timestamp.'.'.$json, 'whsec_test');

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => 't='.$timestamp.',v1='.($tamper ? str_repeat('0', 64) : $hash),
            'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
            'HTTP_PIXELFORGE_EVENT_TYPE' => $event['type'],
        ], $json);
    }

    /** @return array<string,mixed> */
    private function event(Generation $generation, string $status, string $id = 'evt_123'): array
    {
        return [
            'id' => $id,
            'type' => 'generation.'.$status,
            'created_at' => '2026-11-17T10:00:10Z',
            'data' => ['object' => $this->snapshot($generation, $status)],
        ];
    }

    public function test_succeeded_event_is_received_durably_and_billed_once(): void
    {
        Queue::fake();
        $generation = $this->generation();
        $this->send($this->event($generation, 'succeeded'))->assertNoContent();
        self::assertSame(1, WebhookEvent::query()->count());

        app(ProcessPixelforgeWebhook::class, ['eventId' => 'evt_123'])->handle(app(ApplyPixelforgeGeneration::class));

        $fresh = $generation->fresh();
        self::assertSame('succeeded', $fresh->status);
        self::assertSame('0.049060000000', $fresh->provider_cost_usd);
        self::assertSame('0.061325000000', $fresh->customer_cost_usd);
        self::assertNotNull(WebhookEvent::query()->findOrFail('evt_123')->processed_at);
        Queue::assertPushed(ProcessPixelforgeWebhook::class);
    }

    public function test_duplicate_event_id_cannot_charge_twice(): void
    {
        Queue::fake();
        $generation = $this->generation();
        $event = $this->event($generation, 'succeeded');
        $this->send($event)->assertNoContent();
        $this->send($event)->assertNoContent();
        self::assertSame(1, WebhookEvent::query()->count());

        $job = new ProcessPixelforgeWebhook('evt_123');
        $job->handle(app(ApplyPixelforgeGeneration::class));
        $job->handle(app(ApplyPixelforgeGeneration::class));
        self::assertSame('0.061325000000', $generation->fresh()->customer_cost_usd);
    }

    public function test_late_processing_cannot_reverse_succeeded(): void
    {
        Queue::fake();
        $generation = $this->generation();

        $this->send($this->event($generation, 'succeeded', 'evt_success'))->assertNoContent();
        (new ProcessPixelforgeWebhook('evt_success'))->handle(app(ApplyPixelforgeGeneration::class));

        $this->send($this->event($generation, 'processing', 'evt_processing'))->assertNoContent();
        (new ProcessPixelforgeWebhook('evt_processing'))->handle(app(ApplyPixelforgeGeneration::class));

        self::assertSame('succeeded', $generation->fresh()->status);
        self::assertSame('0.061325000000', $generation->fresh()->customer_cost_usd);
    }

    public function test_failed_generation_is_free(): void
    {
        Queue::fake();
        $generation = $this->generation();
        $this->send($this->event($generation, 'failed'))->assertNoContent();
        (new ProcessPixelforgeWebhook('evt_123'))->handle(app(ApplyPixelforgeGeneration::class));

        self::assertSame('failed', $generation->fresh()->status);
        self::assertSame('0.000000000000', $generation->fresh()->customer_cost_usd);
        self::assertSame('generation_failed', $generation->fresh()->error_code);
    }

    public function test_invalid_signature_and_old_timestamp_are_rejected_before_storage(): void
    {
        $generation = $this->generation();
        $event = $this->event($generation, 'succeeded');
        $this->send($event, null, true)->assertStatus(400);
        $this->send($event, time() - 301)->assertStatus(400);

        self::assertSame(0, WebhookEvent::query()->count());
    }

    public function test_header_mismatch_is_rejected(): void
    {
        $generation = $this->generation();
        $event = $this->event($generation, 'succeeded');
        $event['type'] = 'generation.failed';
        $this->send($event)->assertStatus(400);
        self::assertSame(0, WebhookEvent::query()->count());
    }

    public function test_ping_is_accepted_without_generation(): void
    {
        Queue::fake();
        $this->send(['id' => 'evt_ping', 'type' => 'ping', 'data' => ['object' => []]])->assertNoContent();
        self::assertSame(1, WebhookEvent::query()->count());
        Queue::assertNotPushed(ProcessPixelforgeWebhook::class);
    }

    public function test_mismatched_order_id_rolls_back_billing_and_receipt_processing(): void
    {
        Queue::fake();
        $generation = $this->generation();
        $event = $this->event($generation, 'succeeded');
        $event['data']['object']['metadata']['order_id'] = '00000000-0000-0000-0000-000000000000';
        $this->send($event)->assertNoContent();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        (new ProcessPixelforgeWebhook('evt_123'))->handle(app(ApplyPixelforgeGeneration::class));
    }

    public function test_rate_changes_are_not_accepted_for_same_generation(): void
    {
        $generation = $this->generation();
        $generation->setAttribute('rates_snapshot', $this->rates());
        $generation->save();

        $snapshot = $this->snapshot($generation, 'succeeded');
        $snapshot['rates']['output_tokens'] = '99.00';

        $this->expectException(UnexpectedValueException::class);
        app(ApplyPixelforgeGeneration::class)->execute($generation, $snapshot);
    }
}
