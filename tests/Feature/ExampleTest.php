<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Push Button 👉 Receive Paper')
            ->assertSee("url('/images/textures/banner-paper-blue.webp')", false)
            ->assertSee("url('/images/textures/sidebar-paper-green.webp')", false)
            ->assertSee("url('/images/textures/main-washi-paper.webp')", false)
            ->assertSee('aria-controls="main-menu-panel"', false)
            ->assertSee('aria-label="Close menu"', false)
            ->assertSee('Login');
    }
}
