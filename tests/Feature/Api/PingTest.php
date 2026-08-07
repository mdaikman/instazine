<?php

namespace Tests\Feature\Api;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PingTest extends TestCase
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

    public function test_ping_returns_pong(): void
    {
        $this->get('/api/ping')
            ->assertOk()
            ->assertContent('PONG');

        $this->assertDatabaseHas('Health', ['Message' => 'Okay!']);
    }
}
