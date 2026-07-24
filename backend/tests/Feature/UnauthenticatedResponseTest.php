<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Behind the Nitro proxy a clean Accept header doesn't always arrive.
 * Unauthenticated requests must still come back as 401 JSON and must not be
 * redirected to a non-existent login route.
 */
class UnauthenticatedResponseTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_protected_route_without_a_session_returns_401_json_even_without_an_accept_header(): void
    {
        // Deliberately without getJson and without X-Requested-With — the way
        // it can arrive after the proxy.
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
