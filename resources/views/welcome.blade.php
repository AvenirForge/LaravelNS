@extends('layouts.app')

@section('title', 'NoteSync — Twoje notatki zawsze pod ręką')
@section('meta_description', 'NoteSync to szybka i bezpieczna aplikacja do tworzenia, organizacji i synchronizacji notatek.')
@section('og:title', 'NoteSync — Twoje notatki zawsze pod ręką')
@section('og:description', 'Prosta, intuicyjna i zawsze dostępna aplikacja do notatek.')

@section('head_extra')
    <style>
        .hero{padding:56px 0 12px}
        .hero-grid{display:grid;gap:26px;align-items:center;grid-template-columns:1.08fr .92fr}
        .hero-aside{padding:18px}
        .bullet{display:grid;gap:10px;list-style:none;padding:0;margin:0}
        .bullet li{display:flex;gap:10px;align-items:flex-start}
        .dot{width:10px;height:10px;border-radius:50%;margin-top:6px;background:radial-gradient(circle at 30% 30%, var(--accent), var(--accent2))}
        .glass{position:relative;overflow:hidden;background:radial-gradient(1200px 140px at 10% -40px, rgba(255,255,255,.07), transparent), var(--card);box-shadow:var(--shadow-lg)}
        .screens{padding:20px 0 0}
        .shots{display:flex;gap:14px;justify-content:center;align-items:flex-start;flex-wrap:wrap}
        .shot{width:min(240px,40vw);border-radius:18px;border:6px solid rgba(255,255,255,.08);box-shadow:var(--shadow);object-fit:contain;transition:transform .45s cubic-bezier(.25,.46,.45,.94), box-shadow .25s, filter .25s;animation-duration:4s;animation-iteration-count:infinite;animation-direction:alternate;filter:saturate(1.03) contrast(1.02)}
        @keyframes floatA {0%{transform:translateY(0)}50%{transform:translateY(-10px)}100%{transform:translateY(0)}}
        @keyframes floatB {0%{transform:translateY(0)}50%{transform:translateY(10px)}100%{transform:translateY(0)}}
        .shot:nth-child(odd){animation-name:floatA}
        .shot:nth-child(even){animation-name:floatB;animation-delay:.4s}
        .shot:hover{transform:scale(1.06);animation-play-state:paused}
        .features{padding:8px 0 24px}
        .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}
        .col{grid-column:span 6}
        .col-sm{grid-column:span 12}
        .tile{padding:16px;border-radius:16px;background:var(--card);border:1px solid var(--border)}
        .tile h3{margin:8px 0 6px;font-size:18px}
        .tile p{margin:0;color:var(--muted)}
        .cta{padding:12px 0 48px;text-align:center}
        .cta .btn-primary{font-size:18px;padding:14px 18px}
        @media(max-width:980px){.hero-grid{grid-template-columns:1fr}}
    </style>
@endsection

