<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    private const BOARD_TOKEN = 'test-board-token';

    private const POSTMAN_TOKEN = 'test-postman-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('instazine.api_token_hashes', [
            hash('sha256', self::BOARD_TOKEN),
            hash('sha256', self::POSTMAN_TOKEN),
        ]);
    }

    public function test_api_rejects_missing_and_invalid_tokens(): void
    {
        $this->getJson('/api/pic?url=missing.bmp')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->withToken('wrong-token')
            ->getJson('/api/pic?url=missing.bmp')
            ->assertUnauthorized();
    }

    public function test_api_accepts_board_and_postman_tokens(): void
    {
        foreach ([self::BOARD_TOKEN, self::POSTMAN_TOKEN] as $token) {
            $this->withToken($token)
                ->getJson('/api/pic?url=missing.bmp')
                ->assertOk()
                ->assertContent('null');
        }
    }

    public function test_api_is_closed_when_no_token_hashes_are_configured(): void
    {
        config()->set('instazine.api_token_hashes', []);

        $this->withToken(self::BOARD_TOKEN)
            ->getJson('/api/pic?url=missing.bmp')
            ->assertUnauthorized();
    }
}
