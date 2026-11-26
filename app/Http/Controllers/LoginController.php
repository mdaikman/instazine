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
        $reporterPassword = (string) config('instazine.reporter_password');

        if ($user
            && $user->level === UserLevel::Honcho
            && Hash::check($credentials['password'], $user->password)) {
            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->route('admin.articles');
        }

        if ($reporterPassword === '' || ! hash_equals($reporterPassword, $credentials['password'])) {
            return back()
                ->withErrors(['name' => 'The provided credentials do not match our records.'])
                ->onlyInput('name');
        }

        if ($user && $user->level !== UserLevel::Reporter) {
            return back()
                ->withErrors(['name' => 'That name is reserved for another account.'])
                ->onlyInput('name');
        }

        if ($user) {
            if (! Hash::check($reporterPassword, $user->password)) {
                $user->update(['password' => Hash::make($reporterPassword)]);
            }
        } else {
            $user = User::query()->create([
                'name' => $credentials['name'],
                'email' => $credentials['name'].'@reporter.instazine.local',
                'level' => UserLevel::Reporter,
                'password' => Hash::make($reporterPassword),
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('reporter.suggest-story');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
