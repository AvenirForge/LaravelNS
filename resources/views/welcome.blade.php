{{-- resources/views/welcome.blade.php --}}
    <!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>NoteSync — notatki pod ręką</title>
    <link href="https://fonts.googleapis.com/css2?family=Pacifico&family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />

    <style>
        :root{
            /* Kolory bazowe (ciemny, nowoczesny look) */
            --bg:#070a14; --bg2:#0d1324; --fg:#eef3ff; --muted:#aab7cc;
            --card:rgba(255,255,255,.07); --border:rgba(255,255,255,.12);

            /* Akcenty */
            --ctaA:#c1f4ed; --ctaB:#1794e0; --accent:#1794e0; --accent2:#0f689c;

            /* UI */
            --shadow:0 12px 48px rgba(0,0,0,.35); --radius:16px;
            --nav:74px; --container:1320px; --blur:14px;

            scroll-behavior:smooth;
        }
        @media (prefers-color-scheme: light){
            :root{
                --bg:#f5f7fb; --bg2:#e9eef6; --fg:#0b1220; --muted:#4a5872;
                --card:#ffffff; --border:rgba(0,0,0,.10);
                --ctaA:#c1f4ed; --ctaB:#1794e0; --shadow:0 10px 30px rgba(2,6,23,.10);
            }
        }
        @media (prefers-reduced-motion: reduce){
            *{animation:none !important; transition:none !important}
        }

        /* Reset / podstawy */
        *{box-sizing:border-box}
        html,body{height:100%}
        body{
            margin:0; font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial; color:var(--fg);
            background:var(--bg);
        }
        a{color:inherit; text-decoration:none}
        img{display:block; max-width:100%; height:auto}
        .container{max-width:var(--container); margin:0 auto; padding:0 22px}
        .container-narrow{max-width:1120px; margin:0 auto; padding:0 22px}

        /* ====== GLOBAL MODERN BACKGROUND (poza hero) ====== */
        .page-bg{
            position:relative; isolation:isolate;
            background:
                radial-gradient(60% 35% at 100% -10%, rgba(50,138,241,.22), transparent 60%),
                radial-gradient(50% 28% at -10% -10%, rgba(23,148,224,.18), transparent 60%),
                linear-gradient(180deg, var(--bg) 0%, var(--bg2) 100%);
        }
        .page-bg::after{
            /* delikatna siatka jak w topowych landach */
            content:""; position:absolute; inset:0; pointer-events:none; z-index:0;
            background:
                linear-gradient(to right, rgba(255,255,255,.05) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(255,255,255,.05) 1px, transparent 1px);
            background-size:44px 44px;
            mask-image:radial-gradient(1200px 600px at 50% 0%, rgba(0,0,0,.5), transparent 70%);
        }

        /* ====== NAVBAR (fixed na górze, full-width look) ====== */
        .nav{
            position:fixed; inset:0 0 auto 0; height:var(--nav); z-index:1000;
            display:flex; align-items:center;
            backdrop-filter:saturate(140%) blur(var(--blur));
            background:
                linear-gradient(180deg, rgba(6,10,22,.66), rgba(6,10,22,.28));
            border-bottom:1px solid var(--border);
        }
        .nav-inner{display:grid; grid-template-columns:auto 1fr auto; align-items:center; gap:12px; width:100%}
        .brand{display:flex; align-items:center; gap:10px; justify-self:start}
        .brand img{width:40px; height:40px}
        .brand span{font-family:Pacifico,cursive; font-size:28px; letter-spacing:.2px}
        .links{justify-self:center; display:flex; gap:14px}
        .link{padding:10px 12px; border-radius:10px}
        .link:hover{background:rgba(255,255,255,.08)}
        .nav-actions{justify-self:end; display:flex; align-items:center; gap:10px}
        .btn{
            display:inline-flex; gap:10px; align-items:center; padding:11px 16px; border-radius:12px; font-weight:700; box-shadow:var(--shadow);
            background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); color:#0b1020; border:0; transition:transform .15s ease, filter .2s ease;
        }
        .btn:hover{transform:translateY(-1px); filter:saturate(1.08)}

        /* Burger (mobile) */
        .burger{display:none; width:44px; height:44px; border-radius:12px; border:1px solid var(--border); background:var(--card); place-items:center}
        .burger svg{width:22px; height:22px}
        @media (max-width:980px){ .links{display:none} .burger{display:grid} }
        .mobile-panel{
            position:fixed; top:var(--nav); left:0; right:0; z-index:999;
            background:linear-gradient(180deg, rgba(6,10,25,.96), rgba(6,10,25,.90));
            border-bottom:1px solid var(--border); backdrop-filter:blur(var(--blur));
            overflow:hidden; max-height:0; opacity:.0;
            transition:max-height .45s cubic-bezier(.25,.8,.25,1), opacity .35s ease;
        }
        .mobile-panel.open{max-height:320px; opacity:1}
        .mobile-links{display:grid; gap:6px; padding:14px 22px 18px}
        .mobile-links a{padding:12px 12px; border-radius:10px; background:rgba(255,255,255,.06); border:1px solid var(--border)}
        .mobile-links a:active{transform:scale(.99)}

        /* ====== HERO (min-height: full screen minus navbar) ====== */
        .hero{
            position:relative; padding:calc(var(--nav) + 40px) 0 40px; isolation:isolate;
            min-height:calc(70vh); /* razem z fixed navbar daje pełnoekranowy first fold */
        }
        .hero::before{
            /* Zdjęcie + gradient, mocniej przyciemnione pod typografię */
            content:""; position:absolute; inset:0; z-index:-2;
            background:
                linear-gradient(180deg, rgba(7,10,20,.78) 0%, rgba(7,10,20,.82) 30%, rgba(7,10,20,.86) 100%),
                radial-gradient(80% 50% at 75% 10%, rgba(23,148,224,.22), transparent 60%),
                url('{{ asset('assets/images/ns-bg.jpg') }}') center/cover no-repeat;
            filter:saturate(1.05) contrast(1.03);
        }
        .hero::after{
            /* Subtelny „glow” pod tytułem dla lepszej czytelności */
            content:""; position:absolute; inset:0; z-index:-1; pointer-events:none;
            background:radial-gradient(400px 200px at 20% 35%, rgba(1,8,20,.35), transparent 60%);
        }
        .heroBox{display:grid; grid-template-columns:1.05fr .95fr; gap:32px; align-items:center}
        @media (max-width:980px){ .heroBox{grid-template-columns:1fr} }
        .kicker{color:var(--muted); font-weight:700; letter-spacing:.12em; text-transform:uppercase}
        h1{margin:10px 0 8px; font-size:clamp(42px,6.8vw,76px); line-height:1.02; text-shadow:0 2px 14px rgba(0,0,0,.35)}
        .lead{color:#dfe8ff; font-size:clamp(16px,2.2vw,20px); margin:0 0 18px; text-shadow:0 1px 8px rgba(0,0,0,.25)}
        .cta{display:inline-flex; gap:10px; align-items:center; padding:13px 20px; border-radius:14px; font-weight:700; box-shadow:var(--shadow);
            background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); color:#0b1020}
        .cta:hover{transform:translateY(-2px)}
        .card{background:var(--card); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); padding:18px}

        /* ====== SHOTS ====== */
        .shots{padding:18px 0 0; position:relative; z-index:1}
        .gridShots{display:flex; gap:16px; justify-content:center; align-items:flex-start; flex-wrap:wrap}
        .shot{width:min(240px,40vw); border-radius:18px; border:6px solid rgba(255,255,255,.10);
            box-shadow:var(--shadow); object-fit:contain; transition:transform .45s cubic-bezier(.25,.46,.45,.94), box-shadow .25s; animation-duration:4s; animation-iteration-count:infinite; animation-direction:alternate; will-change:transform}
        @keyframes floatA{0%{transform:translateY(0)}50%{transform:translateY(-10px)}100%{transform:translateY(0)}}
        @keyframes floatB{0%{transform:translateY(0)}50%{transform:translateY(10px)}100%{transform:translateY(0)}}
        .shot:nth-child(odd){animation-name:floatA}
        .shot:nth-child(even){animation-name:floatB; animation-delay:.4s}
        .shot:hover{transform:scale(1.06); animation-play-state:paused}

        /* ====== FEATURES ====== */
        .features{padding:28px 0 36px}
        .fgrid{display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:16px; position:relative; z-index:1}
        .tile{padding:18px; border-radius:14px; background:var(--card); border:1px solid var(--border); transition:transform .18s ease, box-shadow .18s ease}
        .tile h3{margin:6px 0 4px; font-size:18px}
        .tile p{margin:0; color:var(--muted)}
        .tile:hover{transform:translateY(-2px); box-shadow:0 14px 40px rgba(0,0,0,.25)}

        /* ====== ABOUT ====== */
        .about{padding:34px 0}
        .about-grid{display:grid; grid-template-columns:1.25fr .75fr; gap:20px; align-items:stretch}
        @media (max-width:980px){ .about-grid{grid-template-columns:1fr} }
        .badge{font-size:12px; font-weight:700; color:#0b1020; background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); display:inline-block; padding:6px 10px; border-radius:999px}
        .pill{display:inline-flex; align-items:center; gap:8px; padding:10px 12px; border-radius:12px; background:var(--card); border:1px solid var(--border); font-weight:600}
        .stack{display:flex; gap:10px; flex-wrap:wrap; margin-top:10px}

        /* ====== FAQ ====== */
        .faq{padding:22px 0 34px}
        .faq-list{display:grid; gap:12px}
        .faq-item{border:1px solid var(--border); border-radius:14px; background:var(--card); overflow:hidden}
        .faq-q{width:100%; text-align:left; background:transparent; color:var(--fg); padding:16px 18px; font-weight:700; border:0; cursor:pointer; display:flex; justify-content:space-between; align-items:center}
        .faq-a{max-height:0; overflow:hidden; transition:max-height .45s cubic-bezier(.25,.8,.25,1), opacity .35s ease; opacity:.0; padding:0 18px}
        .faq-item.open .faq-a{opacity:1; padding:0 18px 16px}
        .faq-icon{transition:transform .35s ease}
        .faq-item.open .faq-icon{transform:rotate(45deg)}

        /* ====== CONTACT ====== */
        .contact{padding:34px 0 56px}
        .form{display:grid; gap:12px}
        .row{display:grid; grid-template-columns:1fr 1fr; gap:12px}
        @media (max-width:720px){ .row{grid-template-columns:1fr} }
        .field{display:flex; flex-direction:column; gap:6px}
        .label{font-size:14px; color:var(--muted)}
        .input, .textarea{
            background:rgba(255,255,255,.05); border:1px solid var(--border); color:var(--fg); border-radius:12px;
            padding:12px 14px; outline:none;
        }
        .input:focus, .textarea:focus{border-color:var(--accent)}
        .textarea{min-height:140px; resize:vertical}
        .form-actions{display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap}
        .hint{color:var(--muted); font-size:12px}

        /* ====== FOOTER ====== */
        footer{border-top:1px solid var(--border); padding:26px 18px 42px; color:var(--muted); position:relative; text-align:center}
        .top{position:absolute; right:18px; top:18px; width:42px; height:42px; display:grid; place-items:center; border-radius:999px;
            background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); color:#0b1020; font-weight:900; box-shadow:var(--shadow)}
        .top:hover{transform:translateY(-2px)}
    </style>
</head>
<body id="top" class="page-bg">

<!-- NAVBAR -->
<div class="nav" role="banner">
    <div class="container nav-inner">
        <!-- Brand maksymalnie po lewej -->
        <a class="brand" href="#top" aria-label="Strona główna">
            <img src="{{ asset('assets/images/logo-notesync.svg') }}" alt="Logo" />
            <span>NoteSync</span>
        </a>

        <!-- Center links (desktop) -->
        <nav class="links" aria-label="Nawigacja główna">
            <a class="link" href="#features">Funkcje</a>
            <a class="link" href="#screens">Zrzuty</a>
            <a class="link" href="#about">O nas</a>
            <a class="link" href="#faq">FAQ</a>
            <a class="link" href="#download">Pobierz</a>
        </nav>

        <!-- CTA maksymalnie po prawej -->
        <div class="nav-actions">
            <a class="btn" href="#contact" id="contactBtn">Kontakt</a>
            <button id="burger" class="burger" aria-controls="mobilePanel" aria-expanded="false" aria-label="Menu">
                <!-- burger icon -->
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
        </div>
    </div>

    <!-- Mobile dropdown -->
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

<!-- HERO (min-h-screen) -->
<header class="hero" role="region" aria-label="Sekcja główna">
    <div class="container heroBox">
        <section>
            <div class="kicker">ANDROID • SYNC</div>
            <h1>Notuj. Porządkuj. <br/>Synchronizuj.</h1>
            <p class="lead">Szybka, lekka aplikacja do notatek z bezpieczną synchronizacją.</p>
            <a id="download" class="cta" href="#" rel="nofollow">Pobierz na Androida</a>
        </section>

        <aside class="card" aria-label="Highlighty">
            <ul style="list-style:none;margin:0;padding:0;display:grid;gap:10px">
                <li>• Offline → auto-sync</li>
                <li>• Zespoły i udostępnianie</li>
                <li>• Testy ABCD</li>
                <li>• Prywatność i szyfrowanie</li>
            </ul>
        </aside>
    </div>
</header>

<!-- SCREENSHOTS -->
<main class="shots" id="screens">
    <div class="container-narrow">
        <div class="gridShots">
            <img loading="lazy" class="shot" url="{{ asset('assets/images/app_light_1.png') }}" alt="Lista notatek" />
            <img loading="lazy" class="shot" url="{{ asset('assets/images/app_light_2.png') }}" alt="Edycja notatki" />
            <img loading="lazy" class="shot" url="{{ asset('assets/images/app_light_3.png') }}" alt="Zespoły" />
            <img loading="lazy" class="shot" url="{{ asset('assets/images/app_light_3.png') }}" alt="Quiz ABCD" />
        </div>
    </div>
</main>

<!-- FEATURES -->
<section class="features" id="features">
    <div class="container-narrow fgrid">
        <article class="tile"><h3>Chmura</h3><p>Notatki zawsze pod ręką.</p></article>
        <article class="tile"><h3>Zespoły</h3><p>Współpraca w czasie rzeczywistym.</p></article>
        <article class="tile"><h3>Wiedza</h3><p>Quizy ABCD do utrwalania.</p></article>
        <article class="tile"><h3>Bezpieczeństwo</h3><p>Nowoczesne standardy ochrony.</p></article>
    </div>
</section>

<!-- ABOUT -->
<section class="about" id="about" aria-labelledby="about-title">
    <div class="container-narrow about-grid">
        <div class="card">
            <span class="badge">O nas</span>
            <h2 id="about-title" style="margin:10px 0 6px">Tworzymy NoteSync z myślą o szybkości i prostocie</h2>
            <p style="color:var(--muted); margin:0 0 10px">
                Jesteśmy małym zespołem łączącym doświadczenie mobilne i backendowe. Dostarczamy narzędzia do notowania,
                które nie przeszkadzają w pracy — tylko ją przyspieszają.
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
    <div class="container-narrow">
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
    <div class="container-narrow">
        <div class="card">
            <h2 id="contact-title" style="margin:0 0 8px">Skontaktuj się z nami</h2>
            <p class="lead" style="margin:0 0 16px">Masz pytanie lub propozycję? Napisz — odpowiemy szybko.</p>
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
      'image' => asset('assets/images/app_light_1.png'),
      'description' => 'Lekka aplikacja do notatek z bezpieczną synchronizacją.',
    ];
