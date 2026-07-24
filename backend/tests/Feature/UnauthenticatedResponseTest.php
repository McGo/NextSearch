<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Hinter dem Nitro-Proxy kommt nicht immer ein sauberer Accept-Header an.
 * Unangemeldete Anfragen müssen trotzdem als 401-JSON zurückkommen und dürfen
 * nicht auf eine nicht existierende Login-Route umgeleitet werden.
 */
class UnauthenticatedResponseTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_protected_route_without_a_session_returns_401_json_even_without_an_accept_header(): void
    {
        // Bewusst ohne getJson und ohne X-Requested-With — so, wie es nach dem
        // Proxy ankommen kann.
        $this->get('/api/search?q=test')
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    #[Test]
    public function the_admin_area_without_a_session_is_also_401_not_a_redirect(): void
    {
        $this->get('/api/admin/instances')
            ->assertStatus(401)
            ->assertHeaderMissing('Location');
    }
}
