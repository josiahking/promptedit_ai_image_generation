<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\PersistGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GenerationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_record_is_saved_before_provider_submission(): void
    {
        $user = User::factory()->create();
        $pricing = [
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
        $result = app(PersistGeneration::class)->execute($user, [
            'prompt' => 'A landscape',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ], $pricing);

        self::assertSame('submitting', $result->status);
        self::assertSame($user->getKey(), $result->user_id);
        self::assertNotEmpty($result->idempotency_key);
        self::assertNull($result->provider_generation_id);
        self::assertSame(1, Generation::query()->count());
    }
}
