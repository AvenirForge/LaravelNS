<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>@yield('title', 'NoteSync — Twoje notatki zawsze pod ręką')</title>
    <meta name="description" content="@yield('meta_description', 'NoteSync to szybka i bezpieczna aplikacja do tworzenia, organizacji i synchronizacji notatek.')" />

    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('favicon.jpg') }}">

    <link rel="icon" type="image/jpeg" sizes="32x32" href="{{ asset('favicon.jpg') }}">
    <link rel="icon" type="image/jpeg" sizes="16x16" href="{{ asset('favicon.jpg') }}">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Pacifico&family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet" />

    <!-- Open Graph -->
    <meta property="og:title" content="@yield('og:title', 'NoteSync — Twoje notatki zawsze pod ręką')" />
    <meta property="og:description" content="@yield('og:description', 'Prosta, intuicyjna i zawsze dostępna aplikacja do notatek.')" />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="{{ config('app.url', 'https://notesync.pl') }}" />
    <meta property="og:image" content="{{ asset('assets/images/app_light_1.png') }}" />

    <style>
        :root{
            --bg:#0b1020; --bg2:#0e1430; --fg:#eaf0ff; --muted:#98a2b3;
            --card:rgba(255,255,255,.06); --border:rgba(255,255,255,.10);
            --accent:#60a5fa; --accent2:#1794e0;
            --ctaA:#c1f4ed; --ctaB:#1794e0;
            --shadow-lg:0 20px 60px rgba(0,0,0,.35);
            --shadow:0 10px 40px rgba(0,0,0,.28);
            --radius-lg:22px; --radius:14px; --container:1160px; --nav-h:66px;
            --focus:0 0 0 3px rgba(23,148,224,.35);
            scroll-behavior:smooth;
        }
        @media (prefers-color-scheme: light){
            :root{
                --bg:#f5f7fb; --bg2:#e9eef6; --fg:#1b2330; --muted:#475569;
                --card:#fff; --border:rgba(0,0,0,.08);
                --accent:#0f689c; --accent2:#1794e0; --ctaA:#c1f4ed; --ctaB:#1794e0;
                --shadow-lg:0 18px 48px rgba(2,6,23,.12); --shadow:0 10px 30px rgba(2,6,23,.10);
            }
        }
        @media (prefers-reduced-motion: reduce){ *{animation:none!important;transition:none!important;scroll-behavior:auto} }

        *{box-sizing:border-box}
        html,body{height:100%}
        body{
            margin:0; font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial;
            color:var(--fg);
            background:
                radial-gradient(60% 40% at 85% -10%, rgba(96,165,250,.20), transparent 60%),
                radial-gradient(40% 35% at -5% -10%, rgba(23,148,224,.18), transparent 60%),
                linear-gradient(180deg, var(--bg) 0%, var(--bg2) 100%);
        }
        a{color:inherit; text-decoration:none}
        img{display:block; max-width:100%; height:auto}
        .container{max-width:var(--container); margin:0 auto; padding:0 20px}
        .card{background:var(--card); border:1px solid var(--border); border-radius:var(--radius-lg); box-shadow:var(--shadow)}
        .btn{appearance:none;border:none;font-weight:700;padding:12px 16px;border-radius:var(--radius);cursor:pointer;transition:transform .2s,box-shadow .2s;outline:0}
        .btn:focus-visible{box-shadow:var(--focus)}
        .btn-primary{background:linear-gradient(90deg,var(--ctaA),var(--ctaB));color:#0b1020;box-shadow:var(--shadow)}
        .btn-primary:hover{transform:translateY(-2px)}
        .btn-ghost{background:transparent;color:var(--fg);outline:1px solid var(--border)}
        .kicker{color:var(--muted);font-weight:700;letter-spacing:.12em;text-transform:uppercase}
        h1{font-size:clamp(34px,5.2vw,56px);line-height:1.05;margin:10px 0 12px}
        h2{font-size:clamp(24px,3.6vw,34px);margin:0 0 14px}
        .lead{font-size:clamp(16px,2.4vw,20px);color:var(--fg);opacity:.92}

        /* NAVBAR */
        .navbar{position:sticky;top:0;z-index:1000;height:var(--nav-h);display:flex;align-items:center;backdrop-filter:saturate(140%) blur(10px);background:linear-gradient(180deg,rgba(0,0,0,.30),rgba(0,0,0,.06));border-bottom:1px solid var(--border)}
        .nav-inner{display:flex;align-items:center;justify-content:space-between}
        .brand{display:flex;align-items:center;gap:10px}
        .brand img{width:34px;height:34px}
        .brand span{font-family:Pacifico,cursive;font-size:24px}
        .nav-cta{display:flex;gap:10px;align-items:center}
        .nav-link{padding:10px 12px;border-radius:10px}
        .nav-link:hover{background:rgba(255,255,255,.06)}

        /* FOOTER */
        footer{border-top:1px solid var(--border);color:var(--muted);text-align:center;padding:28px 18px 40px;position:relative}
        .to-top{position:absolute;right:18px;top:18px;width:42px;height:42px;display:inline-grid;place-items:center;border-radius:999px;background:linear-gradient(90deg,var(--ctaA),var(--ctaB));color:#0b1020;box-shadow:var(--shadow);font-weight:900}
        .to-top:hover{transform:translateY(-2px)}
        .foot-strong{font-weight:700;color:var(--fg)}

        @media (max-width:980px){
            .nav-inner{gap:10px}
            .nav-cta{display:none}
        }
    </style>

    @yield('head_extra')
</head>
<body id="top">
<!-- NAVBAR -->
<nav class="navbar" role="navigation" aria-label="Główna nawigacja">
    <div class="container nav-inner">
        <a class="brand" href="#top" aria-label="NoteSync — początek strony">
            <img src="{{ asset('assets/images/logo-notesync.svg') }}" alt="Logo NoteSync" width="34" height="34" />
            <span>NoteSync</span>
        </a>
        <div class="nav-cta" aria-label="Nawigacja sekcji">
            <a class="nav-link" href="#features">Funkcje</a>
            <a class="nav-link" href="#screens">Zrzuty</a>
            <a class="nav-link" href="#download">Pobierz</a>
        </div>
    </div>
</nav>

@yield('content')

<!-- FOOTER -->
<footer role="contentinfo">
    <a class="to-top" href="#top" aria-label="Powrót na początek strony">↑</a>
    <div class="container">
        © <span class="foot-strong">{{ now()->year }}</span> <span class="foot-strong">NoteSync</span> •
        <span aria-label="Kontakt">hello@notesync.pl</span>
    </div>
</footer>

@yield('body_end')

</body>
</html>
