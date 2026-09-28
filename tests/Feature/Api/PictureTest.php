<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class PictureTest extends ApiTestCase
{
    use RefreshDatabase;

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
        $this->approvedArticleWithPicture('article-pics/example.bmp');

        $response = $this->getJson('/api/pic?url=article-pics/example.bmp')
            ->assertOk()
            ->assertExactJson([
                'height' => 2,
                'width' => 10,
                'pixels' => [160, 0, 1, 128],
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
        config()->set('instazine.banner', 'storage/app/private/banners/example.bmp');

        $this->getJson('/api/pic?url=storage%2Fapp%2Fprivate%2Fbanners%2Fexample.bmp')
            ->assertOk()
            ->assertExactJson([
                'height' => 1,
                'width' => 1,
                'pixels' => [128],
            ]);
    }

    public function test_it_returns_json_null_when_the_file_is_not_a_valid_bmp(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('article-pics/not-a-bitmap.bmp', 'not a bitmap');
        $this->approvedArticleWithPicture('article-pics/not-a-bitmap.bmp');

        $this->getJson('/api/pic?url=article-pics/not-a-bitmap.bmp')
            ->assertOk()
            ->assertContent('null')
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_it_only_serves_pictures_of_approved_articles(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('article-pics/pending.bmp', $this->bitmap());
        $article = Article::query()->create([
            'Approved' => false,
            'Pic' => 'article-pics/pending.bmp',
            'Author' => 1,
            'Date' => now(),
        ]);

        $this->getJson('/api/pic?url=article-pics/pending.bmp')->assertContent('null');

        $article->update(['Approved' => true]);

        $this->getJson('/api/pic?url=article-pics/pending.bmp')->assertJsonPath('width', 8);
    }

    public function test_it_serves_the_configured_banner_and_dividers_but_not_other_files(): void
    {
        Storage::fake('local');
        config()->set('instazine.banner', 'banners/header.bmp');
        config()->set('instazine.dividers', ['storage/app/private/banners/divider.bmp', null]);

        foreach (['banners/header.bmp', 'banners/divider.bmp', 'banners/other.bmp'] as $path) {
            Storage::disk('local')->put($path, $this->bitmap());
        }

        $this->getJson('/api/pic?url=banners/header.bmp')->assertJsonPath('height', 1);
        $this->getJson('/api/pic?url=banners/divider.bmp')->assertJsonPath('height', 1);
        $this->getJson('/api/pic?url=storage/app/private/banners/divider.bmp')->assertJsonPath('height', 1);
        $this->getJson('/api/pic?url=banners/other.bmp')->assertContent('null');
    }

    private function approvedArticleWithPicture(string $path): void
    {
        Article::query()->create([
            'Approved' => true,
            'Pic' => $path,
            'Author' => 1,
            'Date' => now(),
        ]);
    }

    private function bitmap(): string
    {
        $image = imagecreatetruecolor(8, 1);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagetruecolortopalette($image, false, 2);

        ob_start();
        imagebmp($image, null, true);

        return ob_get_clean();
    }
}