@endphp
<script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) !!}</script>

<script>
    // FAQ accordion (animated)
    (function(){
        var items = document.querySelectorAll('.faq-item');
        items.forEach(function(it){
            var q = it.querySelector('.faq-q');
            var a = it.querySelector('.faq-a');
            q.addEventListener('click', function(){
                var isOpen = it.classList.contains('open');
                items.forEach(function(x){
                    x.classList.remove('open');
                    var xa = x.querySelector('.faq-a');
                    xa.style.maxHeight = 0;
                    x.querySelector('.faq-q').setAttribute('aria-expanded','false');
                });
                if(!isOpen){
                    it.classList.add('open');
                    a.style.maxHeight = a.scrollHeight + 'px';
                    q.setAttribute('aria-expanded','true');
                }
            });
            a.style.maxHeight = 0;
        });
    })();

    // Burger menu with neat animation
    (function(){
        var burger = document.getElementById('burger');
        var panel  = document.getElementById('mobilePanel');
        if(!burger || !panel) return;

        var open = false;
        function setState(state){
            open = state;
            panel.classList.toggle('open', open);
            burger.setAttribute('aria-expanded', String(open));
            // swap icon (burger ↔ close)
            burger.innerHTML = open
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M6 18L18 6"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>';
        }
        burger.addEventListener('click', function(){ setState(!open); });

        // close on link click
        panel.querySelectorAll('a').forEach(function(a){
            a.addEventListener('click', function(){ setState(false); });
        });

        // close on escape
        document.addEventListener('keydown', function(e){
            if(e.key === 'Escape' && open) setState(false);
        });
    })();
</script>
</body>
</html>
