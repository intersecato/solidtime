<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Service\Auth\OidcService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class OidcAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_oidc_redirect_is_not_available_when_disabled(): void
    {
        config(['services.oidc.enabled' => false]);

        $this->get('/auth/oidc/redirect')->assertNotFound();
    }

    public function test_oidc_redirect_delegates_to_oidc_service(): void
    {
        $this->mock(OidcService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isEnabled')->once()->andReturn(true);
            $mock->shouldReceive('authorizationRedirectUrl')
                ->once()
                ->andReturn('https://idp.example.com/authorize?client_id=client-id');
        });

        $this->get('/auth/oidc/redirect')
            ->assertRedirect('https://idp.example.com/authorize?client_id=client-id');
    }

    public function test_oidc_callback_authenticates_user_returned_by_oidc_service(): void
    {
        $user = User::factory()->create();

        $this->mock(OidcService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('isEnabled')->once()->andReturn(true);
            $mock->shouldReceive('authenticateCallback')->once()->andReturn($user);
        });

        $this->get('/auth/oidc/callback?code=code-1&state=expected-state')
            ->assertRedirect(RouteServiceProvider::HOME);

        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_redirects_to_oidc_end_session_when_available(): void
    {
        $user = User::factory()->create();

        $this->mock(OidcService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('endSessionUrl')
                ->once()
                ->andReturn('https://idp.example.com/logout?client_id=solidtime');
        });

        $this->actingAs($user)
            ->withHeader('X-Inertia', 'true')
            ->post('/logout')
            ->assertStatus(409)
            ->assertHeader(
                'X-Inertia-Location',
                'https://idp.example.com/logout?client_id=solidtime'
            );

        $this->assertGuest();
    }
}
