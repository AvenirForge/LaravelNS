@php
    // NAPRAWA: Ustawienie języka na podstawie parametru URL lub domyślnego 'pl'
    // To zapewnia, że 'pl' jest domyślny, jeśli 'lang' nie jest ustawiony.
    $locale = request()->query('lang', config('app.fallback_locale', 'pl'));
    if (!in_array($locale, ['pl', 'en'])) {
        $locale = 'pl';
    }
    App::setLocale($locale);
@endphp
    <!doctype html
    <html lang="{{ str_replace('_', '-', App::getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>{{ __('messages.title') }}</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=Pacifico&display=swap" rel="stylesheet" />

    <style>
        :root{
            /* === PALETA APLIKACJI (DARK) === */
            --bg: #121212;
            --bg2: #1F1F1F;
            --bg3: #252525;
            --card: #1F1F1F;
            --border: #2C2C2C;
            --fg: #EAEAEA;
            --fg-muted: #808080;
            --fg-inverted: #1E1E1E;

            /* Akcenty (Dark) */
            --primary: #2c78a7;
            --text-on-primary: #EAEAEA;
            --ctaA: #0f1798;
            --ctaB: #2c78a7;
            --dangerA: #750303;
            --dangerB: #803434;

            /* UI */
            --bg-nav: rgba(18, 18, 18, .85); /* --bg z przezroczystością */
            --grid-line: rgba(255,255,255,.04);
            --shadow: 0 14px 48px rgba(0,0,0,.36);
            --blur-tint: 'dark';

            /* === STAŁE === */
            --radius: 16px;
            --nav: 78px;
            --container: 1400px;
            --blur: 16px;
        }

        html[data-theme="light"] {
            /* === PALETA APLIKACJI (LIGHT) === */
            --bg: #F5F7FA; /* secondaryBackground */
            --bg2: #FFFFFF; /* background */
            --bg3: #E8EBF0; /* thirdBackground */
            --card: #FFFFFF; /* background */
            --border: #E1E4E8;
            --fg: #1E1E1E;
            --fg-muted: #6E6E73;
            --fg-inverted: #EAEAEA;

            /* Akcenty (Light) */
            --primary: #1794e0;
            --text-on-primary: #FFFFFF;
            --ctaA: #7e9ace;
            --ctaB: #1794e0;
            --dangerA: #d38d89;
            --dangerB: #e53935;

            /* UI */
            --bg-nav: rgba(245, 247, 250, .85); /* --bg z przezroczystością */
            --grid-line: rgba(0,0,0,.04);
            --shadow: 0 12px 36px rgba(2,6,23,.12);
            --blur-tint: 'light';
        }

        @media (prefers-reduced-motion: reduce){ *{animation:none!important; transition:none!important} }

        *{box-sizing:border-box}
        html,body{
            height:100%;
            overflow-x:clip;
        }
        body{
            margin:0; font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial;
            -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
            color:var(--fg); background:var(--bg);
            transition: color .2s ease, background-color .2s ease;
        }
        a{color:inherit; text-decoration:none}
        img{display:block; max-width:100%; height:auto}
        .container{max-width:var(--container); margin:0 auto; padding:0 clamp(16px, 4vw, 26px)}
        .container-narrow{max-width:1160px; margin:0 auto; padding:0 clamp(16px, 4vw, 26px)}
        [id]{scroll-margin-top:calc(var(--nav) + 12px)}

        /* Globalne tło – dopasowane do motywu */
        .page-bg{position:relative; isolation:isolate;
            background:
                radial-gradient(1000px 500px at 10% -10%, color-mix(in srgb, var(--primary) 18%, transparent) 60%),
                radial-gradient(800px 420px at 110% -15%, color-mix(in srgb, var(--primary) 12%, transparent) 60%),
                linear-gradient(180deg, var(--bg) 0%, var(--bg2) 100%);
        }
        .page-bg::after{
            content:""; position:fixed; inset:0; z-index:-1; pointer-events:none;
            background:
                linear-gradient(to right, var(--grid-line) 1px, transparent 1px),
                linear-gradient(to bottom, var(--grid-line) 1px, transparent 1px);
            background-size:42px 42px;
            mask-image:radial-gradient(1200px 600px at 50% 0%, rgba(0,0,0,.45), transparent 75%);
        }

        /* NAVBAR */
        .nav{
            position:fixed; inset:0 0 auto 0; height:var(--nav); z-index:1000;
            display:flex; align-items:center;
            backdrop-filter:saturate(160%) blur(var(--blur));
            background:var(--bg-nav);
            border-bottom:1px solid var(--border);
            box-shadow:var(--shadow);
        }
        /* POPRAWKA: Zmiana z grid na flex dla lepszej kontroli na mobilnych */
        .nav-inner{
            display:flex; /* Zamiast grid */
            justify-content: space-between; /* Kluczowe dla mobilnych */
            align-items:center;
            gap:12px;
            width:100%
        }
        .brand{display:inline-flex; align-items:center; gap:10px; color:var(--fg);}
        .brand span{font-family:Pacifico,cursive; font-size:28px; letter-spacing:.2px; opacity:.95}
        .brand-logo{width:60px; height:auto; margin-right:4px; fill:var(--fg); transition:transform .25s ease, opacity .25s ease}
        .brand:hover .brand-logo{transform:rotate(-5deg) scale(1.05); opacity:.9}

        /* POPRAWKA: Użycie flex-grow dla .links, aby wycentrować je na desktopie */
        .links{
            display:flex;
            justify-content: center; /* Centrowanie linków */
            flex-grow: 1; /* Linki zajmują wolne miejsce */
            gap:18px
        }
        .link{padding:10px 12px; border-radius:10px; color:var(--fg); opacity:0.9;}
        .link:hover,.link:focus-visible{
            background:color-mix(in srgb, var(--fg) 10%, transparent);
            opacity: 1;
            outline:none
        }
        .nav-actions{
            display:flex;
            align-items:center;
            gap: 8px;
            /* justify-self:end; -- niepotrzebne przy flex */
        }
        .btn{
            display:inline-flex; gap:10px; align-items:center; padding:12px 18px; border-radius:12px;
            font-weight:800; letter-spacing:.2px;
            background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); color:var(--text-on-primary); border:0;
            box-shadow:0 8px 24px rgba(0,0,0,.35), inset 0 0 0 1px color-mix(in srgb, var(--text-on-primary) 25%, transparent);
            transition:transform .18s ease, filter .2s ease, box-shadow .2s ease;
            cursor: pointer;
        }
        .btn:hover{transform:translateY(-1px); filter:saturate(1.08); box-shadow:0 12px 34px rgba(0,0,0,.42), inset 0 0 0 1px color-mix(in srgb, var(--text-on-primary) 35%, transparent)}

        /* Przełącznik motywu (Desktop i Mobile) */
        .theme-toggle {
            display: grid;
            place-items: center;
            width: 44px; height: 44px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: color-mix(in srgb, var(--fg) 6%, transparent);
            color: var(--fg);
            cursor: pointer;
            padding: 0;
            transition: background-color .2s ease, border-color .2s ease;
        }
        .theme-toggle:hover {
            background: color-mix(in srgb, var(--fg) 10%, transparent);
        }
        .theme-toggle svg { width: 20px; height: 20px; }
        .icon-sun { display: none; }
        .icon-moon { display: block; }
        html[data-theme="light"] .icon-sun { display: block; }
        html[data-theme="light"] .icon-moon { display: none; }

        /* Przełącznik języka (Slider) */
        .lang-slider {
            position: relative;
            display: flex;
            height: 44px;
            width: 90px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: var(--bg3);
            padding: 3px;
        }
        .lang-slider-thumb {
            position: absolute;
            top: 3px;
            bottom: 3px;
            width: calc(50% - 4px); /* 4px to (padding*2 - margin) */
            left: 3px;
            background: var(--card);
            border-radius: 9px; /* Dopasowany do 12px */
            box-shadow: 0 2px 4px rgba(0,0,0,.2);
            transition: transform 0.3s cubic-bezier(0.23, 1, 0.32, 1);
            transform: translateX(0); /* Domyślnie PL */
        }
        .lang-slider.lang-en .lang-slider-thumb {
            transform: translateX(calc(100% + 2px)); /* Przesunięcie dla EN */
        }
        .lang-option {
            position: relative;
            z-index: 2;
            display: grid;
            place-items: center;
            flex: 1; /* Każdy zajmuje 50% */
            height: 100%;
            font-size: 14px;
            font-weight: 800;
            color: var(--fg-muted);
            transition: color 0.3s ease;
            text-decoration: none;
            text-transform: uppercase;
        }
        .lang-option.active {
            color: var(--fg);
        }


        /* Mobile */

        /* Animowany Burger */
        .burger{
            display:none; /* Ukryty domyślnie, widoczny na mobile */
            width:44px; height:44px; border-radius:12px;
            border:1px solid var(--border);
            background:color-mix(in srgb, var(--fg) 6%, transparent);
            color:var(--fg); cursor:pointer;
            padding: 10px; /* Wewnętrzny padding dla SVG */
        }
        .burger:hover {
            background: color-mix(in srgb, var(--fg) 10%, transparent);
        }
        .burger-svg {
            width: 100%; height: 100%;
            overflow: visible;
        }
        .burger-line {
            fill: none;
            stroke: var(--fg);
            stroke-width: 2.5;
            stroke-linecap: round;
            transition: transform 0.3s cubic-bezier(0.23, 1, 0.32, 1),
            opacity 0.2s ease;
            transform-origin: center center;
        }
        /* Stan "X" (otwarte menu) */
        .burger.open .burger-line1 {
            transform: translateY(7px) rotate(45deg);
        }
        .burger.open .burger-line2 {
            opacity: 0;
        }
        .burger.open .burger-line3 {
            transform: translateY(-7px) rotate(-45deg);
        }

        @media (max-width:980px){
            .links{display:none}
            .burger{display:grid} /* Pokazuje burger na mobile */
            /* POPRAWKA: Ukrywa przycisk, przełącznik języka i motywu */
            .nav-actions .btn,
            .nav-actions .lang-slider,
            .nav-actions .theme-toggle {
                display: none;
            }
        }

        /* POPRAWKA: Ukrywa tekst logo na najmniejszych ekranach */
        @media (max-width: 480px) {
            .brand span {
                display: none;
            }
        }

        .scrim{position:fixed; inset:var(--nav) 0 0 0; background:rgba(0,0,0,.55); backdrop-filter:blur(2px); opacity:0; pointer-events:none; transition:opacity .3s ease}
        .scrim.open{opacity:1; pointer-events:auto}

        /* Animacja panelu mobilnego */
        .mobile-panel{
            position:fixed; top:var(--nav); left:0; right:0; z-index:999;
            background:linear-gradient(180deg, var(--bg) 95%, var(--bg2));
            border-bottom:1px solid var(--border);
            box-shadow:0 30px 60px rgba(0,0,0,.45);

            /* Animacja slide-down */
            clip-path: inset(0 0 100% 0);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: transform 0.4s cubic-bezier(0.23, 1, 0.32, 1),
            opacity 0.3s ease,
            clip-path 0.4s cubic-bezier(0.23, 1, 0.32, 1),
            visibility 0.4s;
        }
        .mobile-panel.open{
            clip-path: inset(0 0 0 0);
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .mobile-links{display:grid; gap:10px; padding:18px 26px 22px}
        .mobile-links a{
            padding:14px; border-radius:12px; background:color-mix(in srgb, var(--fg) 9%, transparent);
            border:1px solid var(--border); color:var(--fg); font-weight:600;
            box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--fg) 6%, transparent);
        }

        /* POPRAWKA: Nowe style dla akcji w menu mobilnym */
        .mobile-actions {
            padding: 0 26px 22px; /* Dopasowany padding */
            margin-top: 10px;
            border-top: 1px solid var(--border);
            padding-top: 22px; /* Odstęp od kreski */
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .lang-slider-mobile {
            display: flex;
            justify-content: center;
            gap: 12px;
        }
        .lang-slider-mobile .lang-option {
            font-weight: 800;
            color: var(--fg-muted);
            font-size: 16px;
            text-transform: uppercase;
        }
        .lang-slider-mobile .lang-option.active {
            color: var(--fg);
        }
        .theme-toggle-mobile {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            background: color-mix(in srgb, var(--fg) 9%, transparent);
            border: 1px solid var(--border);
            color: var(--fg);
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
        }
        .theme-toggle-mobile svg {
            width: 20px;
            height: 20px;
        }
        /* Logika pokazywania ikon słońca/księżyca dla przycisku mobilnego */
        .theme-toggle-mobile .icon-sun { display: none; }
        .theme-toggle-mobile .icon-moon { display: block; }
        html[data-theme="light"] .theme-toggle-mobile .icon-sun { display: block; }
        html[data-theme="light"] .theme-toggle-mobile .icon-moon { display: none; }


        /* HERO (2/3 ekranu) */
        .hero{
            position:relative; padding:calc(var(--nav) + 28px) 0 48px; isolation:isolate;
            min-height:calc(66vh + var(--nav));
            display:flex; align-items:center;
        }
        .hero::before{
            content:""; position:absolute; inset:0; z-index:-2;
            background:
                radial-gradient(90% 50% at 80% 10%, color-mix(in srgb, var(--primary) 20%, transparent) 60%),
                linear-gradient(180deg, color-mix(in srgb, var(--bg) 86%, transparent) 0%, color-mix(in srgb, var(--bg) 78%, transparent) 60%, color-mix(in srgb, var(--bg) 90%, transparent) 100%),
                url('{{ asset('assets/images/ns-bg.jpg') }}') center/cover no-repeat;
            filter:saturate(1.02) contrast(1.03);
        }
        .heroBox{
            max-width:980px; margin-inline:auto;
            display:flex; flex-direction:column; align-items:center; gap:22px; text-align:center;
        }
        /* Kicker usunięty zgodnie z dostarczonym plikiem */
        h1{
            margin:6px 0 10px; font-weight:800; line-height:1.02; font-size:clamp(42px,6.2vw,74px);
            background:linear-gradient(180deg, var(--fg) 60%, color-mix(in srgb, var(--fg) 80%, var(--primary)) 100%);
            -webkit-background-clip:text; background-clip:text; color:transparent;
            text-shadow:0 2px 16px rgba(0,0,0,.35);
        }
        .lead{color:var(--fg-muted); font-size:clamp(16px,2.1vw,20px); margin:0 0 12px; text-shadow:0 1px 10px rgba(0,0,0,.25)}
        .cta{
            display:inline-flex; gap:10px; align-items:center; padding:14px 22px; border-radius:14px; font-weight:800;
            background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); color:var(--text-on-primary);
            box-shadow:0 14px 40px rgba(0,0,0,.35), inset 0 0 0 1px color-mix(in srgb, var(--text-on-primary) 28%, transparent);
            transition:transform .18s ease, box-shadow .2s ease, filter .2s ease;
        }
        .cta:hover{transform:translateY(-2px); filter:saturate(1.05); box-shadow:0 18px 48px rgba(0,0,0,.45), inset 0 0 0 1px color-mix(in srgb, var(--text-on-primary) 36%, transparent)}

        .card{background:var(--card); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow); padding:18px}

        /* SCREENSHOTS */
        .shots{padding:28px 0 6px}
        .gridShots{
            display:grid;
            grid-template-columns:repeat(3, 1fr);
            gap:22px;
            align-items:start;
        }
        @media (max-width:1024px){
            .gridShots{grid-template-columns:repeat(2, 1fr)}
        }
        @media (max-width:700px){
            .gridShots{
                grid-template-columns:1fr;
                justify-items:center;
            }
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
            font-weight:800; cursor:pointer; color:var(--text-on-primary);
            background:linear-gradient(90deg,var(--ctaA),var(--ctaB));
            box-shadow:0 10px 30px rgba(0,0,0,.45);
        }

        /* SECTIONS */
        .features{padding:32px 0 38px}
        .fgrid{display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:18px}
        .tile{padding:20px; border-radius:16px; background:var(--card); border:1px solid var(--border); transition:transform .18s ease, box-shadow .18s ease}
        .tile h3{margin:6px 0 6px; font-size:18px}
        .tile p{margin:0; color:var(--fg-muted)}
        .tile:hover{transform:translateY(-2px); box-shadow:0 14px 40px rgba(0,0,0,.25)}

        .about{padding:38px 0}
        .about-grid{display:grid; grid-template-columns:1.25fr .75fr; gap:22px}
        @media (max-width:980px){ .about-grid{grid-template-columns:1fr} }
        .badge{font-size:12px; font-weight:800; color:var(--text-on-primary); background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); display:inline-block; padding:6px 10px; border-radius:999px}
        .pill{display:inline-flex; align-items:center; gap:8px; padding:10px 12px; border-radius:12px; background:var(--bg); border:1px solid var(--border); font-weight:700}
        .stack{display:flex; gap:10px; flex-wrap:wrap; margin-top:10px}

        .faq{padding:26px 0 40px}
        .faq-list{display:grid; gap:12px}
        .faq-item{border:1px solid var(--border); border-radius:14px; background:var(--card); overflow:hidden}
        .faq-q{width:100%; text-align:left; background:transparent; color:var(--fg); padding:16px 18px; font-weight:800; border:0; cursor:pointer; display:flex; justify-content:space-between; align-items:center}
        .faq-a{max-height:0; overflow:hidden; transition:max-height .45s cubic-bezier(.25,.8,.25,1), opacity .35s ease; opacity:0; padding:0 18px}
        .faq-item.open .faq-a{opacity:1; padding:0 18px 16px}

        /* POPRAWKA: Styl dla ikony SVG + animacja */
        .faq-icon{
            transition:transform .35s ease;
            width: 20px;
            height: 20px;
            stroke: var(--fg);
            flex-shrink: 0; /* Zapobiega kurczeniu się ikony */
            margin-left: 12px;
        }
        .faq-item.open .faq-icon{transform:rotate(45deg)}

        .contact{padding:40px 0 60px}
        .form{display:grid; gap:12px}
        .row{display:grid; grid-template-columns:1fr 1fr; gap:12px}
        @media (max-width:720px){ .row{grid-template-columns:1fr} }
        .field{display:flex; flex-direction:column; gap:6px}
        .label{font-size:14px; color:var(--fg-muted)}
        .input,.textarea{background:var(--bg); border:1px solid var(--border); color:var(--fg); border-radius:12px; padding:12px 14px; outline:none}
        .input:focus,.textarea:focus{border-color:var(--primary)}
        .textarea{min-height:140px; resize:vertical}
        .form-actions{display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap}
        .hint{color:var(--fg-muted); font-size:12px}

        .lead-themed{color:var(--fg) !important}

        footer{border-top:1px solid var(--border); padding:28px 18px 44px; color:var(--fg-muted); position:relative; text-align:center}
        .top{position:absolute; right:18px; top:18px; width:42px; height:42px; display:grid; place-items:center; border-radius:999px; background:linear-gradient(90deg,var(--ctaA),var(--ctaB)); color:var(--text-on-primary); font-weight:900; box-shadow:var(--shadow)}
        .top:hover{transform:translateY(-2px)}
        /* NOWOŚĆ: Styl dla ikony SVG w przycisku .top */
        .top svg {
            width: 20px;
            height: 20px;
            stroke: var(--text-on-primary);
        }

        /* ====== DODATKI CTA + MODAL EXPO (RESPONSYWNE) ====== */
        .cta-stack{display:flex; flex-direction:column; gap:12px; width:100%; max-width:520px}
        .cta-icon{width:20px; height:20px; object-fit:contain}
        .cta-disabled{
            background:linear-gradient(90deg, rgba(200,210,224,.65), rgba(160,170,186,.65));
            color:rgba(8,16,33,.75); cursor:not-allowed;
            box-shadow:0 10px 28px rgba(0,0,0,.25), inset 0 0 0 1px rgba(255,255,255,.18);
        }
        .cta-disabled:hover{transform:none; filter:none; box-shadow:0 10px 28px rgba(0,0,0,.28), inset 0 0 0 1px rgba(255,255,255,.20)}
        .padlock{width:18px; height:18px; opacity:.85}

        /* Tooltip na desktop */
        .tooltip{position:relative}
        .tooltip[data-tip]::after{
            content:attr(data-tip);
            position:absolute; left:50%; transform:translateX(-50%) translateY(6px);
            bottom:-6px; background:var(--bg3); color:var(--fg); font-weight:700; font-size:12px;
            padding:8px 10px; border-radius:10px; border:1px solid var(--border);
            white-space:nowrap; pointer-events:none; opacity:0; transition:opacity .18s ease, transform .18s ease;
            box-shadow:0 12px 30px rgba(0,0,0,.35);
        }
        .tooltip:hover::after{opacity:1; transform:translateX(-50%) translateY(0)}

        /* Toast dla przycisku iOS na mobile */
        .mobile-toast {
            background: var(--bg3);
            color: var(--fg);
            padding: 10px 14px;
            border-radius: 10px;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
            margin-top: 10px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            animation: fadeIn .2s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Modal Expo GO */
        #expoModal{
            padding-top:max(16px, env(safe-area-inset-top));
            padding-right:max(16px, env(safe-area-inset-right));
            padding-bottom:max(16px, env(safe-area-inset-bottom));
            padding-left:max(16px, env(safe-area-inset-left));
            overscroll-behavior:contain;
        }
        .modal-card{
            position:relative; display:flex; flex-direction:column; align-items:center; gap:14px;
            background:linear-gradient(180deg, var(--bg) 92%, var(--bg2) 98%);
            border:1px solid var(--border); border-radius:16px; padding:18px;
            box-shadow:0 20px 60px rgba(0,0,0,.55), inset 0 0 0 1px color-mix(in srgb, var(--fg) 5%, transparent);
            backdrop-filter:blur(8px) saturate(130%);
            transform:translateY(8px) scale(.98); opacity:0;
            transition:opacity .25s ease, transform .25s ease;
            width:min(96vw,720px); max-height:calc(100dvh - 48px); overflow:auto; -webkit-overflow-scrolling:touch;
        }
        .lightbox.open .modal-card{ opacity:1; transform:translateY(0) scale(1); }

        .xbtn{
            position:absolute; top:10px; right:10px; width:40px; height:40px; border-radius:12px;
            border:1px solid var(--border); display:grid; place-items:center; cursor:pointer;
            background:linear-gradient(180deg, color-mix(in srgb, var(--fg) 12%, transparent), color-mix(in srgb, var(--fg) 6%, transparent));
            color:var(--fg); box-shadow:0 8px 22px rgba(0,0,0,.35), inset 0 0 0 1px color-mix(in srgb, var(--fg) 6%, transparent);
            transition:transform .18s ease, box-shadow .18s ease, filter .18s ease;
        }
        .xbtn:hover{ transform:rotate(90deg); filter:saturate(1.05); box-shadow:0 12px 28px rgba(0,0,0,.45) }
        .xbtn svg{width:20px; height:20px}

        .steps{width:100%; margin-top:6px}
        .steps-grid{display:grid; gap:10px; grid-template-columns:repeat(3, minmax(0,1fr))}
        @media (max-width:640px){ .steps-grid{grid-template-columns:1fr} }
        .step{
            display:flex; align-items:flex-start; gap:10px;
            padding:12px; border-radius:12px;
            background:color-mix(in srgb, var(--fg) 6%, transparent);
            border:1px solid var(--border);
            box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--fg) 4%, transparent);
            transition:transform .18s ease, box-shadow .18s ease, background .18s ease;
        }
        .step:hover{ transform:translateY(-1px); box-shadow:0 10px 28px rgba(0,0,0,.35) }
        .step-num{
            flex:0 0 auto; width:28px; height:28px; border-radius:999px;
            display:grid; place-items:center; font-weight:900; color:var(--text-on-primary);
            background:linear-gradient(90deg,var(--ctaA),var(--ctaB));
            box-shadow:0 6px 16px rgba(0,0,0,.35), inset 0 0 0 1px color-mix(in srgb, var(--text-on-primary) 22%, transparent);
        }
        .step-title{font-weight:800; margin:0; color:var(--fg)}
        .step-desc{margin:0; color:var(--fg-muted); font-size:12px}

        body.no-scroll{overflow:hidden}
        @supports(height:100dvh){ #expoModal{min-height:100dvh} }

        /* ====== Style dla animacji na scroll ====== */
        [data-reveal] {
            opacity: 0;
            transform: translateY(24px);
            transition: opacity 0.6s cubic-bezier(0.23, 1, 0.32, 1),
            transform 0.6s cubic-bezier(0.23, 1, 0.32, 1);
            will-change: opacity, transform; /* Wskazówka dla przeglądarki */
        }
        [data-reveal].is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* Opóźnienia dla siatek (efekt kaskadowy) */
        .gridShots > [data-reveal]:nth-child(2) { transition-delay: 0.1s; }
        .gridShots > [data-reveal]:nth-child(3) { transition-delay: 0.2s; }

        .fgrid > [data-reveal]:nth-child(2) { transition-delay: 0.1s; }
        .fgrid > [data-reveal]:nth-child(3) { transition-delay: 0.2s; }
        .fgrid > [data-reveal]:nth-child(4) { transition-delay: 0.3s; }

        /* Kaskada dla FAQ używa zmiennej --idx ustawionej w HTML */
        .faq-list > [data-reveal] {
            transition-duration: 0.5s;
            transition-delay: calc(0.08s * var(--idx, 0));
        }

    </style>

    <script>
        // Skrypt blokujący renderowanie, aby zapobiec FOUC (Flash of Unstyled Content)
        (function() {
            try {
                var theme = localStorage.getItem('theme');
                if (theme) {
                    document.documentElement.setAttribute('data-theme', theme);
                } else {
                    // Jeśli brak zapisanego motywu, użyj preferencji systemowych
                    var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
                }
            } catch (e) {
                // W razie błędu (np. localStorage niedostępny), zostaw domyślny 'dark'
            }
        })();
    </script>
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

    // Logika przełącznika języka
    $currentLocale = App::getLocale();
    $isEn = $currentLocale == 'en';
    $langSwitchUrlPl = url('?lang=pl');
    $langSwitchUrlEn = url('?lang=en');

    /* Tłumaczenia zrzutów */
    $shots = [
        ['file'=>'app_light_1', 'alt'=> __('messages.shots.alt1')],
        ['file'=>'app_light_2', 'alt'=> __('messages.shots.alt2')],
        ['file'=>'app_light_3', 'alt'=> __('messages.shots.alt3')],
    ];
    $expoQrImg = notesync_resolve_asset('expogo-qr');
@endphp

<div class="nav" role="banner">
    <div class="container nav-inner">
        <a class="brand" href="#top" aria-label="{{ __('messages.footer.home_aria') }}">
            <img src="/assets/images/logo-notesync.jpg" class="brand-logo" alt="" />
            <span>NoteSync</span>
        </a>
        <nav class="links" aria-label="Nawigacja główna">
            <a class="link" href="#features">{{ __('messages.nav.features') }}</a>
            <a class="link" href="#screens">{{ __('messages.nav.screens') }}</a>
            <a class="link" href="#about">{{ __('messages.nav.about') }}</a>
            <a class="link" href="#faq">{{ __('messages.nav.faq') }}</a>
            <a class="link" href="#download">{{ __('messages.nav.download') }}</a>
        </nav>

        <div class="nav-actions">
            {{-- Ten przycisk będzie ukryty na @media (max-width: 980px) --}}
            <a class="btn" href="#contact" id="contactBtn">{{ __('messages.nav.contact') }}</a>

            {{-- Ten przełącznik będzie ukryty na @media (max-width: 980px) --}}
            <div class="lang-slider @if($isEn) lang-en @endif">
                <span class="lang-slider-thumb"></span>
                <a href="{{ $langSwitchUrlPl }}" class="lang-option @if(!$isEn) active @endif" aria-label="{{ __('messages.lang_toggle_aria_pl') }}" lang="pl">PL</a>
                <a href="{{ $langSwitchUrlEn }}" class="lang-option @if($isEn) active @endif" aria-label="{{ __('messages.lang_toggle_aria_en') }}" lang="en">EN</a>
            </div>

            {{-- Ten przełącznik będzie ukryty na @media (max-width: 980px) --}}
            <button id="theme-toggle" class="theme-toggle" aria-label="{{ __('messages.theme_toggle_aria') }}">
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
            </button>

            {{-- Ten przycisk będzie WIDOCZNY tylko na @media (max-width: 980px) --}}
            <button id="burger" class="burger" aria-controls="mobilePanel" aria-expanded="false" aria-label="{{ __('messages.menu_aria') }}">
                <svg class="burger-svg" viewBox="0 0 24 24">
                    <path class="burger-line burger-line1" d="M 3,6 H 21" />
                    <path class="burger-line burger-line2" d="M 3,12 H 21" />
                    <path class="burger-line burger-line3" d="M 3,18 H 21" />
                </svg>
            </button>
        </div>
    </div>

    <div id="navScrim" class="scrim" aria-hidden="true"></div>
    <div id="mobilePanel" class="mobile-panel" role="region" aria-label="Menu mobilne">
        <div class="mobile-links">
            <a href="#features">{{ __('messages.nav.features') }}</a>
            <a href="#screens">{{ __('messages.nav.screens') }}</a>
            <a href="#about">{{ __('messages.nav.about') }}</a>
            <a href="#faq">{{ __('messages.nav.faq') }}</a>
            <a href="#download">{{ __('messages.nav.download') }}</a>
            <a href="#contact">{{ __('messages.nav.contact') }}</a>
        </div>

        <div class="mobile-actions">
            <div class="lang-slider-mobile">
                <a href="{{ $langSwitchUrlPl }}" class="lang-option @if(!$isEn) active @endif" lang="pl">PL</a>
                <a href="{{ $langSwitchUrlEn }}" class="lang-option @if($isEn) active @endif" lang="en">EN</a>
            </div>
            <button id="theme-toggle-mobile" class="theme-toggle-mobile">
                {{-- Ikony skopiowane z przycisku desktopowego --}}
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                <span>{{ __('messages.theme_toggle_aria') }}</span>
            </button>
        </div>
    </div>
</div>

<header class="hero" role="region" aria-label="Sekcja główna">
    <div class="container heroBox">
        <h1 data-reveal>{!! __('messages.hero.title') !!}</h1>
        <p class="lead" data-reveal style="transition-delay: 0.1s;">{{ __('messages.hero.lead') }}</p>

        <div class="cta-stack" data-reveal style="transition-delay: 0.2s;" aria-label="Opcje pobrania">
            <a id="download" class="cta" href="#download" rel="nofollow">
                <img class="cta-icon" src="{{ asset('assets/images/android.svg') }}" alt="" aria-hidden="true">
                {{ __('messages.hero.cta_android') }}
            </a>

            <a id="download-ios"
               class="cta cta-disabled tooltip"
               href="#"
               aria-disabled="true"
               data-tip="{{ __('messages.hero.cta_ios_tooltip') }}"
               title="{{ __('messages.hero.cta_ios_tooltip') }}">
                <img class="cta-icon" src="{{ asset('assets/images/ios.svg') }}" alt="" aria-hidden="true">
                {{ __('messages.hero.cta_ios') }}
                <svg class="padlock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="3.5" y="11" width="17" height="9" rx="2" ry="2" />
                    <path d="M7 11V8a5 5 0 0 1 10 0v3" />
                </svg>
            </a>
            {{-- Kontener na dynamiczny toast mobilny pojawi się tutaj --}}

            <a id="expogoBtn" class="cta" href="#" rel="nofollow" aria-haspopup="dialog" aria-controls="expoModal">
                <img class="cta-icon" src="{{ asset('assets/images/expogo.svg') }}" alt="" aria-hidden="true">
                {{ __('messages.hero.cta_expo') }}
            </a>
        </div>
    </div>
</header>

<main class="shots" id="screens">
    <div class="container">
        <div class="gridShots">
            @foreach($shots as $s)
                @php $src = notesync_resolve_asset($s['file']); @endphp
                <button class="shot-btn" data-full="{{ $src }}" aria-label="{{ __('messages.shots.aria_label_prefix') }} {{ $s['alt'] }}" data-reveal>
                    <img class="shot" src="{{ $src }}" alt="{{ $s['alt'] }}" loading="lazy" decoding="async"/>
                </button>
            @endforeach
        </div>
    </div>
</main>

<section class="features" id="features">
    <div class="container fgrid">
        <article class="tile" data-reveal><h3 >{{ __('messages.features.f1_title') }}</h3><p>{{ __('messages.features.f1_desc') }}</p></article>
        <article class="tile" data-reveal><h3 >{{ __('messages.features.f2_title') }}</h3><p>{{ __('messages.features.f2_desc') }}</p></article>
        <article class="tile" data-reveal><h3 >{{ __('messages.features.f3_title') }}</h3><p>{{ __('messages.features.f3_desc') }}</p></article>
        <article class="tile" data-reveal><h3 >{{ __('messages.features.f4_title') }}</h3><p>{{ __('messages.features.f4_desc') }}</p></article>
    </div>
</section>

<section class="about" id="about" aria-labelledby="about-title">
    <div class="container about-grid">
        <div class="card" data-reveal>
            <span class="badge">{{ __('messages.about.badge') }}</span>
            <h2 id="about-title" style="margin:10px 0 6px">{{ __('messages.about.title') }}</h2>
            <p style="color:var(--fg-muted); margin:0 0 10px">
                {{ __('messages.about.desc') }}
            </p>
            <div class="stack">
                <span class="pill">{{ __('messages.about.stack1') }}</span>
                <span class="pill">{{ __('messages.about.stack2') }}</span>
                <span class="pill">{{ __('messages.about.stack3') }}</span>
                <span class="pill">{{ __('messages.about.stack4') }}</span>
            </div>
        </div>
        <div class="card" data-reveal style="transition-delay: 0.1s;">
            <h3 style="margin:0 0 8px">{{ __('messages.about.team_title') }}</h3>
            <ul style="list-style:none;margin:0;padding:0;display:grid;gap:10px">
                <li>{!! __('messages.about.team_li1') !!}</li>
                <li>{!! __('messages.about.team_li2') !!}</li>
                <li>{!! __('messages.about.team_li3') !!}</li>
            </ul>
        </div>
    </div>
</section>

<section class="faq" id="faq" aria-labelledby="faq-title">
    <div class="container">
        <h2 id="faq-title" style="margin:0 0 10px" data-reveal>{{ __('messages.faq.title') }}</h2>
        <div class="faq-list" role="list">
            <div class="faq-item" role="listitem" data-reveal style="--idx: 0">
                <button class="faq-q" aria-expanded="false">
                    <span>{{ __('messages.faq.q1') }}</span>
                    <svg class="faq-icon" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                </button>
                <div class="faq-a"><p>{{ __('messages.faq.a1') }}</p></div>
            </div>
            <div class="faq-item" role="listitem" data-reveal style="--idx: 1">
                <button class="faq-q" aria-expanded="false">
                    <span>{{ __('messages.faq.q2') }}</span>
                    <svg class="faq-icon" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                </button>
                <div class="faq-a"><p>{{ __('messages.faq.a2') }}</p></div>
            </div>
            <div class="faq-item" role="listitem" data-reveal style="--idx: 2">
                <button class="faq-q" aria-expanded="false">
                    <span>{{ __('messages.faq.q3') }}</span>
                    <svg class="faq-icon" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                </button>
                <div class="faq-a"><p>{{ __('messages.faq.a3') }}</p></div>
            </div>
            <div class="faq-item" role="listitem" data-reveal style="--idx: 3">
                <button class="faq-q" aria-expanded="false">
                    <span>{{ __('messages.faq.q4') }}</span>
                    <svg class="faq-icon" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                </button>
                <div class="faq-a"><p>{{ __('messages.faq.a4') }}</p></div>
            </div>
        </div>
    </div>
</section>

<section class="contact" id="contact" aria-labelledby="contact-title">
    <div class="container">
        <div class="card" data-reveal>
            <h2 id="contact-title" style="margin:0 0 8px">{{ __('messages.contact.title') }}</h2>
            <p class="lead lead-themed" style="margin:0 0 16px">{{ __('messages.contact.lead') }}</p>
            <form class="form" action="#" method="post" novalidate>
                @csrf
                <div class="row">
                    <div class="field">
                        <label class="label" for="name">{{ __('messages.contact.label_name') }}</label>
                        <input class="input" type="text" id="name" name="name" placeholder="{{ __('messages.contact.placeholder_name') }}" autocomplete="name" required>
                    </div>
                    <div class="field">
                        <label class="label" for="email">{{ __('messages.contact.label_email') }}</label>
                        <input class="input" type="email" id="email" name="email" placeholder="{{ __('messages.contact.placeholder_email') }}" autocomplete="email" required>
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="message">{{ __('messages.contact.label_message') }}</label>
                    <textarea class="textarea" id="message" name="message" placeholder="{{ __('messages.contact.placeholder_message') }}" required></textarea>
                </div>
                <div class="form-actions">
                    <label style="display:flex; align-items:center; gap:10px; color:var(--fg-muted); font-size:14px;">
                        <input type="checkbox" required style="transform:translateY(1px)">
                        {{ __('messages.contact.label_consent') }}
                    </label>
                    <button class="btn" type="submit">{{ __('messages.contact.btn_submit') }}</button>
                </div>
                <div class="hint">{{ __('messages.contact.hint') }}</div>
            </form>
        </div>
    </div>
</section>

<footer>
    <a class="top" href="#top" aria-label="{{ __('messages.footer.top_aria') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="18 15 12 9 6 15"></polyline>
        </svg>
    </a>
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
      'description' => __('messages.hero.lead'), // Użycie tłumaczenia
    ];
