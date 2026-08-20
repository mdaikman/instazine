<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class DitheredImageConverter
{
    /**
     * Convert an uploaded image to a printer-width, dithered bitmap.
     */
    public function convert(UploadedFile $picture): string
    {
        $source = $this->createImage($picture);
        $printerWidth = max(1, (int) config('instazine.printer_pixel_width'));
        $maximumHeight = max(1, (int) config('instazine.image_height_max'));

        try {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $width = $printerWidth;
            $height = max(1, (int) round($sourceHeight * $width / $sourceWidth));

            if ($height > $maximumHeight) {
                $height = $maximumHeight;
                $width = max(1, (int) round($sourceWidth * $height / $sourceHeight));
            }

            $converted = imagecreatetruecolor($width, $height);
            $white = imagecolorallocate($converted, 255, 255, 255);
            imagefill($converted, 0, 0, $white);
            imagecopyresampled(
                $converted,
                $source,
                0,
                0,
                0,
                0,
                $width,
                $height,
                $sourceWidth,
                $sourceHeight,
            );

            $this->ditherToOneBit($converted, $width, $height);
            imagetruecolortopalette($converted, false, 2);

            ob_start();
            imagebmp($converted, null, true);
            $contents = ob_get_clean();
            imagedestroy($converted);

            return $contents;
        } finally {
            imagedestroy($source);
        }
    }

    private function createImage(UploadedFile $picture): \GdImage
    {
        $source = match ($picture->getMimeType()) {
            'image/jpeg' => imagecreatefromjpeg($picture->getRealPath()),
            'image/png' => imagecreatefrompng($picture->getRealPath()),
            'image/gif' => imagecreatefromgif($picture->getRealPath()),
            'image/webp' => imagecreatefromwebp($picture->getRealPath()),
            'image/avif' => imagecreatefromavif($picture->getRealPath()),
            default => false,
        };

        if ($source === false) {
            throw ValidationException::withMessages([
                'pic' => 'The uploaded image could not be processed.',
            ]);
        }

        return $source;
    }

    private function ditherToOneBit(\GdImage $image, int $width, int $height): void
    {
        $currentErrors = array_fill(0, $width + 2, 0.0);

        for ($y = 0; $y < $height; $y++) {
            $nextErrors = array_fill(0, $width + 2, 0.0);

            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                $red = ($color >> 16) & 0xff;
                $green = ($color >> 8) & 0xff;
                $blue = $color & 0xff;
                $luminance = max(0, min(255, 0.299 * $red + 0.587 * $green + 0.114 * $blue + $currentErrors[$x + 1]));
                $output = $luminance < 128 ? 0 : 255;
                $error = $luminance - $output;

                imagesetpixel($image, $x, $y, ($output << 16) | ($output << 8) | $output);

                $currentErrors[$x + 2] += $error * 7 / 16;
                $nextErrors[$x] += $error * 3 / 16;
                $nextErrors[$x + 1] += $error * 5 / 16;
                $nextErrors[$x + 2] += $error / 16;
            }

            $currentErrors = $nextErrors;
        }
    }
}
