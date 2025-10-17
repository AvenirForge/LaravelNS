{{-- resources/views/welcome.blade.php --}}
    <!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>NoteSync — notatki pod ręką</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=Pacifico&display=swap" rel="stylesheet" />

    <style>
        :root{
            --bg:#070a13; --bg2:#0d1324; --fg:#eef3ff; --muted:#b4c0d6;
            --card:rgba(255,255,255,.10); --border:rgba(255,255,255,.18);
            --ctaA:#bff0ea; --ctaB:#1794e0; --accent:#1ea9ff;

            --shadow:0 14px 48px rgba(0,0,0,.36);
            --radius:16px;
            --nav:78px;
            --container:1400px; /* +25% szerzej */
            --blur:16px;
        }
        @media (prefers-color-scheme: light){
            :root{
                --bg:#f6f8fc; --bg2:#e9eef6; --fg:#0a1224; --muted:#4a5872;
                --card:#ffffff; --border:rgba(0,0,0,.10);
                --ctaA:#c1f4ed; --ctaB:#1794e0;
                --shadow:0 12px 36px rgba(2,6,23,.12);
            }
        }
        @media (prefers-reduced-motion: reduce){ *{animation:none!important; transition:none!important} }

        *{box-sizing:border-box}
        html,body{height:100%}
        body{
            margin:0; font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial;
            -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
            color:var(--fg); background:var(--bg);
        }
        a{color:inherit; text-decoration:none}
        img{display:block; max-width:100%; height:auto}
        .container{max-width:var(--container) ; margin:0 auto; padding:0 26px}
        .container-narrow{max-width:1360px; margin:0 auto; padding:0 26px}
        [id]{scroll-margin-top:calc(var(--nav) + 12px)}

        /* Page background */
        .page-bg{position:relative; isolation:isolate; background:linear-gradient(180deg,var(--bg) 0%,var(--bg2) 100%)}
        .page-bg::before{
            content:""; position:fixed; inset:-20vh 0 0; z-index:-2; pointer-events:none;
            background:
                radial-gradient(60% 35% at 120% -10%, rgba(50,138,241,.22), transparent 60%),
                radial-gradient(50% 28% at -10% -10%, rgba(23,148,224,.18), transparent 60%);
        }

        /* NAVBAR */
        .nav{
            position:fixed; inset:0 0 auto 0; height:var(--nav); z-index:1000;
            display:flex; align-items:center;
            backdrop-filter:saturate(160%) blur(var(--blur));
            background:linear-gradient(180deg, rgba(6,12,28,.92) 0%, rgba(6,12,28,.85) 60%, rgba(6,12,28,.70) 100%);
            border-bottom:1px solid rgba(255,255,255,.15);
            box-shadow:0 8px 24px rgba(0,0,0,.35);
        }
        .nav-inner{display:grid; grid-template-columns:auto 1fr auto; align-items:center; gap:12px; width:100%}
        .brand{display:inline-flex; align-items:center; gap:10px; color:#e8eeff;}
        .brand span{font-family:Pacifico,cursive; font-size:28px; letter-spacing:.2px; opacity:.95}
        .brand-logo{width:28px; height:auto; margin-right:4px; fill:#fff; transition:transform .25s ease, opacity .25s ease}
        .brand:hover .brand-logo{transform:rotate(-5deg) scale(1.05); opacity:.9}
        .links{justify-self:center; display:flex; gap:18px}
        .link{padding:10px 12px; border-radius:10px; color:#e8eeff}
        .link:hover,.link:focus-visible{background:rgba(255,255,255,.10); outline:none}
        .nav-actions{justify-self:end; display:flex; align-items:center; gap:10px}
        .btn{
            display:inline-flex; gap:10px; align-items:center; padding:12px 18px; border-radius:12px;
            font-weight:800; letter-spacing:.2px;
            background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); color:#061021; border:0;
            box-shadow:0 8px 24px rgba(0,0,0,.35), inset 0 0 0 1px rgba(255,255,255,.25);
            transition:transform .18s ease, filter .2s ease, box-shadow .2s ease;
        }
        .btn:hover{transform:translateY(-1px); filter:saturate(1.08); box-shadow:0 12px 34px rgba(0,0,0,.42), inset 0 0 0 1px rgba(255,255,255,.35)}

        /* Burger (mobile) */
        .burger{display:none; width:44px; height:44px; border-radius:12px; border:1px solid rgba(255,255,255,.25); background:rgba(255,255,255,.06); place-items:center; color:#fff}
        .burger svg{width:22px; height:22px}
        @media (max-width:980px){ .links{display:none} .burger{display:grid} }
        .scrim{position:fixed; inset:var(--nav) 0 0 0; background:rgba(0,0,0,.55); backdrop-filter:blur(2px); opacity:0; pointer-events:none; transition:opacity .3s ease}
        .scrim.open{opacity:1; pointer-events:auto}
        .mobile-panel{
            position:fixed; top:var(--nav); left:0; right:0; z-index:999;
            background:linear-gradient(180deg, rgba(10,17,36,.98), rgba(10,17,36,.92));
            border-bottom:1px solid rgba(255,255,255,.16); box-shadow:0 30px 60px rgba(0,0,0,.45);
            overflow:hidden; max-height:0; opacity:0; transform:translateY(-8px);
            transition:max-height .45s cubic-bezier(.25,.8,.25,1), opacity .35s ease, transform .35s ease;
        }
        .mobile-panel.open{max-height:420px; opacity:1; transform:translateY(0)}
        .mobile-links{display:grid; gap:10px; padding:18px 26px 22px}
        .mobile-links a{
            padding:14px; border-radius:12px; background:rgba(255,255,255,.09);
            border:1px solid rgba(255,255,255,.18); color:#f5f8ff; font-weight:600;
            box-shadow:inset 0 0 0 1px rgba(255,255,255,.06);
        }

        /* HERO (2/3 ekranu) */
        .hero{
            position:relative; padding:calc(var(--nav) + 28px) 0 48px; isolation:isolate;
            min-height:calc(66vh + var(--nav));
            display:flex; align-items:center;
        }
        .hero::before{
            content:""; position:absolute; inset:0; z-index:-2;
            background:
                radial-gradient(90% 50% at 80% 10%, rgba(30,169,255,.22), transparent 60%),
                linear-gradient(180deg, rgba(8,12,26,.85) 0%, rgba(8,12,26,.80) 60%, rgba(8,12,26,.88) 100%),
                url('{{ asset('assets/images/ns-bg.jpg') }}') center/cover no-repeat;
            filter:saturate(1.02) contrast(1.03);
        }
        .heroBox{
            max-width:980px; margin-inline:auto;
            display:flex; flex-direction:column; align-items:center; gap:22px; text-align:center;
        }
        .kicker{color:#c8d6f9; font-weight:700; letter-spacing:.18em; text-transform:uppercase; opacity:.9}
        h1{
            margin:6px 0 10px; font-weight:800; line-height:1.02; font-size:clamp(42px,6.2vw,74px);
            background:linear-gradient(180deg,#f7fbff 0%, #cfe6ff 70%, #9fcbff 100%);
            -webkit-background-clip:text; background-clip:text; color:transparent;
            text-shadow:0 2px 16px rgba(0,0,0,.35);
        }
        .lead{color:#e5eeff; font-size:clamp(16px,2.1vw,20px); margin:0 0 12px; text-shadow:0 1px 10px rgba(0,0,0,.25)}
        .cta{
            display:inline-flex; gap:10px; align-items:center; padding:14px 22px; border-radius:14px; font-weight:800;
            background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); color:#061021;
            box-shadow:0 14px 40px rgba(0,0,0,.35), inset 0 0 0 1px rgba(255,255,255,.28);
            transition:transform .18s ease, box-shadow .2s ease, filter .2s ease;
        }
        .cta:hover{transform:translateY(-2px); filter:saturate(1.05); box-shadow:0 18px 48px rgba(0,0,0,.45), inset 0 0 0 1px rgba(255,255,255,.36)}

        .card{background:var(--card); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); padding:18px}

        /* SCREENSHOTS — SZERZEJ + LIGHTBOX */
        .shots{padding:28px 0 6px}
        .gridShots{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(360px,1fr)); /* szerzej */
            gap:22px;
            align-items:start;
        }
        .shot-btn{
            display:block; width:100%; background:var(--card); border:1px solid var(--border);
            border-radius:22px; padding:14px; box-shadow:var(--shadow); cursor:zoom-in;
            transition:transform .18s ease, box-shadow .18s ease;
            overflow:hidden;
        }
        .shot-btn:hover{transform:translateY(-2px); box-shadow:0 16px 44px rgba(0,0,0,.38)}
        .shot{width:100%; height:auto; border-radius:14px; object-fit:contain; transition:transform .25s ease}
        .shot-btn:hover .shot, .shot-btn:focus-visible .shot{transform:scale(1.03)}

        .lightbox{
            position:fixed; inset:0; background:rgba(0,0,0,.8);
            display:flex; align-items:center; justify-content:center; padding:24px;
            opacity:0; pointer-events:none; transition:opacity .25s ease;
            z-index:1200;
        }
        .lightbox.open{opacity:1; pointer-events:auto}
        .lightbox img{
            max-width:min(96vw, 1200px); max-height:90vh; object-fit:contain;
            border-radius:16px; box-shadow:0 20px 60px rgba(0,0,0,.55);
            background:#000;
        }
        .lightbox-close{
            position:absolute; top:24px; right:24px; border:0; border-radius:12px; padding:10px 12px;
            font-weight:800; cursor:pointer; color:#061021;
            background:linear-gradient(90deg,var(--ctaA),var(--ctaB));
            box-shadow:0 10px 30px rgba(0,0,0,.45);
        }

        /* SECTIONS */
        .features{padding:32px 0 38px}
        .fgrid{display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:18px}
        .tile{padding:20px; border-radius:16px; background:var(--card); border:1px solid var(--border); transition:transform .18s ease, box-shadow .18s ease}
        .tile h3{margin:6px 0 6px; font-size:18px}
        .tile p{margin:0; color:var(--muted)}
        .tile:hover{transform:translateY(-2px); box-shadow:0 14px 40px rgba(0,0,0,.25)}

        .about{padding:38px 0}
        .about-grid{display:grid; grid-template-columns:1.25fr .75fr; gap:22px}
        @media (max-width:980px){ .about-grid{grid-template-columns:1fr} }
        .badge{font-size:12px; font-weight:800; color:#061021; background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); display:inline-block; padding:6px 10px; border-radius:999px}
        .pill{display:inline-flex; align-items:center; gap:8px; padding:10px 12px; border-radius:12px; background:var(--card); border:1px solid var(--border); font-weight:700}
        .stack{display:flex; gap:10px; flex-wrap:wrap; margin-top:10px}

        .faq{padding:26px 0 40px}
        .faq-list{display:grid; gap:12px}
        .faq-item{border:1px solid var(--border); border-radius:14px; background:var(--card); overflow:hidden}
        .faq-q{width:100%; text-align:left; background:transparent; color:var(--fg); padding:16px 18px; font-weight:800; border:0; cursor:pointer; display:flex; justify-content:space-between; align-items:center}
        .faq-a{max-height:0; overflow:hidden; transition:max-height .45s cubic-bezier(.25,.8,.25,1), opacity .35s ease; opacity:0; padding:0 18px}
        .faq-item.open .faq-a{opacity:1; padding:0 18px 16px}
        .faq-icon{transition:transform .35s ease}
        .faq-item.open .faq-icon{transform:rotate(45deg)}

        .contact{padding:40px 0 60px}
        .form{display:grid; gap:12px}
        .row{display:grid; grid-template-columns:1fr 1fr; gap:12px}
        @media (max-width:720px){ .row{grid-template-columns:1fr} }
        .field{display:flex; flex-direction:column; gap:6px}
        .label{font-size:14px; color:var(--muted)}
        .input,.textarea{background:rgba(255,255,255,.08); border:1px solid var(--border); color:var(--fg); border-radius:12px; padding:12px 14px; outline:none}
        .input:focus,.textarea:focus{border-color:var(--accent)}
        .textarea{min-height:140px; resize:vertical}
        .form-actions{display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap}
        .hint{color:var(--muted); font-size:12px}

        footer{border-top:1px solid var(--border); padding:28px 18px 44px; color:var(--muted); position:relative; text-align:center}
        .top{position:absolute; right:18px; top:18px; width:42px; height:42px; display:grid; place-items:center; border-radius:999px; background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); color:#061021; font-weight:900; box-shadow:var(--shadow)}
        .top:hover{transform:translateY(-2px)}
    </style>
</head>
<body id="top" class="page-bg">

@php
    if (!function_exists('notesync_resolve_asset')) {
        function notesync_resolve_asset(string $basename): string {
            $exts = ['png','jpg','jpeg','webp','PNG','JPG','JPEG','WEBP'];
            foreach ($exts as $ext) {
                $rel = "assets/images/{$basename}.{$ext}";
                $abs = public_path($rel);
                if (file_exists($abs)) {
                    $v = @filemtime($abs) ?: time();
                    return asset($rel).'?v='.$v;
                }
            }
            return 'data:image/svg+xml;utf8,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>');
        }
    }
    $shots = [
        ['file'=>'app_light_1', 'alt'=>'Lista notatek'],
        ['file'=>'app_light_2', 'alt'=>'Edycja notatki'],
        ['file'=>'app_light_3', 'alt'=>'Zespoły'],
    ];
@endphp

    <!-- NAVBAR -->
<div class="nav" role="banner">
    <div class="container nav-inner">
        <a class="brand" href="#top" aria-label="Strona główna">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 412 511.87" class="brand-logo"><path d="M35.7 32.95h33.54V11.18C69.24 5.01 74.25 0 80.43 0c6.17 0 11.18 5.01 11.18 11.18v21.77h49.21V11.18c0-6.17 5.01-11.18 11.19-11.18 6.17 0 11.18 5.01 11.18 11.18v21.77h49.21V11.18C212.4 5.01 217.41 0 223.59 0c6.17 0 11.18 5.01 11.18 11.18v21.77h49.21V11.18c0-6.17 5.01-11.18 11.19-11.18 6.17 0 11.18 5.01 11.18 11.18v21.77h34.55c9.83 0 18.76 4.03 25.21 10.49 5.36 5.35 9.04 12.4 10.15 20.23h.04c9.82 0 18.76 4.03 25.21 10.48C407.98 80.62 412 89.56 412 99.37v376.8c0 9.77-4.04 18.7-10.49 25.17-6.51 6.5-15.45 10.53-25.21 10.53H67.71c-9.81 0-18.75-4.02-25.22-10.49-6.14-6.14-10.09-14.53-10.45-23.8-8.36-.86-15.9-4.66-21.55-10.31C4.03 460.82 0 451.89 0 442.06V68.65c0-9.83 4.03-18.77 10.48-25.22 6.45-6.45 15.39-10.48 25.22-10.48z" fill="#fff"/></svg>
            <span>NoteSync</span>
        </a>

        <nav class="links" aria-label="Nawigacja główna">
            <a class="link" href="#features">Funkcje</a>
            <a class="link" href="#screens">Zrzuty</a>
            <a class="link" href="#about">O nas</a>
            <a class="link" href="#faq">FAQ</a>
            <a class="link" href="#download">Pobierz</a>
        </nav>

        <div class="nav-actions">
            <a class="btn" href="#contact" id="contactBtn">Kontakt</a>
            <button id="burger" class="burger" aria-controls="mobilePanel" aria-expanded="false" aria-label="Menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
        </div>
    </div>

    <div id="navScrim" class="scrim" aria-hidden="true"></div>
    <div id="mobilePanel" class="mobile-panel" role="region" aria-label="Menu mobilne">
        <div class="mobile-links">
            <a href="#features">Funkcje</a>
            <a href="#screens">Zrzuty</a>
            <a href="#about">O nas</a>
            <a href="#faq">FAQ</a>
            <a href="#download">Pobierz</a>
            <a href="#contact">Kontakt</a>
        </div>
    </div>
</div>

<!-- HERO -->
<header class="hero" role="region" aria-label="Sekcja główna">
    <div class="container heroBox">
        <div class="kicker">ANDROID • SYNC</div>
        <h1>Notuj. Porządkuj.<br/>Synchronizuj.</h1>
        <p class="lead">Szybka, lekka aplikacja do notatek z bezpieczną synchronizacją.</p>
        <a id="download" class="cta" href="#download" rel="nofollow">Pobierz na Androida</a>
    </div>
</header>

<!-- SCREENSHOTS (poszerzone + lightbox) -->
<main class="shots" id="screens">
    <div style="margin-top: 150px;" class="container">
        <div class="gridShots">
            @foreach($shots as $s)
                @php $src = notesync_resolve_asset($s['file']); @endphp
                <button class="shot-btn" data-full="{{ $src }}" aria-label="Powiększ zrzut: {{ $s['alt'] }}">
                    <img class="shot" src="{{ $src }}" alt="{{ $s['alt'] }}" loading="lazy" decoding="async"/>
                </button>
            @endforeach
        </div>
    </div>
</main>

<!-- FEATURES -->
<section class="features" id="features">
    <div class="container fgrid">
        <article class="tile"><h3>Chmura</h3><p>Notatki zawsze pod ręką.</p></article>
        <article class="tile"><h3>Zespoły</h3><p>Współpraca w czasie rzeczywistym.</p></article>
        <article class="tile"><h3>Wiedza</h3><p>Quizy ABCD do utrwalania.</p></article>
    </div>
</section>

<!-- ABOUT -->
<section class="about" id="about" aria-labelledby="about-title">
    <div class="container about-grid">
        <div class="card">
            <span class="badge">O nas</span>
            <h2 id="about-title" style="margin:10px 0 6px">Tworzymy NoteSync z myślą o szybkości i prostocie</h2>
            <p style="color:var(--muted); margin:0 0 10px">
                Mały zespół, duże doświadczenie mobilne i backendowe. Narzędzia, które nie przeszkadzają — przyspieszają.
            </p>
            <div class="stack">
                <span class="pill">Frontend: React Native</span>
                <span class="pill">Backend: Laravel API</span>
                <span class="pill">Sync: REST + Webhooks</span>
                <span class="pill">Bezpieczeństwo: JWT + szyfrowanie</span>
            </div>
        </div>
        <div class="card" aria-label="Zespół">
            <h3 style="margin:0 0 8px">Zespół</h3>
            <ul style="list-style:none;margin:0;padding:0;display:grid;gap:10px">
                <li><strong>Frontend (React Native):</strong> UI/UX, offline-first, animacje, dostępność.</li>
                <li><strong>Backend (Laravel API):</strong> architektura, bezpieczeństwo, synchronizacja.</li>
                <li><strong>DevOps:</strong> CI/CD, monitoring, stabilność aktualizacji.</li>
            </ul>
        </div>
    </div>
</section>

<!-- FAQ -->
<section class="faq" id="faq" aria-labelledby="faq-title">
    <div class="container">
        <h2 id="faq-title" style="margin:0 0 10px">FAQ — najczęstsze pytania</h2>
        <div class="faq-list" role="list">
            <div class="faq-item" role="listitem">
                <button class="faq-q" aria-expanded="false"><span>Czy NoteSync działa offline?</span><span class="faq-icon">＋</span></button>
                <div class="faq-a"><p>Tak — edytujesz bez internetu, a połączenie uruchamia automatyczną synchronizację.</p></div>
            </div>
            <div class="faq-item" role="listitem">
                <button class="faq-q" aria-expanded="false"><span>Jak wygląda synchronizacja między urządzeniami?</span><span class="faq-icon">＋</span></button>
                <div class="faq-a"><p>Laravel REST API + wersjonowanie zmian; aplikacja mobilna scala je bezkolizyjnie.</p></div>
            </div>
            <div class="faq-item" role="listitem">
                <button class="faq-q" aria-expanded="false"><span>Czy moje dane są bezpieczne?</span><span class="faq-icon">＋</span></button>
                <div class="faq-a"><p>JWT, szyfrowanie w tranzycie i spoczynku, dobre praktyki OWASP i zasada minimalnych uprawnień.</p></div>
            </div>
            <div class="faq-item" role="listitem">
                <button class="faq-q" aria-expanded="false"><span>Czy mogę współdzielić notatki z zespołem?</span><span class="faq-icon">＋</span></button>
                <div class="faq-a"><p>Tak — zapraszaj członków zespołu i ustawiaj uprawnienia na folderach lub pojedynczych notatkach.</p></div>
            </div>
            <div class="faq-item" role="listitem">
                <button class="faq-q" aria-expanded="false"><span>Czy wspieracie tryb ciemny?</span><span class="faq-icon">＋</span></button>
                <div class="faq-a"><p>UI respektuje preferencje systemowe; czytelne kontrasty w jasnym i ciemnym motywie.</p></div>
            </div>
        </div>
    </div>
</section>

<!-- CONTACT -->
<section class="contact" id="contact" aria-labelledby="contact-title">
    <div class="container">
        <div class="card">
            <h2 id="contact-title" style="margin:0 0 8px">Skontaktuj się z nami</h2>
            <p class="lead" style="color: black; margin:0 0 16px">Masz pytanie lub propozycję? Napisz — odpowiemy szybko.</p>
            <form class="form" action="#" method="post" novalidate>
                @csrf
                <div class="row">
                    <div class="field">
                        <label class="label" for="name">Imię i nazwisko</label>
                        <input class="input" type="text" id="name" name="name" placeholder="Jan Kowalski" autocomplete="name" required>
                    </div>
                    <div class="field">
                        <label class="label" for="email">E-mail</label>
                        <input class="input" type="email" id="email" name="email" placeholder="jan@example.com" autocomplete="email" required>
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="message">Wiadomość</label>
                    <textarea class="textarea" id="message" name="message" placeholder="W czym możemy pomóc?" required></textarea>
                </div>
                <div class="form-actions">
                    <label style="display:flex; align-items:center; gap:10px; color:var(--muted); font-size:14px;">
                        <input type="checkbox" required style="transform:translateY(1px)">
                        Zgadzam się na kontakt w sprawie mojego zapytania.
                    </label>
                    <button class="btn" type="submit">Wyślij</button>
                </div>
                <div class="hint">Chronimy Twoją prywatność. Nie udostępniamy danych osobom trzecim.</div>
            </form>
        </div>
    </div>
</section>

<!-- FOOTER -->
<footer>
    <a class="top" href="#top" aria-label="Do góry">↑</a>
    © {{ now()->year }} NoteSync
</footer>

@php
    $ld = [
      '@context' => 'https://schema.org',
      '@type' => 'SoftwareApplication',
      'name' => 'NoteSync',
      'operatingSystem' => 'Android',
      'applicationCategory' => 'ProductivityApplication',
      'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'PLN'],
      'url' => config('app.url', 'https://notesync.pl'),
      'image' => notesync_resolve_asset('app_light_1'),
      'description' => 'Lekka aplikacja do notatek z bezpieczną synchronizacją.',
    ];
@endphp
<script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) !!}</script>

<script>
    /* FAQ – akordeon */
    (function(){
        var items = document.querySelectorAll('.faq-item');
        items.forEach(function(it){
            var q = it.querySelector('.faq-q');
            var a = it.querySelector('.faq-a');
            q.addEventListener('click', function(){
                var open = it.classList.contains('open');
                items.forEach(function(x){
                    x.classList.remove('open');
                    var xa = x.querySelector('.faq-a'); xa.style.maxHeight = 0;
                    x.querySelector('.faq-q').setAttribute('aria-expanded','false');
                });
                if(!open){
                    it.classList.add('open');
                    a.style.maxHeight = a.scrollHeight + 'px';
                    q.setAttribute('aria-expanded','true');
                }
            });
            a.style.maxHeight = 0;
        });
    })();

    /* Burger + scrim */
    (function(){
        var burger = document.getElementById('burger');
        var panel  = document.getElementById('mobilePanel');
        var scrim  = document.getElementById('navScrim');
        if(!burger || !panel || !scrim) return;
        var open = false;
        function setState(state){
            open = state;
            panel.classList.toggle('open', open);
            scrim.classList.toggle('open', open);
            burger.setAttribute('aria-expanded', String(open));
            burger.innerHTML = open
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M6 18L18 6"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>';
        }
        burger.addEventListener('click', function(){ setState(!open); });
        scrim.addEventListener('click', function(){ setState(false); });
        panel.querySelectorAll('a').forEach(function(a){ a.addEventListener('click', function(){ setState(false); }); });
        document.addEventListener('keydown', function(e){ if(e.key==='Escape' && open) setState(false); });
    })();

    /* LIGHTBOX – klik na zrzut -> podgląd z możliwością zamknięcia */
    (function(){
        var container = document.createElement('div');
        container.id = 'lightbox';
        container.className = 'lightbox';
        container.innerHTML = '<button class="lightbox-close" aria-label="Zamknij">Zamknij</button><img id="lightboxImg" alt="Podgląd zrzutu"/>';
        document.body.appendChild(container);

        var imgEl = container.querySelector('#lightboxImg');
        var closeBtn = container.querySelector('.lightbox-close');

        function open(src){
            imgEl.src = src;
            container.classList.add('open');
        }
        function close(){
            container.classList.remove('open');
            imgEl.src = '';
        }

        document.querySelectorAll('.shot-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                open(btn.getAttribute('data-full'));
            });
        });
        container.addEventListener('click', function(e){ if(e.target === container) close(); });
        closeBtn.addEventListener('click', close);
        document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && container.classList.contains('open')) close(); });
    })();
</script>
</body>
</html>