@endphp
<script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) !!}</script>

<script>
    // Przekazanie tłumaczeń z PHP do JS
    const translations = {
        lightboxClose: "{{ __('messages.lightbox.close_text') }}",
        lightboxCloseAria: "{{ __('messages.lightbox.close_aria') }}",
        lightboxAlt: "{{ __('messages.lightbox.image_alt') }}",
        iosToast: "{{ __('messages.ios_toast.message') }}",
        expoModalAria: "{{ __('messages.expo.modal_aria') }}",
        expoCloseAria: "{{ __('messages.expo.close_aria') }}",
        expoQrAlt: "{{ __('messages.expo.qr_alt') }}",
        expoScanText: "{!! __('messages.expo.scan_text') !!}",
        expoStep1Title: "{{ __('messages.expo.step1_title') }}",
        expoStep1Desc: "{{ __('messages.expo.step1_desc') }}",
        expoStep2Title: "{{ __('messages.expo.step2_title') }}",
        expoStep2Desc: "{{ __('messages.expo.step2_desc') }}",
        expoStep3Title: "{{ __('messages.expo.step3_title') }}",
        expoStep3Desc: "{{ __('messages.expo.step3_desc') }}",
    };

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

    /* Burger + scrim (z animacją CSS) */
    (function(){
        var burger = document.getElementById('burger');
        var panel  = document.getElementById('mobilePanel');
        var scrim  = document.getElementById('navScrim');
        if(!burger || !panel || !scrim) return;
        var open = false;

        function setState(state){
            open = state;
            // Przełączanie klas kontrolowanych przez CSS
            panel.classList.toggle('open', open);
            scrim.classList.toggle('open', open);
            burger.classList.toggle('open', open); // Kontroluje animację ikony
            burger.setAttribute('aria-expanded', String(open));

            // POPRAWKA: Blokowanie scrolla na body
            document.body.classList.toggle('no-scroll', open);
        }

        burger.addEventListener('click', function(){ setState(!open); });
        scrim.addEventListener('click', function(){ setState(false); });

        // Kliknięcie linku (<a>) w menu zamyka panel.
        panel.querySelectorAll('a').forEach(function(a){
            a.addEventListener('click', function(){ setState(false); });
        });
        document.addEventListener('keydown', function(e){ if(e.key==='Escape' && open) setState(false); });
    })();

    /* LIGHTBOX */
    (function(){
        var container = document.createElement('div');
        container.id = 'lightbox';
        container.className = 'lightbox';
        // Użycie tłumaczeń JS
        container.innerHTML = '<button class="lightbox-close" aria-label="' + translations.lightboxCloseAria + '">' + translations.lightboxClose + '</button>' +
            '<img id="lightboxImg" alt="' + translations.lightboxAlt + '"/>';
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

    /* POPRAWKA: Przełącznik motywów (dla obu przycisków) */
    (function() {
        // Wspólna funkcja do zmiany motywu
        function toggleTheme() {
            var currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
            var newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            try {
                localStorage.setItem('theme', newTheme);
            } catch (err) {
                console.warn('Nie można zapisać motywu w localStorage.');
            }
        }

        // Znajdź oba przyciski
        var desktopToggleBtn = document.getElementById('theme-toggle');
        var mobileToggleBtn = document.getElementById('theme-toggle-mobile'); // Nowy ID

        // Dodaj listener do przycisku desktopowego
        if (desktopToggleBtn) {
            desktopToggleBtn.addEventListener('click', toggleTheme);
        }

        // Dodaj listener do przycisku mobilnego
        if (mobileToggleBtn) {
            mobileToggleBtn.addEventListener('click', toggleTheme);
        }
    })();

    /* CTA iOS (disabled) + MODAL Expo GO */
    (function(){
        var iosBtn = document.getElementById('download-ios');
        var clickCount = 0;
        var existingToast = null;
        var isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;

        if(iosBtn){
            iosBtn.addEventListener('click', function(e){
                e.preventDefault();

                if (isTouch) {
                    clickCount++;
                    if (clickCount === 1) {
                        if (existingToast) existingToast.remove();
                        var toast = document.createElement('div');
                        toast.className = 'mobile-toast';
                        toast.textContent = translations.iosToast; // Użycie tłumaczenia JS
                        iosBtn.parentNode.insertBefore(toast, iosBtn.nextSibling);
                        existingToast = toast;
                    } else if (clickCount === 2) {
                        if (existingToast) {
                            existingToast.remove();
                            existingToast = null;
                        }
                        clickCount = 0;
                    }
                }
            });
        }

        // Modal Expo GO
        var expoModal = document.createElement('div');
        expoModal.id = 'expoModal';
        expoModal.className = 'lightbox';
        expoModal.setAttribute('role','dialog');
        expoModal.setAttribute('aria-modal','true');
        expoModal.setAttribute('aria-label', translations.expoModalAria); // Użycie tłumaczenia JS

        var expoQr = @json($expoQrImg);

        expoModal.innerHTML =
            '<div class="modal-card" id="expoCard">' +
            '<button class="xbtn" id="expoX" aria-label="' + translations.expoCloseAria + '">' + // Użycie tłumaczenia JS
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
            '<path d="M6 6l12 12M6 18L18 6"/>' +
            '</svg>' +
            '</button>' +
            '<img src="'+ expoQr +'" alt="' + translations.expoQrAlt + '" style="max-width:min(86vw,460px);height:auto;border-radius:12px;background:#fff"/>' + // Użycie tłumaczenia JS
            '<div style="color:var(--fg);font-weight:700;text-align:center">' + translations.expoScanText + '</div>' + // Użycie tłumaczenia JS
            '<div class="steps">' +
            '<div class="steps-grid">' +
            '<div class="step">' +
            '<div class="step-num">1</div>' +
            '<div><p class="step-title">' + translations.expoStep1Title + '</p><p class="step-desc">' + translations.expoStep1Desc + '</p></div>' + // Użycie tłumaczenia JS
            '</div>' +
            '<div class="step">' +
            '<div class="step-num">2</div>' +
            '<div><p class="step-title">' + translations.expoStep2Title + '</p><p class="step-desc">' + translations.expoStep2Desc + '</p></div>' + // Użycie tłumaczenia JS
            '</div>' +
            '<div class="step">' +
            '<div class="step-num">3</div>' +
            '<div><p class="step-title">' + translations.expoStep3Title + '</p><p class="step-desc">' + translations.expoStep3Desc + '</p></div>' + // Użycie tłumaczenia JS
            '</div>' +
            '</div>' +
            '</div>' +
            '</div>';

        document.body.appendChild(expoModal);

        function closeModal(){
            document.body.classList.remove('no-scroll');
            expoModal.classList.remove('open');
            var opener = document.getElementById('expogoBtn');
            if(opener){ opener.focus({preventScroll:true}); }
        }

        expoModal.addEventListener('click', function(e){ if(e.target === expoModal) closeModal(); });
        document.addEventListener('keydown', function(e){ if(e.key==='Escape' && expoModal.classList.contains('open')) closeModal(); });

        var expoBtn = document.getElementById('expogoBtn');
        if(expoBtn){
            expoBtn.addEventListener('click', function(e){
                e.preventDefault();
                document.body.classList.add('no-scroll');
                expoModal.classList.add('open');
                setTimeout(function(){
                    var xb = document.getElementById('expoX');
                    if(xb){ xb.addEventListener('click', closeModal); xb.focus({preventScroll:true}); }
                }, 0);
            });
        }
    })();

    /* ====== Animacje na scroll (Intersection Observer) ====== */
    (function(){
        // Sprawdź, czy użytkownik nie preferuje zredukowanego ruchu
        var prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (prefersReducedMotion) {
            // Jeśli tak, pokaż od razu wszystkie elementy
            document.querySelectorAll('[data-reveal]').forEach(el => {
                el.classList.add('is-visible');
            });
            return; // Zakończ skrypt
        }

        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    obs.unobserve(entry.target); // Animuj tylko raz
                }
            });
        }, {
            threshold: 0.1, // Uruchom, gdy 10% elementu jest widoczne
            rootMargin: "0px 0px -50px 0px" // Uruchom trochę później (50px od dolnej krawędzi)
        });

        // Znajdź wszystkie elementy do animowania
        document.querySelectorAll('[data-reveal]').forEach(el => {
            observer.observe(el);
        });
    })();
</script>
</body>
</html>
