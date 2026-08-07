<?php

namespace App\Http\Middleware;

use App\Enums\UserLevel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasLevel
{
    /**
     * Allow public access for buttonpushers, or require one of the supplied user levels.
     *
     * @param  list<string>  $levels
     */
    public function handle(Request $request, Closure $next, string ...$levels): Response
    {
        if (in_array(UserLevel::Buttonpusher->value, $levels, true)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (
            $user->level !== UserLevel::Honcho
            && ! in_array($user->level->value, $levels, true)
        ) {
            abort(403);
        }

        return $next($request);
    }
}
