<?php

namespace Tests\Feature\Api;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HealthMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('Health', function (Blueprint $table) {
            $table->id('H_id');
            $table->dateTime('Date')->nullable();
            $table->text('Message')->nullable();
            $table->timestamps();
        });
    }

    public function test_json_message_is_sanitized_and_saved_to_health(): void
    {
        $response = $this->postJson('/api/ping', [
            'Message' => '  <strong>All systems nominal.</strong>  ',
        ]);

        $response->assertCreated()
            ->assertJsonPath('Message', 'Thanks.');

        $this->assertDatabaseHas('Health', [
            'Message' => 'All systems nominal.',
        ]);
    }

    public function test_health_message_cannot_exceed_512_characters(): void
    {
        $this->postJson('/api/ping', [
            'Message' => str_repeat('a', 512),
        ])->assertCreated();

        $this->postJson('/api/ping', [
            'Message' => str_repeat('a', 513),
        ])->assertUnprocessable();

        $this->assertDatabaseCount('Health', 1);
    }

    public function test_health_message_requires_exactly_one_json_message_field(): void
    {
        $this->postJson('/api/ping', [
            'Message' => 'Okay!',
            'extra' => 'not allowed',
        ])->assertUnprocessable();

        $this->post('/api/ping', ['Message' => 'Okay!'])
            ->assertStatus(415);
    }
}
