<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Storage;

class PictureTest extends ApiTestCase
{
    public function test_it_returns_bmp_dimensions_and_packed_one_bit_pixels(): void
    {
        Storage::fake('local');
        $image = imagecreatetruecolor(10, 2);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);
        imagesetpixel($image, 0, 0, $black);
        imagesetpixel($image, 2, 0, $black);
        imagesetpixel($image, 7, 1, $black);
        imagesetpixel($image, 8, 1, $black);
        imagetruecolortopalette($image, false, 2);

        ob_start();
        imagebmp($image, null, true);
        $contents = ob_get_clean();
        imagedestroy($image);
        Storage::disk('local')->put('article-pics/example.bmp', $contents);

        $response = $this->getJson('/api/pic?url=article-pics/example.bmp')
            ->assertOk()
            ->assertExactJson([
                'height' => 2,
                'width' => 10,
                'pixels' => 'oAABgA==',
            ]);

        $this->assertSame(
            strlen($response->getContent()),
            (int) $response->headers->get('Content-Length'),
        );
    }

    public function test_it_returns_json_null_for_missing_or_unsafe_paths(): void
    {
        Storage::fake('local');

        foreach ([
            '/api/pic',
            '/api/pic?url=missing.bmp',
            '/api/pic?url=/absolute.bmp',
            '/api/pic?url=../outside.bmp',
            '/api/pic?url=https%3A%2F%2Fexample.com%2Fimage.bmp',
        ] as $url) {
            $this->getJson($url)
                ->assertOk()
                ->assertContent('null')
                ->assertHeader('Content-Type', 'application/json');
        }
    }

    public function test_it_accepts_the_legacy_private_storage_prefix(): void
    {
        Storage::fake('local');
        $image = imagecreatetruecolor(1, 1);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 0));

        ob_start();
        imagebmp($image, null, true);
        $contents = ob_get_clean();
        imagedestroy($image);
        Storage::disk('local')->put('banners/example.bmp', $contents);

        $this->getJson('/api/pic?url=storage%2Fapp%2Fprivate%2Fbanners%2Fexample.bmp')
            ->assertOk()
            ->assertExactJson([
                'height' => 1,
                'width' => 1,
                'pixels' => 'gA==',
            ]);
    }

    public function test_it_returns_json_null_when_the_file_is_not_a_valid_bmp(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('article-pics/not-a-bitmap.bmp', 'not a bitmap');

        $this->getJson('/api/pic?url=article-pics/not-a-bitmap.bmp')
            ->assertOk()
            ->assertContent('null')
            ->assertHeader('Content-Type', 'application/json');
    }
}
