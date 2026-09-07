<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Push Button 👉 Receive Paper')</title>
    <style>
        :root {
            color-scheme: light;
            font-family: system-ui, sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
        }

        .banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem 2rem;
            color: #fff;
            background: #2878b8;
        }

        .banner h1 {
            margin: 0;
            font-size: 1.5rem;
        }

        .health-message {
            font-size: 0.7rem;
            text-align: right;
        }

        .health-date {
            display: block;
            margin-top: 0.15rem;
        }

        .page {
            display: grid;
            min-height: calc(100vh - 3.75rem);
            grid-template-columns: 20% 1fr;
        }

        .menu-panel {
            color: #fff;
            background: #278547;
        }

        .menu-panel summary {
            padding: 1rem;
            cursor: pointer;
            list-style: none;
        }

        .menu-panel summary::-webkit-details-marker {
            display: none;
        }

        .menu-panel summary::after {
            float: right;
            content: '−';
        }

        .menu-panel:not([open]) summary::after {
            content: '+';
        }

        .menu {
            display: grid;
            gap: 0.75rem;
            padding: 0 1rem 1rem;
        }

        .menu a,
        .menu button {
            width: 100%;
            padding: 0.65rem;
            border: 0;
            color: #fff;
            background: transparent;
            font: inherit;
            font-style: italic;
            font-weight: 700;
            text-align: left;
            text-decoration: none;
            cursor: pointer;
        }

        .menu a:hover,
        .menu button:hover {
            background: rgb(255 255 255 / 15%);
        }

        .menu form {
            margin: 0;
        }

        .inline-form {
            display: inline;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .main-view {
            min-width: 0;
            padding: 2rem;
        }

        .landing-view {
            display: grid;
            min-height: calc(100vh - 7.75rem);
            place-items: center;
        }

        @media (max-width: 767px) {
            .banner {
                padding: 1rem;
            }

            .page {
                display: block;
            }

            .menu-panel {
                width: 100%;
            }

            .main-view {
                padding: 1rem;
            }

            .landing-view {
                min-height: calc(100vh - 9rem);
            }
        }
    </style>
    @stack('styles')
</head>
<body>
    <header class="banner">
        <h1>Push Button 👉 Receive Paper</h1>
        @if (filled($latestHealth?->Message))
            <small class="health-message">
                {{ $latestHealth->Message }}<span class="health-date">{{ $latestHealth->Date?->format('H:i d/m/Y') }}</span>
            </small>
        @endif
    </header>

    <div class="page">
        <details class="menu-panel" open>
            <summary><strong>Menu</strong></summary>

            <nav class="menu" aria-label="Main navigation">
                @auth
                    @if (auth()->user()->level === \App\Enums\UserLevel::Honcho)
                        <a href="{{ route('admin.articles') }}">Articles</a>
                        <a href="{{ route('admin.random-texts') }}">Random texts</a>
                    @endif

                    @if (auth()->user()->level === \App\Enums\UserLevel::Reporter)
                        <a href="{{ route('reporter.suggest-story') }}">Suggest a story</a>
                    @endif

                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit">Sign out</button>
                    </form>
                @else
                    <a href="{{ route('login') }}">Login</a>
                @endauth
            </nav>
        </details>

        <main class="main-view">
            @yield('content')
        </main>
    </div>

    @stack('scripts')
</body>
</html>
