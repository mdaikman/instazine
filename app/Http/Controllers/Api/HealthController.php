<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Health;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class HealthController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->isJson(), 415, 'The request body must be JSON.');

        $payload = $request->json()->all();

        if (count($payload) !== 1 || ! array_key_exists('Message', $payload)) {
            throw ValidationException::withMessages([
                'Message' => 'The JSON body must contain only a Message field.',
            ]);
        }

        $validated = Validator::make($payload, [
            'Message' => ['required', 'string'],
        ])->validate();

        $message = trim(strip_tags($validated['Message']));

        if ($message === '') {
            throw ValidationException::withMessages([
                'Message' => 'The Message field must contain text after sanitization.',
            ]);
        }

        $health = Health::query()->create([
            'Message' => $message,
            'Date' => now(),
        ]);

        return response()->json([
            'Message' => 'Thanks.',
        ], 201);
    }
}
