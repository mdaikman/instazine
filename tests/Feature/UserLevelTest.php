<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UserLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_buttonpusher_routes_are_public(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Login');
    }

    public function test_reporter_routes_require_a_reporter_or_honcho(): void
    {
        Route::middleware('level:reporter,honcho')->get('/reporter-only', fn () => 'Reporter area');

        $this->get('/reporter-only')->assertRedirect(route('login'));

        $reporter = User::factory()->create(['level' => UserLevel::Reporter]);

        $this->actingAs($reporter)
            ->get('/reporter-only')
            ->assertOk()
            ->assertSee('Reporter area');

        $honcho = User::factory()->create(['level' => UserLevel::Honcho]);

        $this->actingAs($honcho)
            ->get('/reporter-only')
            ->assertOk();
    }

    public function test_honcho_routes_reject_reporters(): void
    {
        Route::middleware('level:honcho')->get('/honcho-only', fn () => 'Honcho area');

        $reporter = User::factory()->create(['level' => UserLevel::Reporter]);

        $this->actingAs($reporter)->get('/honcho-only')->assertForbidden();
    }
}
