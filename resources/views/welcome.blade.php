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
            --bg:#0b1020; --bg2:#0e1430; --fg:#eaf0ff; --muted:#9aa4b2;
            --card:rgba(255,255,255,.06); --border:rgba(255,255,255,.10);
            --ctaA:#c1f4ed; --ctaB:#1794e0; --accent:#1794e0; --accent2:#0f689c;
            --shadow:0 10px 40px rgba(0,0,0,.28); --radius:16px; --nav:64px; --container:1100px;
            scroll-behavior:smooth;
        }
        @media (prefers-color-scheme: light){
            :root{
                --bg:#f4f7f6; --bg2:#e9efec; --fg:#1c2430; --muted:#4b5563;
                --card:#ffffff; --border:rgba(0,0,0,.08);
                --ctaA:#c1f4ed; --ctaB:#1794e0;
                --shadow:0 10px 30px rgba(2,6,23,.10);
            }
        }
        *{box-sizing:border-box}
        html,body{height:100%}
        body{
            margin:0; font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial; color:var(--fg);
            background:
                radial-gradient(60% 40% at 85% -10%, rgba(96,165,250,.20), transparent 60%),
                radial-gradient(40% 35% at -5% -10%, rgba(23,148,224,.18), transparent 60%),
                linear-gradient(180deg, var(--bg) 0%, var(--bg2) 100%);
        }
        a{color:inherit; text-decoration:none}
        img{display:block; max-width:100%; height:auto}
        .container{max-width:var(--container); margin:0 auto; padding:0 20px}

        /* NAVBAR */
        .nav{
            position:sticky; top:0; z-index:1000; height:var(--nav);
            display:flex; align-items:center; border-bottom:1px solid var(--border);
            backdrop-filter:saturate(140%) blur(10px);
            background:linear-gradient(180deg, rgba(0,0,0,.25), rgba(0,0,0,.06));
        }
        .nav-inner{
            display:grid; grid-template-columns:auto 1fr auto; align-items:center; gap:16px;
        }
        .brand{display:flex; align-items:center; gap:10px; justify-self:start}
        .brand img{width:36px; height:36px}
        .brand span{font-family:Pacifico,cursive; font-size:26px}
        .links{display:flex; gap:12px; justify-self:center}
        .link{padding:10px 12px; border-radius:10px}
        .link:hover{background:rgba(255,255,255,.06)}
        .nav-cta{justify-self:end}
        .btn{
            display:inline-flex; gap:10px; align-items:center; padding:10px 14px; border-radius:12px; font-weight:700; box-shadow:var(--shadow);
            background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); color:#0b1020; border:0;
        }
        .btn:hover{transform:translateY(-1px)}
        @media (max-width:920px){
            .links{display:none}
        }

        /* HERO */
        .hero{padding:72px 0 18px}
        .heroBox{display:grid; grid-template-columns:1.1fr .9fr; gap:28px; align-items:center}
        @media (max-width:920px){ .heroBox{grid-template-columns:1fr} }
        .kicker{color:var(--muted); font-weight:700; letter-spacing:.12em; text-transform:uppercase}
        h1{margin:10px 0 8px; font-size:clamp(30px,5.6vw,56px); line-height:1.05}
        .lead{color:var(--muted); font-size:clamp(15px,2.5vw,18px); margin:0 0 14px}
        .cta{display:inline-flex; gap:10px; align-items:center; padding:12px 18px; border-radius:14px; font-weight:700; box-shadow:var(--shadow);
            background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); color:#0b1020}
        .cta:hover{transform:translateY(-2px)}
        .card{background:var(--card); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); padding:18px}

        /* SHOTS */
        .shots{padding:12px 0 0}
        .gridShots{display:flex; gap:16px; justify-content:center; align-items:flex-start; flex-wrap:wrap}
        .shot{width:min(240px,40vw); border-radius:18px; border:6px solid rgba(255,255,255,.08);
            box-shadow:var(--shadow); object-fit:contain; transition:transform .45s cubic-bezier(.25,.46,.45,.94), box-shadow .25s; animation-duration:4s; animation-iteration-count:infinite; animation-direction:alternate}
        @keyframes floatA{0%{transform:translateY(0)}50%{transform:translateY(-10px)}100%{transform:translateY(0)}}
        @keyframes floatB{0%{transform:translateY(0)}50%{transform:translateY(10px)}100%{transform:translateY(0)}}
        .shot:nth-child(odd){animation-name:floatA}
        .shot:nth-child(even){animation-name:floatB; animation-delay:.4s}
        .shot:hover{transform:scale(1.06); animation-play-state:paused}

        /* FEATURES */
        .features{padding:22px 0 28px}
        .fgrid{display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px}
        .tile{padding:16px; border-radius:14px; background:var(--card); border:1px solid var(--border)}
        .tile h3{margin:6px 0 4px; font-size:18px}
        .tile p{margin:0; color:var(--muted)}

        /* ABOUT */
        .about{padding:28px 0}
        .about-grid{display:grid; grid-template-columns:1.2fr .8fr; gap:18px; align-items:stretch}
        @media (max-width:920px){ .about-grid{grid-template-columns:1fr} }
        .badge{font-size:12px; font-weight:700; color:#0b1020; background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); display:inline-block; padding:6px 10px; border-radius:999px}
        .pill{display:inline-flex; align-items:center; gap:8px; padding:10px 12px; border-radius:12px; background:var(--card); border:1px solid var(--border); font-weight:600}
        .stack{display:flex; gap:10px; flex-wrap:wrap; margin-top:10px}

        /* FAQ */
        .faq{padding:18px 0 30px}
        .faq-list{display:grid; gap:10px}
        .faq-item{border:1px solid var(--border); border-radius:14px; background:var(--card); overflow:hidden}
        .faq-q{width:100%; text-align:left; background:transparent; color:var(--fg); padding:16px 18px; font-weight:700; border:0; cursor:pointer; display:flex; justify-content:space-between; align-items:center}
        .faq-a{max-height:0; overflow:hidden; transition:max-height .45s cubic-bezier(.25,.8,.25,1), opacity .35s ease; opacity:.0; padding:0 18px}
        .faq-item.open .faq-a{opacity:1; padding:0 18px 16px}
        .faq-icon{transition:transform .35s ease}
        .faq-item.open .faq-icon{transform:rotate(45deg)}

        /* CONTACT */
        .contact{padding:28px 0 46px}
        .form{display:grid; gap:12px}
        .row{display:grid; grid-template-columns:1fr 1fr; gap:12px}
        @media (max-width:720px){ .row{grid-template-columns:1fr} }
        .field{display:flex; flex-direction:column; gap:6px}
        .label{font-size:14px; color:var(--muted)}
        .input, .textarea{
            background:rgba(255,255,255,.04); border:1px solid var(--border); color:var(--fg); border-radius:12px;
            padding:12px 14px; outline:none;
        }
        .input:focus, .textarea:focus{border-color:var(--accent)}
        .textarea{min-height:140px; resize:vertical}
        .form-actions{display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap}
        .hint{color:var(--muted); font-size:12px}

        /* FOOTER */
        footer{border-top:1px solid var(--border); padding:26px 18px 42px; color:var(--muted); position:relative; text-align:center}
        .top{position:absolute; right:18px; top:18px; width:42px; height:42px; display:grid; place-items:center; border-radius:999px;
            background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); color:#0b1020; font-weight:900; box-shadow:var(--shadow)}
        .top:hover{transform:translateY(-2px)}
    </style>
</head>
<body id="top">

<!-- NAVBAR -->
<div class="nav">
    <div class="container nav-inner">
        <a class="brand" href="#top" aria-label="Strona główna">
            <img src="{{ asset('assets/images/logo-notesync.svg') }}" alt="Logo" />
            <span>NoteSync</span>
        </a>

        <nav class="links" aria-label="Nawigacja">
            <a class="link" href="#features">Funkcje</a>
            <a class="link" href="#screens">Zrzuty</a>
            <a class="link" href="#about">O nas</a>
            <a class="link" href="#faq">FAQ</a>
            <a class="link" href="#download">Pobierz</a>
        </nav>

        <div class="nav-cta">
            <a class="btn" href="#contact" id="contactBtn">Kontakt</a>
        </div>
    </div>
</div>

<!-- HERO -->
<header class="hero">
    <div class="container heroBox">
        <section>
            <div class="kicker">Android • Sync</div>
            <h1>Notuj. Porządkuj. Synchronizuj.</h1>
            <p class="lead">Szybka, lekka aplikacja do notatek z bezpieczną synchronizacją.</p>
            <a id="download" class="cta" href="#" rel="nofollow">Pobierz na Androida</a>
            <div class="card" style="margin-top:14px">
                <small>API: <code>{{ rtrim(config('app.url','https://notesync.pl'), '/') }}/api</code></small>
            </div>
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
    <div class="container">
        <div class="gridShots">
            <img class="shot" src="{{ asset('assets/images/app_light_1.png') }}" alt="Lista notatek" />
            <img class="shot" src="{{ asset('assets/images/app_light_2.png') }}" alt="Edycja notatki" />
            <img class="shot" src="{{ asset('assets/images/app_light_3.png') }}" alt="Zespoły" />
            <img class="shot" src="{{ asset('assets/images/app_light_4.png') }}" alt="Quiz ABCD" />
        </div>
    </div>
</main>

<!-- FEATURES -->
<section class="features" id="features">
    <div class="container fgrid">
        <article class="tile"><h3>Chmura</h3><p>Notatki zawsze pod ręką.</p></article>
        <article class="tile"><h3>Zespoły</h3><p>Współpraca w czasie rzeczywistym.</p></article>
        <article class="tile"><h3>Wiedza</h3><p>Quizy ABCD do utrwalania.</p></article>
        <article class="tile"><h3>Bezpieczeństwo</h3><p>Nowoczesne standardy ochrony.</p></article>
    </div>
</section>

<!-- ABOUT -->
<section class="about" id="about" aria-labelledby="about-title">
    <div class="container about-grid">
        <div class="card">
            <span class="badge">O nas</span>
            <h2 id="about-title" style="margin:10px 0 6px">Tworzymy NoteSync z myślą o szybkości i prostocie</h2>
            <p style="color:var(--muted); margin:0 0 10px">
                Jesteśmy małym zespołem, który łączy doświadczenie mobilne i backendowe. Dostarczamy niezawodne
                narzędzia do notowania, które nie przeszkadzają w pracy — tylko ją przyspieszają.
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
                <li><strong>Frontend (React Native):</strong> projekt UI/UX, offline-first, animacje i dostępność.</li>
                <li><strong>Backend (Laravel API):</strong> architektura, bezpieczeństwo, wydajna synchronizacja.</li>
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
                <button class="faq-q" aria-expanded="false">
                    <span>Czy NoteSync działa offline?</span>
                    <span class="faq-icon">＋</span>
                </button>
                <div class="faq-a">
                    <p>Tak. Tworzysz i edytujesz notatki bez internetu. Gdy tylko połączenie wróci — wszystko synchronizuje się automatycznie.</p>
                </div>
            </div>
            <div class="faq-item" role="listitem">
                <button class="faq-q" aria-expanded="false">
                    <span>Jak wygląda synchronizacja między urządzeniami?</span>
                    <span class="faq-icon">＋</span>
                </button>
                <div class="faq-a">
                    <p>Backend Laravel udostępnia REST API. Aplikacja mobilna (React Native) łączy się bezpiecznie, a zmiany są scalane według czasu i wersji.</p>
                </div>
            </div>
            <div class="faq-item" role="listitem">
                <button class="faq-q" aria-expanded="false">
                    <span>Czy moje dane są bezpieczne?</span>
                    <span class="faq-icon">＋</span>
                </button>
                <div class="faq-a">
                    <p>Stosujemy uwierzytelnianie tokenami (JWT), szyfrowanie w spoczynku i w tranzycie oraz najlepsze praktyki OWASP.</p>
                </div>
            </div>
            <div class="faq-item" role="listitem">
                <button class="faq-q" aria-expanded="false">
                    <span>Czy mogę współdzielić notatki z zespołem?</span>
                    <span class="faq-icon">＋</span>
                </button>
                <div class="faq-a">
                    <p>Tak. Twórz zespoły, zapraszaj współpracowników i udostępniaj foldery lub pojedyncze notatki z uprawnieniami.</p>
                </div>
            </div>
            <div class="faq-item" role="listitem">
                <button class="faq-q" aria-expanded="false">
                    <span>Czy jest tryb ciemny?</span>
                    <span class="faq-icon">＋</span>
                </button>
                <div class="faq-a">
                    <p>Interfejs wspiera preferencje systemowe. Strona i aplikacja zachowują spójny, czytelny wygląd w obu trybach.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CONTACT -->
<section class="contact" id="contact" aria-labelledby="contact-title">
    <div class="container">
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
    // FAQ: płynne otwieranie/zwijanie (nowoczesna, lekka animacja bez bibliotek)
    (function(){
        var items = document.querySelectorAll('.faq-item');
        items.forEach(function(it){
            var q = it.querySelector('.faq-q');
            var a = it.querySelector('.faq-a');
            q.addEventListener('click', function(){
                var isOpen = it.classList.contains('open');
                // zamknij wszystkie
                items.forEach(function(x){
                    x.classList.remove('open');
                    var xa = x.querySelector('.faq-a');
                    xa.style.maxHeight = 0;
                    x.querySelector('.faq-q').setAttribute('aria-expanded','false');
                });
                // otwórz bieżący
                if(!isOpen){
                    it.classList.add('open');
                    a.style.maxHeight = a.scrollHeight + 'px';
                    q.setAttribute('aria-expanded','true');
                }
            });
            // początkowa wysokość
            a.style.maxHeight = 0;
        });
    })();
</script>
</body>
</html>
