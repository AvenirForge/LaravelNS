<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_throttling_middleware_blocks_excessive_requests()
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($user, 'api')
                ->getJson('/api/me/profile')
                ->assertStatus(200);
        }

        $this->actingAs($user, 'api')
            ->getJson('/api/me/profile')
            ->assertStatus(429);
    }

    public function test_user_cannot_bypass_email_verification_via_mass_assignment()
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user, 'api')->patchJson('/api/me/profile', [
            'name' => 'Hacker',
            'email_verified_at' => now(),
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Hacker',
            'email_verified_at' => null,
        ]);
    }
}
