<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AssetController extends Controller
{
    public function index(): JsonResponse
    {
        $images = array_values(array_unique(array_filter([
            config('instazine.banner'),
            ...(array) config('instazine.dividers', []),
        ], static fn (mixed $path): bool => is_string($path) && $path !== '')));

        $response = response()->json(['images' => $images]);
        $response->headers->set('Content-Length', (string) strlen($response->getContent()));

        return $response;
    }
}
