<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PictureController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $path = $request->query('url');

        if (! $this->isSafeRelativeBmpPath($path) || ! Storage::disk('local')->exists($path)) {
            return $this->json(null);
        }

        $contents = Storage::disk('local')->get($path);
        $details = is_string($contents) ? @getimagesizefromstring($contents) : false;
        $image = is_string($contents) ? @imagecreatefromstring($contents) : false;

        if ($details === false || ($details[2] ?? null) !== IMAGETYPE_BMP || $image === false) {
            if ($image instanceof \GdImage) {
                imagedestroy($image);
            }

            return $this->json(null);
        }

        try {
            $width = imagesx($image);
            $height = imagesy($image);

            return $this->json([
                'height' => $height,
                'width' => $width,
                'pixels' => $this->packPixels($image, $width, $height),
            ]);
        } finally {
            imagedestroy($image);
        }
    }

    private function isSafeRelativeBmpPath(mixed $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }

        if (str_starts_with($path, '/') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1) {
            return false;
        }

        $segments = explode('/', $path);

        return ! in_array('', $segments, true)
            && ! in_array('.', $segments, true)
            && ! in_array('..', $segments, true)
            && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'bmp';
    }

    /**
     * Pack rows MSB-first, padding the end of each row with zero bits.
     * Black pixels are 1 and white pixels are 0.
     *
     * @return list<int>
     */
    private function packPixels(\GdImage $image, int $width, int $height): array
    {
        $bytes = [];

        for ($y = 0; $y < $height; $y++) {
            for ($byteX = 0; $byteX < (int) ceil($width / 8); $byteX++) {
                $byte = 0;

                for ($bit = 0; $bit < 8; $bit++) {
                    $x = $byteX * 8 + $bit;

                    if ($x >= $width) {
                        break;
                    }

                    $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                    $luminance = 0.299 * $color['red'] + 0.587 * $color['green'] + 0.114 * $color['blue'];

                    if ($luminance < 128) {
                        $byte |= 1 << (7 - $bit);
                    }
                }

                $bytes[] = $byte;
            }
        }

        return $bytes;
    }

    private function json(mixed $data): JsonResponse
    {
        $response = $data === null
            ? JsonResponse::fromJsonString('null')
            : response()->json($data);
        $response->headers->set('Content-Length', (string) strlen($response->getContent()));

        return $response;
    }
}
