<?php

namespace App\Providers;

use App\Enums\UserLevel;
use App\Models\Health;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as ViewInstance;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layouts.app', function (ViewInstance $view): void {
            $view->with('latestHealth', null);

            $user = request()->user();

            if (! request()->routeIs('admin', 'admin.*') || $user?->level !== UserLevel::Honcho) {
                return;
            }

            $view->with('latestHealth', Health::query()
                ->orderByDesc('Date')
                ->orderByDesc('H_id')
                ->first(['Date', 'Message']));
        });
    }
}
