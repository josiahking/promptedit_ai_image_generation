<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class StudioLandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_view_public_studio_landing_page(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Welcome'));

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk();
        $this->get(route('register'))->assertOk();
    }

    public function test_authenticated_user_can_still_access_landing_page_and_private_studio(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Welcome'));

        $this->get(route('dashboard'))->assertOk();
    }
}
