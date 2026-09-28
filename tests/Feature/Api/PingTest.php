<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;

class PingTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_ping_returns_pong(): void
    {
        $this->get('/api/ping')
            ->assertOk()
            ->assertContent('PONG');

        $this->assertDatabaseHas('Health', ['Message' => 'Okay!']);
    }
}
