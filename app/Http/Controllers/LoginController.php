<?php

namespace App\Http\Controllers;

use App\Enums\UserLevel;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'name' => ['required', 'string', 'max:230', 'alpha_dash:ascii'],
            'password' => ['required'],
        ]);

        $user = User::query()->where('name', $credentials['name'])->first();

        if ($user && Hash::check($credentials['password'], $user->password)) {
            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->intended(
                route($user->level === UserLevel::Honcho ? 'admin.articles' : 'reporter.suggest-story'),
            );
        }

        if ($credentials['password'] !== 'instazine') {
            return back()
                ->withErrors(['name' => 'The provided credentials do not match our records.'])
                ->onlyInput('name');
        }

        if ($user && $user->level !== UserLevel::Reporter) {
            return back()
                ->withErrors(['name' => 'That name is reserved for another account.'])
                ->onlyInput('name');
        }

        $user ??= User::query()->create([
            'name' => $credentials['name'],
            'email' => $credentials['name'].'@reporter.instazine.local',
            'level' => UserLevel::Reporter,
            'password' => Hash::make('instazine'),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('reporter.suggest-story'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
