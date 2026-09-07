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
            background-color: #2878b8;
            background-image: url('/images/textures/banner-paper-blue.webp');
            background-position: center;
            background-size: cover;
        }

        .banner h1 {
            margin: 0;
            font-size: 1.5rem;
        }

        .menu-toggle,
        .menu-close,
        .menu-backdrop {
            display: none;
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
            background-color: #278547;
            background-image: url('/images/textures/sidebar-paper-green.webp');
            background-position: top left;
            background-size: 32rem auto;
        }

        .menu-heading {
            padding: 1rem;
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
            background-color: #f8f5ea;
            background-image: url('/images/textures/main-washi-paper.webp');
            background-position: center;
            background-repeat: no-repeat;
            background-size: cover;
        }

        .landing-view {
            display: grid;
            min-height: calc(100vh - 7.75rem);
            place-items: center;
        }

        @media (max-width: 767px) {
            .banner {
                position: sticky;
                z-index: 30;
                top: 0;
                justify-content: flex-start;
                padding: 1rem;
            }

            .banner h1 {
                flex: 1;
                font-size: 1.1rem;
            }

            .menu-toggle,
            .menu-close {
                display: inline-grid;
                width: 2.75rem;
                height: 2.75rem;
                padding: 0;
                border: 1px solid rgb(255 255 255 / 60%);
                border-radius: 0.25rem;
                color: #fff;
                background: transparent;
                font: inherit;
                font-size: 1.4rem;
                cursor: pointer;
                place-items: center;
            }

            .page {
                display: block;
                width: 100%;
                max-width: 100vw;
                overflow-x: hidden;
            }

            .menu-panel {
                position: fixed;
                z-index: 20;
                top: var(--mobile-menu-top, 4.75rem);
                bottom: 0;
                left: 0;
                width: min(82vw, 20rem);
                overflow-y: auto;
                box-shadow: 0 0 1.5rem rgb(0 0 0 / 35%);
                transform: translateX(-100%);
                transition: transform 180ms ease, visibility 180ms;
                visibility: hidden;
            }

            .menu-heading {
                padding-right: 4.5rem;
            }

            .menu-close {
                position: absolute;
                top: 0.45rem;
                right: 0.5rem;
            }

            .menu-backdrop {
                position: fixed;
                z-index: 10;
                top: var(--mobile-menu-top, 4.75rem);
                right: 0;
                bottom: 0;
                left: 0;
                width: 100%;
                padding: 0;
                border: 0;
                background: rgb(0 0 0 / 45%);
                cursor: pointer;
            }

            body.menu-open {
                overflow: hidden;
            }

            body.menu-open .menu-panel {
                transform: translateX(0);
                visibility: visible;
            }

            body.menu-open .menu-backdrop {
                display: block;
            }

            .main-view {
                width: 100%;
                max-width: 100vw;
                min-height: calc(100vh - var(--mobile-menu-top, 4.75rem));
                min-height: calc(100dvh - var(--mobile-menu-top, 4.75rem));
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
        <button
            type="button"
            class="menu-toggle"
            aria-label="Open menu"
            aria-controls="main-menu-panel"
            aria-expanded="false"
        >☰</button>
        <h1>Push Button 👉 Receive Paper</h1>
        @if (filled($latestHealth?->Message))
            <small class="health-message">
                {{ $latestHealth->Message }}<span class="health-date">{{ $latestHealth->Date?->format('H:i d/m/Y') }}</span>
            </small>
        @endif
    </header>

    <div class="page">
        <aside id="main-menu-panel" class="menu-panel" aria-label="Main menu">
            <div class="menu-heading"><strong>Menu</strong></div>
            <button type="button" class="menu-close" aria-label="Close menu">×</button>

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
        </aside>

        <main class="main-view">
            @yield('content')
        </main>
    </div>

    <button type="button" class="menu-backdrop" aria-label="Close menu" tabindex="-1"></button>

    @stack('scripts')
    <script>
        (() => {
            const body = document.body;
            const banner = document.querySelector('.banner');
            const menu = document.querySelector('#main-menu-panel');
            const openButton = document.querySelector('.menu-toggle');
            const closeButtons = document.querySelectorAll('.menu-close, .menu-backdrop');
            const mobileMenu = window.matchMedia('(max-width: 767px)');

            const updateMenuTop = () => {
                body.style.setProperty('--mobile-menu-top', `${banner.offsetHeight}px`);
            };

            updateMenuTop();
            window.addEventListener('resize', updateMenuTop);

            if ('ResizeObserver' in window) {
                new ResizeObserver(updateMenuTop).observe(banner);
            }

            const setMenuOpen = (isOpen, restoreFocus = false) => {
                body.classList.toggle('menu-open', isOpen);
                openButton.setAttribute('aria-expanded', String(isOpen));

                if (isOpen) {
                    menu.querySelector('a, button')?.focus();
                } else if (restoreFocus) {
                    openButton.focus();
                }
            };

            openButton.addEventListener('click', () => setMenuOpen(true));
            closeButtons.forEach((button) => {
                button.addEventListener('click', () => setMenuOpen(false, true));
            });

            menu.querySelectorAll('a').forEach((link) => {
                link.addEventListener('click', () => setMenuOpen(false));
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && body.classList.contains('menu-open')) {
                    setMenuOpen(false, true);
                }
            });

            mobileMenu.addEventListener('change', (event) => {
                if (!event.matches) {
                    setMenuOpen(false);
                }
            });
        })();
    </script>
</body>
</html>
