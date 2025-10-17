{{-- resources/views/landing.blade.php --}}
    <!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>NoteSync — Twoje notatki zawsze pod ręką</title>
    <meta name="description" content="NoteSync to szybka i bezpieczna aplikacja do tworzenia, organizacji i synchronizacji notatek między urządzeniami. Idealna dla Androida.">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Pacifico&family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">

    {{-- Open Graph / Twitter --}}
    <meta property="og:title" content="NoteSync — Twoje notatki zawsze pod ręką">
    <meta property="og:description" content="Prosta, intuicyjna i zawsze dostępna aplikacja do notatek.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ config('app.url', 'https://notesync.pl') }}">
    <meta property="og:image" content="{{ asset('assets/images/app_light_1.png') }}">

    <style>
        :root{
            --bg: #0b1020;
            --bg-2:#0e1430;
            --fg: #eaf0ff;
            --muted:#98a2b3;
            --card: rgba(255,255,255,.06);
            --border: rgba(255,255,255,.10);
            --accent:#60a5fa;
            --accent-2:#1794e0;
            --cta-a:#c1f4ed; --cta-b:#1794e0;
            --shadow: 0 10px 40px rgba(0,0,0,.35);
        }
        @media (prefers-color-scheme: light){
            :root{
                --bg:#f4f7f6; --bg-2:#e7eeec; --fg:#222; --muted:#475569;
                --card:#ffffff; --border:rgba(0,0,0,.08);
                --accent:#0f689c; --accent-2:#1794e0;
                --cta-a:#c1f4ed; --cta-b:#1794e0;
                --shadow: 0 8px 30px rgba(0,0,0,.12);
            }
        }

        *{box-sizing:border-box}
        html,body{height:100%}
        body{
            margin:0; font-family: Inter,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial;
            color:var(--fg); background:
            radial-gradient(70% 40% at 80% -10%, rgba(96,165,250,.18), transparent),
            linear-gradient(180deg, var(--bg) 0%, var(--bg-2) 100%);
        }

        /* NAVBAR */
        .navbar{
            position:sticky; top:0; z-index:1000;
            backdrop-filter:saturate(140%) blur(10px);
            background: linear-gradient(180deg, rgba(0,0,0,.25), rgba(0,0,0,.05));
            border-bottom:1px solid var(--border);
        }
        .nav-inner{
            max-width:1100px; margin:0 auto; padding:14px 18px;
            display:flex; align-items:center; justify-content:space-between;
        }
        .brand{
            display:flex; align-items:center; gap:10px; text-decoration:none;
        }
        .brand img{width:34px; height:34px}
        .brand span{
            font-family: Pacifico, cursive; font-size:24px; color:var(--fg);
        }
        .nav-cta{
            display:flex; gap:10px; align-items:center;
        }
        .btn{appearance:none; border:none; cursor:pointer; border-radius:14px;
            padding:12px 16px; font-weight:700}
        .btn-ghost{
            background:transparent; color:var(--fg); outline:1px solid var(--border);
        }
        .btn-primary{
            background:linear-gradient(90deg, var(--cta-a), var(--cta-b));
            color:#0b1020; box-shadow: var(--shadow);
        }
        .btn-primary:hover{
            transform: translateY(-2px);
        }

        /* HERO */
        .hero{
            max-width:1100px; margin:0 auto; padding:64px 18px 24px;
            display:grid; grid-template-columns: 1.1fr .9fr; gap:28px;
            align-items:center;
        }
        .kicker{color:var(--muted); font-weight:600; letter-spacing:.12em; text-transform:uppercase}
        h1{font-size: clamp(34px,5.2vw,56px); line-height:1.05; margin:10px 0 10px}
        .lead{font-size: clamp(16px,2.4vw,20px); color:var(--fg); opacity:.92}
        .hero-cta{display:flex; gap:12px; margin-top:18px; flex-wrap:wrap}
        .card{
            background:var(--card); border:1px solid var(--border); border-radius:22px; padding:22px;
            box-shadow: var(--shadow);
        }

        /* SCREENSHOTS */
        .screens{max-width:1100px; margin:18px auto 8px; padding:0 18px;}
        .screen-grid{display:flex; gap:14px; justify-content:center; align-items:flex-start; flex-wrap:wrap}
        .shot{
            width:min(240px, 42vw); border-radius:18px; border:6px solid rgba(255,255,255,.08);
            box-shadow: var(--shadow); object-fit:contain;
            transition: transform .45s cubic-bezier(.25,.46,.45,.94), box-shadow .25s ease;
            animation-duration:4s; animation-iteration-count: infinite; animation-direction: alternate;
        }
        @keyframes floatA { 0%{transform:translateY(0)} 50%{transform:translateY(-10px)} 100%{transform:translateY(0)} }
        @keyframes floatB { 0%{transform:translateY(0)} 50%{transform:translateY(12px)} 100%{transform:translateY(0)} }
        .shot:nth-child(odd){ animation-name: floatA }
        .shot:nth-child(even){ animation-name: floatB; animation-delay:.4s }
        .shot:hover{ transform: scale(1.06); animation-play-state: paused }

        /* FEATURES */
        .features{max-width:1100px; margin:12px auto 28px; padding:0 18px}
        .features h2{font-size: clamp(24px,3.6vw,34px); margin:20px 0}
        .grid{display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:14px}
        .tile{background:var(--card); border:1px solid var(--border); border-radius:16px; padding:16px}
        .tile h3{margin:10px 0 6px; font-size:18px}
        .tile p{margin:0; color:var(--muted)}

        /* CTA bottom */
        .cta-bottom{max-width:1100px; margin:18px auto 48px; padding:0 18px; text-align:center}
        .cta-bottom .btn-primary{font-size:18px; padding:14px 18px}

        /* FOOTER */
        footer{border-top:1px solid var(--border); color:var(--muted); text-align:center; padding:26px 18px}

        /* RWD */
        @media (max-width: 980px){
            .hero{grid-template-columns: 1fr; padding-top:36px}
            .nav-inner{padding:12px 14px}
        }
    </style>
