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
        .nav{position:sticky; top:0; z-index:1000; height:var(--nav);
            display:flex; align-items:center; border-bottom:1px solid var(--border);
            backdrop-filter:saturate(140%) blur(10px);
            background:linear-gradient(180deg, rgba(0,0,0,.25), rgba(0,0,0,.06));
        }
        .nav-inner{display:flex; align-items:center; justify-content:space-between}
        .brand{display:flex; align-items:center; gap:10px}
        .brand img{width:36px; height:36px}
        .brand span{font-family:Pacifico,cursive; font-size:26px}
        .links{display:flex; gap:12px}
        .link{padding:10px 12px; border-radius:10px}
        .link:hover{background:rgba(255,255,255,.06)}
        @media (max-width:920px){ .links{display:none} }

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

        /* FEATURES (krótko i czytelnie) */
        .features{padding:22px 0 28px}
        .fgrid{display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px}
        .tile{padding:16px; border-radius:14px; background:var(--card); border:1px solid var(--border)}
        .tile h3{margin:6px 0 4px; font-size:18px}
        .tile p{margin:0; color:var(--muted)}

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
            <!-- placeholder logo -->
            <img src="{{ asset('assets/images/logo-notesync.svg') }}" alt="Logo" />
            <span>NoteSync</span>
        </a>
        <nav class="links" aria-label="Nawigacja">
            <a class="link" href="#features">Funkcje</a>
            <a class="link" href="#screens">Zrzuty</a>
            <a class="link" href="#download">Pobierz</a>
        </nav>
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
            <img class="shot"
                 alt="Lista notatek"
                 data-light-src="{{ asset('assets/images/app_light_1.png') }}"
                 data-dark-src="{{ asset('assets/images/app_dark_1.png') }}" />
            <img class="shot"
                 alt="Edycja"
                 data-light-src="{{ asset('assets/images/app_light_2.png') }}"
                 data-dark-src="{{ asset('assets/images/app_dark_2.png') }}" />
            <img class="shot"
                 alt="Zespoły"
                 data-light-src="{{ asset('assets/images/app_light_3.png') }}"
                 data-dark-src="{{ asset('assets/images/app_dark_3.png') }}" />
            <img class="shot"
                 alt="Quiz ABCD"
                 data-light-src="{{ asset('assets/images/app_light_4.png') }}"
                 data-dark-src="{{ asset('assets/images/app_dark_4.png') }}" />
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
    (function(){
        var mq = window.matchMedia("(prefers-color-scheme: dark)");
        function apply(){
            document.querySelectorAll('.shot').forEach(function(img){
                var src = mq.matches ? img.getAttribute('data-dark-src') : img.getAttribute('data-light-src');
                if (src && img.src !== src) img.src = src;
            });
        }
        apply();
        if (mq.addEventListener) mq.addEventListener('change', apply);
        else if (mq.addListener) mq.addListener(apply);
    })();
</script>
</body>
</html>