@section('content')
    <!-- HERO -->
    <header class="hero">
        <div class="container">
            <div class="hero-grid">
                <section>
                    <div class="kicker">Android • Synchronizacja • Zespoły</div>
                    <h1>Uporządkuj myśli z NoteSync</h1>
                    <p class="lead">Prosta, szybka i zawsze dostępna aplikacja do tworzenia, organizowania i bezpiecznej synchronizacji notatek między urządzeniami.</p>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:18px">
                        <a class="btn btn-primary" id="download" href="#" rel="nofollow" aria-label="Pobierz aplikację NoteSync na Androida">Pobierz z Google Play</a>
                        <a class="btn btn-ghost" href="#features">Dlaczego NoteSync?</a>
                    </div>
                    <div class="card glass" style="margin-top:16px; padding:20px">
                        <strong>Endpoint API:</strong>
                        <code>{{ rtrim(config('app.url', 'https://notesync.pl'), '/') }}/api</code><br />
                        <small style="color:var(--muted)">Autoryzacja: Bearer token / Sanctum</small>
                    </div>
                </section>

                <aside class="card glass hero-aside" aria-label="Szybkie informacje">
                    <ul class="bullet">
                        <li><span class="dot"></span><div><strong>Tryb offline</strong> — notuj, a my zsynchronizujemy, gdy wróci sieć.</div></li>
                        <li><span class="dot"></span><div><strong>Zespoły</strong> — udostępniaj notatki i pracuj wspólnie.</div></li>
                        <li><span class="dot"></span><div><strong>Testy ABCD</strong> — utrwalaj wiedzę interaktywnymi quizami.</div></li>
                        <li><span class="dot"></span><div><strong>Bezpieczeństwo</strong> — Twoje dane należą do Ciebie.</div></li>
                    </ul>
                </aside>
            </div>
        </div>
    </header>

    <!-- SCREENSHOTS -->
    <section class="screens" id="screens" aria-labelledby="screenshots-heading">
        <div class="container">
            <h2 id="screenshots-heading">Zobacz, jak to działa</h2>
            <div class="shots">
                <img class="shot" alt="Lista notatek — NoteSync"
                     data-light-src="{{ asset('assets/images/app_light_1.png') }}"
                     data-dark-src="{{ asset('assets/images/app_dark_1.png') }}" />
                <img class="shot" alt="Edycja notatki — NoteSync"
                     data-light-src="{{ asset('assets/images/app_light_2.png') }}"
                     data-dark-src="{{ asset('assets/images/app_dark_2.png') }}" />
                <img class="shot" alt="Zespoły — NoteSync"
                     data-light-src="{{ asset('assets/images/app_light_3.png') }}"
                     data-dark-src="{{ asset('assets/images/app_dark_3.png') }}" />
                <img class="shot" alt="Testy ABCD — NoteSync"
                     data-light-src="{{ asset('assets/images/app_light_4.png') }}"
                     data-dark-src="{{ asset('assets/images/app_dark_4.png') }}" />
            </div>
        </div>
    </section>

    <!-- FEATURES -->
    <section class="features" id="features">
        <div class="container">
            <h2>Dlaczego NoteSync?</h2>
            <div class="grid">
                <div class="col col-sm">
                    <article class="tile" aria-label="Synchronizacja w chmurze">
                        <h3>Synchronizacja w chmurze</h3>
                        <p>Prześlij notatkę i miej ją zawsze przy sobie — na każdym urządzeniu.</p>
                    </article>
                </div>
                <div class="col col-sm">
                    <article class="tile" aria-label="Zespoły i udostępnianie">
                        <h3>Zespoły i udostępnianie</h3>
                        <p>Wbudowany system zespołów do współdzielenia notatek.</p>
                    </article>
                </div>
                <div class="col col-sm">
                    <article class="tile" aria-label="Testy ABCD">
                        <h3>Testy ABCD</h3>
                        <p>Sprawdzony sposób na utrwalanie wiedzy i naukę.</p>
                    </article>
                </div>
                <div class="col col-sm">
                    <article class="tile" aria-label="Prywatność i bezpieczeństwo">
                        <h3>Prywatność i bezpieczeństwo</h3>
                        <p>Ty decydujesz, co i komu udostępniasz — reszta zostaje u Ciebie.</p>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="cta">
        <div class="container">
            <p class="lead" style="margin:8px 0 16px">Gotowy, by zacząć porządkować notatki?</p>
            <a class="btn btn-primary" href="#" rel="nofollow">Pobierz na Androida</a>
        </div>
    </section>
@endsection

@section('body_end')
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
          'description' => 'Aplikacja do tworzenia i synchronizacji notatek z funkcjami zespołów i testów ABCD.',
        ];
    @endphp
    <script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) !!}</script>

    <script>
        (function(){
            var prefersDark = window.matchMedia("(prefers-color-scheme: dark)");
            function applyThemeImages(){
                document.querySelectorAll('.shot').forEach(function(img){
                    var src = prefersDark.matches ? img.getAttribute('data-dark-src') : img.getAttribute('data-light-src');
                    if (src && img.src !== src) { img.src = src; }
                });
            }
            applyThemeImages();
            if (prefersDark.addEventListener) { prefersDark.addEventListener('change', applyThemeImages); }
            else if (prefersDark.addListener)   { prefersDark.addListener(applyThemeImages); }
        })();
    </script>
@endsection
