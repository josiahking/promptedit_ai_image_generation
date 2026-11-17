<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\PersistGeneration;
use App\Jobs\SubmitPixelforgeGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class GenerationStudioTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,mixed> */
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
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    private function makeGeneration(User $user): Generation
    {
        return app(PersistGeneration::class)->execute($user, [
            'prompt' => 'A mountain beside a quiet lake',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ], $this->pricing());
    }

    /** @return array<string,mixed> */
    private function input(): array
    {
        return [
            'prompt' => 'A mountain beside a quiet lake',
            'quality' => 'medium',
            'size' => '1024x1024',
            'reference_images' => [],
        ];
    }

    public function test_guest_cannot_access_private_endpoints(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->postJson(route('generations.estimate'), $this->input())->assertUnauthorized();
        $this->post(route('generations.store'), $this->input())->assertRedirect(route('login'));
        $this->get(route('generations.status', ['id' => '00000000-0000-0000-0000-000000000000']))
            ->assertRedirect(route('login'));
    }

    public function test_dashboard_shows_only_current_users_history(): void
    {
        $owner = User::factory()->create();
        $another = User::factory()->create();
        $own = $this->makeGeneration($owner);
        $other = $this->makeGeneration($another);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('generations', 1)
                ->where('generations.0.id', $own->id)
                ->missing('generations.1'));
        $this->get(route('generations.status', ['id' => $other->id]))->assertNotFound();
        $this->get(route('generations.status', ['id' => $own->id]))
            ->assertOk()->assertJsonPath('id', $own->id);
    }

    public function test_quote_is_calculated_on_server_with_markup_and_range(): void
    {
        Http::fake(['*/v1/pricing' => Http::response($this->pricing(), 200)]);
        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.estimate'), [
                'prompt' => str_repeat('A', 400),
                'quality' => 'high',
                'size' => '1536x1024',
                'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
            ])->assertOk()
            ->assertJsonPath('maximum_usd', '0.467343750000')
            ->assertJsonStructure(['estimated_usd', 'minimum_usd', 'maximum_usd']);
        Http::assertSentCount(1);
    }

    public function test_submission_queues_an_owned_generation(): void
    {
        Queue::fake();
        Http::fake(['*/v1/pricing' => Http::response($this->pricing(), 200)]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('generations.store'), $this->input())
            ->assertRedirect(route('dashboard'));

        $record = Generation::query()->sole();
        self::assertSame($user->id, $record->user_id);
        self::assertSame('submitting', $record->status);
        Queue::assertPushed(SubmitPixelforgeGeneration::class);
        Http::assertSentCount(1);
    }

    public function test_submission_rejects_invalid_configuration(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), [
                'prompt' => 'Test',
                'quality' => 'ultra',
                'size' => 'badsize',
                'reference_images' => array_fill(0, 5, 'https://example.com/image.jpg'),
            ])
            ->assertSessionHasErrors(['quality', 'size', 'reference_images']);

        self::assertSame(0, Generation::query()->count());
    }

    public function test_quote_rejects_invalid_reference_urls(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.estimate'), [
                'prompt' => 'Test',
                'quality' => 'medium',
                'size' => '1024x1024',
                'reference_images' => ['file:///etc/passwd'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reference_images.0']);
    }

    public function test_expired_image_is_hidden_but_the_charge_is_retained(): void
    {
        $user = User::factory()->create();
        $record = $this->makeGeneration($user);
        $record->update([
            'status' => 'succeeded',
            'provider_cost_usd' => '0.100000000000',
            'customer_cost_usd' => '0.125000000000',
            'image_url' => 'https://example.com/expired.png',
            'image_url_expires_at' => now()->subHour(),
            'completed_at' => now()->subDays(2),
        ]);

        $this->actingAs($user)
            ->get(route('generations.status', ['id' => $record->id]))
            ->assertOk()
            ->assertJsonPath('image_url', null)
            ->assertJsonPath('image_expired', true)
            ->assertJsonPath('customer_cost_usd', '0.125000000000');
    }

    public function test_price_service_failure_does_not_persist_an_order(): void
    {
        Http::fake(['*/v1/pricing' => Http::response(['error' => ['code' => 'overloaded']], 503)]);
        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), $this->input())
            ->assertSessionHasErrors(['prompt']);
        self::assertSame(0, Generation::query()->count());
    }
}
