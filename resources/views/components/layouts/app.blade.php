<!doctype html>
<html lang="{{ str_replace('_', '-', App::getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preload" href="{{ asset('fonts/Inter-ExtraBold.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/Inter-SemiBold.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/Inter-Regular.woff2') }}" as="font" type="font/woff2" crossorigin>

    <meta name="description" content="NoteSync to nowoczesne narzędzie do synchronizacji notatek zapewniające bezpieczeństwo, szybkość i minimalistyczny design dla profesjonalistów.">
    <title>{{ __('messages.title') ?? 'NoteSync - Twoje notatki' }}</title>

    <style>
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: url('{{ asset('fonts/Inter-Regular.woff2') }}') format('woff2');
        }
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 600;
            font-display: swap;
            src: url('{{ asset('fonts/Inter-SemiBold.woff2') }}') format('woff2');
        }
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 800;
            font-display: swap;
            src: url('{{ asset('fonts/Inter-ExtraBold.woff2') }}') format('woff2');
        }

        :root {
            --bg: #050505;
            --fg: #EDEDED;
            --primary: #3B82F6;
            --accent: #6366F1;
            --container: 1280px;
        }

        html[data-theme="light"] {
            --bg: #F2F4F7;
            --fg: #111827;
            --primary: #2563EB;
            --accent: #4F46E5;
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        html { scroll-behavior: smooth; }

        body {
            min-height: 100vh;
            margin: 0;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            -webkit-font-smoothing: antialiased;
            color: var(--fg);
            background: var(--bg);
            transition: background-color .3s ease, color .3s ease;
            line-height: 1.6;
            text-rendering: optimizeLegibility;
        }

        a { color: inherit; text-decoration: none; }
        img { display: block; max-width: 100%; height: auto; }

        .container {
            max-width: var(--container);
            margin: 0 auto;
            padding: 0 24px;
        }

        .page-bg-mesh {
            position: fixed;
            inset: 0;
            z-index: -1;
            pointer-events: none;
            background:
                radial-gradient(circle at 15% 50%, color-mix(in srgb, var(--primary) 8%, transparent), transparent 25%),
                radial-gradient(circle at 85% 30%, color-mix(in srgb, var(--accent) 8%, transparent), transparent 25%);
            will-change: opacity;
        }

        html[data-theme="light"] .page-bg-mesh {
            background:
                radial-gradient(circle at 15% 50%, color-mix(in srgb, var(--primary) 4%, transparent), transparent 30%),
                radial-gradient(circle at 85% 30%, color-mix(in srgb, var(--accent) 4%, transparent), transparent 30%);
        }

        [data-reveal] {
            opacity: 0;
            transform: translate3d(0, 24px, 0);
            transition: opacity 0.6s cubic-bezier(0.16, 1, 0.3, 1), transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            will-change: opacity, transform;
        }

        [data-reveal].is-visible {
            opacity: 1;
            transform: translate3d(0, 0, 0);
        }
    </style>

    <script>
        (function () {
            try {
                const theme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', theme);
            } catch (e) {}
        })();
    </script>
</head>
<body id="top">

<div class="page-wrapper" style="position: relative; isolation: isolate;">
    <div class="page-bg-mesh"></div>
    {{ $slot }}
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });

        document.querySelectorAll('[data-reveal]').forEach(el => observer.observe(el));
    });
</script>
</body>
</html>
