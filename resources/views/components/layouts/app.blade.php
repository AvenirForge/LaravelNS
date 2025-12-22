<!doctype html>
<html lang="{{ str_replace('_', '-', App::getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <meta name="description" content="NoteSync to nowoczesne narzędzie do synchronizacji notatek zapewniające bezpieczeństwo, szybkość i minimalistyczny design dla profesjonalistów." />
    <title>{{ __('messages.title') ?? 'NoteSync - Twoje notatki' }}</title>

    <style>
        /* INTER - Lokalna implementacja wag 400, 500, 600, 800 */
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
            font-weight: 500;
            font-display: swap;
            src: url('{{ asset('fonts/Inter-Medium.woff2') }}') format('woff2');
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

        /* PACIFICO - Lokalna implementacja */
        @font-face {
            font-family: 'Pacifico';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: url('{{ asset('fonts/Pacifico-Regular.woff2') }}') format('woff2');
        }

        :root {
            --bg: #050505;
            --bg2: #0A0A0A;
            --bg3: #111111;
            --card: #111111;
            --border: rgba(255, 255, 255, 0.08);
            --border-hover: rgba(255, 255, 255, 0.15);
            --fg: #EDEDED;
            --fg-muted: #888888;
            --primary: #3B82F6;
            --primary-glow: rgba(59, 130, 246, 0.4);
            --accent: #6366F1;
            --container: 1280px;
            --nav-height: 80px;
            --radius: 20px;
        }

        html[data-theme="light"] {
            --bg: #F2F4F7;
            --bg2: #FFFFFF;
            --bg3: #F9FAFB;
            --card: #FFFFFF;
            --border: rgba(0, 0, 0, 0.06);
            --border-hover: rgba(0, 0, 0, 0.1);
            --fg: #111827;
            --fg-muted: #64748B;
            --primary: #2563EB;
            --primary-glow: rgba(37, 99, 235, 0.15);
            --accent: #4F46E5;
        }

        @media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important } }

        * { box-sizing: border-box; outline-color: var(--primary); -webkit-tap-highlight-color: transparent; }

        html {
            scroll-behavior: smooth;
        }

        body {
            min-height: 100vh;
            margin: 0;
            overflow-x: hidden;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            -webkit-font-smoothing: antialiased;
            color: var(--fg);
            background: var(--bg);
            transition: color .3s ease, background-color .3s ease;
            line-height: 1.6;
        }

        a { color: inherit; text-decoration: none; transition: color 0.2s ease; }
        ul, h1, h2, h3, p { margin: 0; padding: 0; }
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
            transition: opacity 0.5s ease;
            background:
                radial-gradient(circle at 15% 50%, color-mix(in srgb, var(--primary) 8%, transparent), transparent 25%),
                radial-gradient(circle at 85% 30%, color-mix(in srgb, var(--accent) 8%, transparent), transparent 25%);
        }

        html[data-theme="light"] .page-bg-mesh {
            background:
                radial-gradient(circle at 15% 50%, color-mix(in srgb, var(--primary) 4%, transparent), transparent 30%),
                radial-gradient(circle at 85% 30%, color-mix(in srgb, var(--accent) 4%, transparent), transparent 30%);
        }

        [data-reveal] {
            opacity: 0;
            transform: translateY(24px);
            transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            will-change: opacity, transform;
        }

        [data-reveal].is-visible {
            opacity: 1;
            transform: translateY(0);
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
    {!! $slot !!}
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
