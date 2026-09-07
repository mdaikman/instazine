<?php

namespace Tests\Unit;

use App\Services\DitheredImageConverter;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DitheredImageConverterTest extends TestCase
{
    public function test_it_caps_height_and_reduces_width_to_preserve_the_aspect_ratio(): void
    {
        config()->set('instazine.printer_pixel_width', 400);
        config()->set('instazine.image_height_max', 300);

        $contents = app(DitheredImageConverter::class)
            ->convert(UploadedFile::fake()->image('portrait.png', 100, 200));
        $image = imagecreatefromstring($contents);

        $this->assertSame(150, imagesx($image));
        $this->assertSame(300, imagesy($image));

        imagedestroy($image);
    }

    public function test_it_uses_the_printer_width_when_the_scaled_height_is_below_the_cap(): void
    {
        config()->set('instazine.printer_pixel_width', 400);
        config()->set('instazine.image_height_max', 300);

        $contents = app(DitheredImageConverter::class)
            ->convert(UploadedFile::fake()->image('landscape.png', 200, 100));
        $image = imagecreatefromstring($contents);

        $this->assertSame(400, imagesx($image));
        $this->assertSame(200, imagesy($image));

        imagedestroy($image);
    }
}
