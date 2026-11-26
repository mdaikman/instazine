<?php

namespace Tests\Feature\Api;

class AssetTest extends ApiTestCase
{
    public function test_it_returns_unique_configured_banner_and_divider_images(): void
    {
        config()->set('instazine.banner', 'banners/header.bmp');
        config()->set('instazine.dividers', [
            'banners/a.bmp',
            '',
            null,
            'banners/a.bmp',
            'banners/b.bmp',
        ]);

        $response = $this->getJson('/api/assets')
            ->assertOk()
            ->assertExactJson([
                'images' => [
                    'banners/header.bmp',
                    'banners/a.bmp',
                    'banners/b.bmp',
                ],
            ]);

        $this->assertSame(
            strlen($response->getContent()),
            (int) $response->headers->get('Content-Length'),
        );
    }
}
