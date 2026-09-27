<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (is_string($token) && $token !== '') {
            $providedHash = hash('sha256', $token);

            foreach ((array) config('instazine.api_token_hashes', []) as $allowedHash) {
                if (is_string($allowedHash)
                    && preg_match('/^[a-f0-9]{64}$/i', $allowedHash) === 1
                    && hash_equals(strtolower($allowedHash), $providedHash)) {
                    return $next($request);
                }
            }
        }

        return new JsonResponse(['message' => 'Unauthenticated.'], 401);
    }
}
