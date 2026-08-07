<?php

use App\Models\Health;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ContentController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    Health::query()->create([
        'Message' => 'Okay!',
        'Date' => now(),
    ]);

    return response('PONG');
});

Route::post('/ping', [HealthController::class, 'store']);
Route::get('/content', [ContentController::class, 'index']);