</head>
<body>

{{-- NAVBAR --}}
<nav class="navbar" role="navigation" aria-label="Główna nawigacja">
    <div class="nav-inner">
        <a class="brand" href="{{ url('/') }}" aria-label="NoteSync — strona główna">
            <img src="{{ asset('assets/images/logo-notesync.svg') }}" alt="Logo NoteSync" width="34" height="34">
            <span>NoteSync</span>
        </a>
        <div class="nav-cta">
            <a class="btn btn-ghost" href="#features">Funkcje</a>
            <a class="btn btn-ghost" href="#screens">Zrzuty</a>
            <a class="btn btn-primary" href="#download" aria-label="Pobierz aplikację na Androida">Pobierz na Androida</a>
        </div>
    </div>
</nav>

{{-- HERO --}}
<header class="hero">
    <section>
        <div class="kicker">Android • Synchronizacja • Zespoły</div>
        <h1>Uporządkuj myśli z NoteSync</h1>
        <p class="lead">Prosta, szybka i zawsze dostępna aplikacja do tworzenia, organizowania i bezpiecznej synchronizacji notatek między urządzeniami.</p>
        <div class="hero-cta">
            <a class="btn btn-primary" id="download" href="#" rel="nofollow">
                {{-- Możesz podmienić na prawdziwy link do Google Play --}}
                Pobierz z Google Play
            </a>
            <a class="btn btn-ghost" href="#features">Dlaczego NoteSync?</a>
        </div>

        <div class="card" style="margin-top:16px">
            <strong>Endpoint bazowy API:</strong> <code>{{ rtrim(config('app.url', 'https://notesync.pl'), '/') }}/api</code><br>
            <small>Autoryzacja: Bearer token / Sanctum (zgodnie z projektem).</small>
        </div>
    </section>

    <aside class="card" aria-label="Szybkie informacje">
        <ul style="list-style:none; padding:0; margin:0; display:grid; gap:10px">
            <li><strong>Tryb offline</strong> — notuj, a my zsynchronizujemy gdy wróci sieć.</li>
            <li><strong>Zespoły</strong> — udostępniaj notatki i pracuj wspólnie.</li>
            <li><strong>Testy ABCD</strong> — utrwalaj wiedzę interaktywnymi quizami.</li>
            <li><strong>Bezpieczeństwo</strong> — Twoje dane są wyłącznie Twoje.</li>
        </ul>
    </aside>
