@php
    use Illuminate\Support\Facades\App;
    $locale = request()->query('lang', 'pl');
    if (!in_array($locale, ['pl', 'en'])) {
        $locale = 'pl';
    }
    App::setLocale($locale);
@endphp
    <!doctype html>
<html lang="{{ str_replace('_', '-', App::getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <meta name="description" content="NoteSync - Synchronizacja, bezpieczeństwo i minimalizm. Twoje notatki zawsze pod ręką." />
    <title>{{ __('messages.title') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;800&display=swap" rel="stylesheet"/>
    <style>
        :root {
            --bg: #050505;
            --bg2: #0A0A0A;
            --card: #111111;
            --border: rgba(255, 255, 255, 0.08);
            --border-hover: rgba(255, 255, 255, 0.15);
            --fg: #EDEDED;
            --fg-muted: #888888;
            --primary: #3B82F6;
            --primary-glow: rgba(59, 130, 246, 0.4);
            --accent: #6366F1;
            --nav-height: 72px;
            --radius: 20px;
            --container: 1280px;
        }

        html[data-theme="light"] {
            --bg: #FAFAFA;
            --bg2: #FFFFFF;
            --card: #FFFFFF;
            --border: rgba(0, 0, 0, 0.08);
            --border-hover: rgba(0, 0, 0, 0.15);
            --fg: #171717;
            --fg-muted: #666666;
            --primary: #2563EB;
            --primary-glow: rgba(37, 99, 235, 0.25);
            --accent: #4F46E5;
        }

        * { box-sizing: border-box; outline-color: var(--primary); }
        html { scroll-behavior: smooth; -webkit-font-smoothing: antialiased; }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--fg);
            line-height: 1.6;
            overflow-x: hidden;
            transition: background 0.3s ease, color 0.3s ease;
        }

        a { text-decoration: none; color: inherit; transition: color 0.2s; }
        ul, h1, h2, h3, p { margin: 0; padding: 0; }
        img { max-width: 100%; display: block; }

        .container {
            max-width: var(--container);
            margin: 0 auto;
            padding: 0 24px;
        }

        .bg-gradient {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100vh;
            background:
                radial-gradient(circle at 15% 50%, color-mix(in srgb, var(--primary) 8%, transparent), transparent 25%),
                radial-gradient(circle at 85% 30%, color-mix(in srgb, var(--accent) 8%, transparent), transparent 25%);
            z-index: -1;
            pointer-events: none;
        }

        [data-reveal] {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }
        [data-reveal].visible { opacity: 1; transform: translateY(0); }
    </style>
    <script>
        (function() {
            try {
                const local = localStorage.getItem('theme');
                const support = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', local || support);
            } catch (e) {}
        })();
    </script>
</head>
<body id="top">
<div class="bg-gradient"></div>
{{ $slot }}

<script>
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if(entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });
    document.querySelectorAll('[data-reveal]').forEach(el => observer.observe(el));
</script>
</body>
</html>
