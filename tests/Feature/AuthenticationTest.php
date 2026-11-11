<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_sign_in_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'authorized@example.test',
            'password' => 'private-test-password',
            'role' => 'superadmin',
        ]);

        $previousSessionToken = 'previous-session-token';

        $this->withSession(['_token' => $previousSessionToken])
            ->post(route('login.store'), [
                'email' => 'authorized@example.test',
                'password' => 'private-test-password',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($previousSessionToken, session()->token());

        $this->get(route('gestion'))->assertOk();
        $this->get(route('perfil'))
            ->assertOk()
            ->assertSee('Autenticado')
            ->assertSee('ID de cuenta: '.$user->getAuthIdentifier());
    }

    public function test_invalid_credentials_are_rejected_without_preserving_password(): void
    {
        User::factory()->create([
            'email' => 'person@example.test',
            'password' => 'correct-private-password',
        ]);

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => 'person@example.test',
                'password' => 'incorrect-private-password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email')
            ->assertSessionHas('_old_input.email', 'person@example.test')
            ->assertSessionMissing('_old_input.password');

        $this->assertGuest();
    }

    public function test_gestion_requires_an_authenticated_user(): void
    {
        $this->get(route('gestion'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_sees_the_active_account_id_on_profile(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($user, 'web')
            ->get(route('perfil'))
            ->assertOk()
            ->assertSee('Autenticado')
            ->assertSee('ID de cuenta: '.$user->getAuthIdentifier());
    }

    public function test_guest_profile_reports_no_authenticated_account(): void
    {
        $this->get(route('perfil'))
            ->assertOk()
            ->assertSee('Sin autenticar')
            ->assertSee('ID de cuenta: no disponible')
            ->assertDontSee('ID de cuenta: 1');
    }

    public function test_authenticated_user_without_administration_permission_is_forbidden(): void
    {
        $user = User::factory()->create(['role' => 'operador']);

        $this->actingAs($user)
            ->get(route('gestion'))
            ->assertForbidden();
    }

    public function test_user_can_sign_out_and_session_is_invalidated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
