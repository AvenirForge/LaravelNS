<!doctype html>
<html lang="pl" data-theme="dark">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>NoteSync - Twoje notatki</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;800&family=Pacifico&display=swap" rel="stylesheet" />

    <style>
        :root {
            --bg: #050505;
            --bg2: #0A0A0A;
            --bg3: #111111;
            --card: #111111;
            --border: rgba(255, 255, 255, 0.08);
            --fg: #EDEDED;
            --fg-muted: #888888;
            --primary: #3B82F6;
            --primary-glow: rgba(59, 130, 246, 0.4);
            --accent: #6366F1;
            --text-on-primary: #FFFFFF;
            --container: 1280px;
            --nav-height: 80px;
            --radius: 20px;
        }

        html[data-theme="light"] {
            --bg: #FAFAFA;
            --bg2: #FFFFFF;
            --bg3: #F0F0F0;
            --card: #FFFFFF;
            --border: rgba(0, 0, 0, 0.08);
            --fg: #171717;
            --fg-muted: #666666;
            --primary: #2563EB;
            --primary-glow: rgba(37, 99, 235, 0.2);
            --accent: #4F46E5;
        }

        @media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important } }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html, body { height: 100%; overflow-x: hidden; scroll-behavior: smooth; }
        body {
            margin: 0; font-family: 'Inter', system-ui, -apple-system, sans-serif; -webkit-font-smoothing: antialiased;
            color: var(--fg); background: var(--bg); transition: color .3s ease, background-color .3s ease;
            line-height: 1.6;
        }
        a { color: inherit; text-decoration: none; }
        img { display: block; max-width: 100%; height: auto; }
        .container { max-width: var(--container); margin: 0 auto; padding: 0 24px; }

        .page-wrapper { position: relative; isolation: isolate; }

        .page-bg-mesh {
            position: fixed; inset: 0; z-index: -1; pointer-events: none;
            background:
                radial-gradient(circle at 15% 50%, color-mix(in srgb, var(--primary) 5%, transparent), transparent 25%),
                radial-gradient(circle at 85% 30%, color-mix(in srgb, var(--accent) 5%, transparent), transparent 25%);
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

<div class="page-wrapper">
    <div class="page-bg-mesh"></div>
    {{ $slot }}
</div>

<script>
    (function () {
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });

        document.querySelectorAll('[data-reveal]').forEach(el => observer.observe(el));
    })();
</script>
</body>
</html>