</header>

{{-- SCREENSHOTS --}}
<main>
    <section class="screens" id="screens" aria-labelledby="screenshots-heading">
        <h2 id="screenshots-heading" style="margin:0 0 14px 0">Zobacz, jak to działa</h2>
        <div class="screen-grid">
            <img
                class="shot"
                alt="Ekran listy notatek — NoteSync"
                data-light-src="{{ asset('assets/images/app_light_1.png') }}"
                data-dark-src="{{ asset('assets/images/app_dark_1.png') }}"
            />
            <img
                class="shot"
                alt="Widok edycji notatki — NoteSync"
                data-light-src="{{ asset('assets/images/app_light_2.png') }}"
                data-dark-src="{{ asset('assets/images/app_dark_2.png') }}"
            />
            <img
                class="shot"
                alt="Udostępnianie w zespole — NoteSync"
                data-light-src="{{ asset('assets/images/app_light_3.png') }}"
                data-dark-src="{{ asset('assets/images/app_dark_3.png') }}"
            />
            <img
                class="shot"
                alt="Testy ABCD — NoteSync"
                data-light-src="{{ asset('assets/images/app_light_4.png') }}"
                data-dark-src="{{ asset('assets/images/app_dark_4.png') }}"
            />
        </div>
    </section>

    {{-- FEATURES --}}
    <section class="features" id="features">
        <h2>Dlaczego NoteSync?</h2>
        <div class="grid">
            <article class="tile" aria-label="Synchronizacja w chmurze">
                <h3>Synchronizacja w chmurze</h3>
                <p>Prześlij notatkę i miej ją zawsze przy sobie — na każdym urządzeniu.</p>
            </article>
            <article class="tile" aria-label="Zespoły i udostępnianie">
                <h3>Zespoły i udostępnianie</h3>
                <p>Wbudowany system zespołów do współdzielenia notatek.</p>
            </article>
            <article class="tile" aria-label="Testy ABCD">
                <h3>Testy ABCD</h3>
                <p>Sprawdzony sposób na utrwalanie wiedzy i naukę.</p>
            </article>
            <article class="tile" aria-label="Prywatność i bezpieczeństwo">
                <h3>Prywatność i bezpieczeństwo</h3>
                <p>Ty decydujesz, co i komu udostępniasz — reszta zostaje u Ciebie.</p>
            </article>
        </div>
    </section>

    {{-- CTA BOTTOM --}}
    <section class="cta-bottom">
        <p class="lead" style="margin:8px 0 16px">Gotowy, by zacząć porządkować notatki?</p>
        <a class="btn btn-primary" href="#" rel="nofollow">Pobierz na Androida</a>
    </section>
</main>

<footer role="contentinfo">
    © {{ now()->year }} NoteSync • <span aria-label="Kontakt">hello@notesync.pl</span>
</footer>

{{-- JSON-LD (opcjonalnie pod SEO aplikacji) --}}
<script type="application/ld+json">
    {
      "@context":"https://schema.org",
      "@type":"SoftwareApplication",
      "name":"NoteSync",
      "operatingSystem":"Android",
      "applicationCategory":"ProductivityApplication",
      "offers": {"@type":"Offer","price":"0","priceCurrency":"PLN"},
      "url":"{{ config('app.url', 'https://notesync.pl') }}",
    "image":"{{ asset('assets/images/app_light_1.png') }}",
    "description":"Aplikacja do tworzenia i synchronizacji notatek z funkcjami zespołów i testów ABCD."
  }
</script>

{{-- Light/Dark switching dla screenów --}}
<script>
    (function(){
        const prefersDark = window.matchMedia("(prefers-color-scheme: dark)");
        const apply = () => {
            document.querySelectorAll('.shot').forEach(img => {
                const src = prefersDark.matches ? img.dataset.darkSrc : img.dataset.lightSrc;
                if (src && img.src !== src) img.src = src;
            });
        };
        apply();
        prefersDark.addEventListener('change', apply);
    })();
</script>
</body>
</html>
