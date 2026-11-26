<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    protected const API_TOKEN = 'test-api-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('instazine.api_token_hashes', [hash('sha256', self::API_TOKEN)]);
        $this->withToken(self::API_TOKEN);
    }
}
