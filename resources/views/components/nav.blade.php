@php
    $navItems = [
        ['name' => 'Home', 'path' => '#top', 'icon' => 'home'],
        ['name' => 'Funkcje', 'path' => '#features', 'icon' => 'zap'],
        ['name' => 'Ekrany', 'path' => '#screens', 'icon' => 'monitor'],
        ['name' => 'O nas', 'path' => '#about', 'icon' => 'users'],
        ['name' => 'Pytania', 'path' => '#faq', 'icon' => 'help-circle'],
    ];
@endphp

<style>
    .navbar {
        position: fixed; top: 24px; left: 0; right: 0;
        z-index: 1000; display: flex; justify-content: center;
        padding: 0 24px; pointer-events: none;
    }
    .nav-pill {
        pointer-events: auto;
        background: rgba(10, 10, 10, 0.4);
        backdrop-filter: blur(20px) saturate(160%);
        -webkit-backdrop-filter: blur(20px) saturate(160%);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 999px;
        padding: 6px 12px 6px 24px;
        display: flex; align-items: center; justify-content: space-between;
        width: 100%; max-width: 1100px;
        box-shadow: 0 20px 40px -5px rgba(0, 0, 0, 0.3);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }
    html[data-theme="light"] .nav-pill {
        background: rgba(255, 255, 255, 0.7);
        border-color: rgba(0,0,0,0.05);
    }

    .mm-active .theme-toggle {
        display: none !important;
    }

    .brand { font-weight: 700; font-size: 18px; display: flex; align-items: center; gap: 10px; color: var(--fg); text-decoration: none; }
    .brand span { font-family: 'Pacifico', cursive; font-size: 22px; }
    .brand-logo { width: 32px; height: auto; border-radius: 8px; transition: transform 0.3s ease; }

    .nav-links { display: flex; gap: 4px; }
    .nav-link {
        padding: 10px 18px; border-radius: 99px; font-size: 13px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.5px;
        color: var(--fg-muted); transition: all 0.2s ease; text-decoration: none;
    }
    .nav-link:hover { color: var(--fg); background: rgba(255,255,255,0.1); }
    html[data-theme="light"] .nav-link:hover { background: rgba(0,0,0,0.05); }

    .nav-actions { display: flex; align-items: center; gap: 12px; }

    .theme-toggle {
        width: 42px; height: 42px; border-radius: 50%;
        background: rgba(255, 255, 255, 0.05); border: 1px solid var(--border);
        color: var(--fg); cursor: pointer; display: grid; place-items: center;
        transition: all 0.3s ease;
    }
    html[data-theme="light"] .theme-toggle { background: rgba(0,0,0,0.03); }

    .sun-icon { display: block; } .moon-icon { display: none; }
    html[data-theme="light"] .sun-icon { display: none; }
    html[data-theme="light"] .moon-icon { display: block; }

    .burger {
        display: none; width: 42px; height: 42px; border-radius: 50%;
        background: var(--fg); color: var(--bg); border: none;
        cursor: pointer; position: relative; z-index: 1100;
    }
    .burger-icon { position: relative; width: 18px; height: 2px; background: currentColor; margin: 0 auto; }
    .burger-icon::before, .burger-icon::after { content: ""; position: absolute; left: 0; width: 100%; height: 2px; background: currentColor; }
    .burger-icon::before { top: -6px; } .burger-icon::after { top: 6px; }

    .mm {
        position: fixed; inset: 0; z-index: 1050;
        visibility: hidden; pointer-events: none;
    }
    .mm.is-open { visibility: visible; pointer-events: auto; }

    .mm__overlay {
        position: absolute; inset: 0;
        transition: opacity 0.5s ease;
        background: rgba(10, 10, 10, 0.8);
        backdrop-filter: blur(4px);
        opacity: 0;
    }
    html[data-theme="light"] .mm__overlay {
        background: rgba(240, 240, 240, 0.6);
        backdrop-filter: blur(6px);
    }
    .mm.is-open .mm__overlay { opacity: 1; }

    .mm__card {
        position: absolute; right: 24px; top: 24px;
        width: calc(100% - 48px); max-width: 360px;
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: 32px;
        box-shadow: 0 40px 80px rgba(0,0,0,0.4);
        transform: translateY(-20px) scale(0.95);
        opacity: 0;
        transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        padding-bottom: 20px;
    }
    .mm.is-open .mm__card { transform: translateY(0) scale(1); opacity: 1; }

    .mm__head {
        padding: 20px 24px; display: flex; align-items: center; justify-content: space-between;
    }
    .mm__title { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 2px; color: var(--fg-muted); }

    .mm__close {
        width: 36px; height: 36px; border-radius: 50%;
        background: var(--bg2); border: 1px solid var(--border);
        color: var(--fg); cursor: pointer; display: grid; place-items: center;
        transition: transform 0.3s ease;
    }
    .mm__close:hover { transform: rotate(90deg); }

    .mm__nav { padding: 0 12px; }
    .mm__item {
        display: flex; align-items: center; justify-content: space-between;
        padding: 16px 16px; border-radius: 16px; text-decoration: none;
        color: var(--fg); font-weight: 700; transition: 0.2s;
        margin-bottom: 2px;
    }
    .mm__item:hover { background: var(--bg2); }
    .mm__item-icon-left { margin-right: 14px; color: var(--primary); opacity: 0.8; }
    .mm__icon-wrap { opacity: 0.2; }

    @media(max-width: 900px) {
        .nav-links { display: none; }
        .burger { display: grid; place-items: center; }
    }
</style>

<nav class="navbar" id="top">
    <div class="nav-pill">
        <a href="#top" class="brand">
            <img src="{{ asset('logo.avif') }}" class="brand-logo" alt="NoteSync" />
            <span>NoteSync</span>
        </a>

        <div class="nav-links">
            @foreach($navItems as $item)
                <a href="{{ $item['path'] }}" class="nav-link">{{ $item['name'] }}</a>
            @endforeach
        </div>

        <div class="nav-actions">
            <button id="themeSwitcher" class="theme-toggle" aria-label="Zmień motyw">
                <svg class="sun-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                <svg class="moon-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
            </button>

            <button id="burgerBtn" class="burger">
                <div class="burger-icon"></div>
            </button>
        </div>
    </div>
</nav>

<div id="mobileMenu" class="mm">
    <div class="mm__overlay" onclick="toggleMenu()"></div>
    <div class="mm__card">
        <div class="mm__head">
            <span class="mm__title">Nawigacja</span>
            <button class="mm__close" onclick="toggleMenu()" aria-label="Zamknij">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <nav class="mm__nav">
            @foreach($navItems as $item)
                <a href="{{ $item['path'] }}" class="mm__item" onclick="toggleMenu()">
                    <div style="display: flex; align-items: center;">
                        <span class="mm__item-icon-left">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                @if($item['icon'] == 'home') <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                                @elseif($item['icon'] == 'zap') <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                                @elseif($item['icon'] == 'monitor') <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/>
                                @elseif($item['icon'] == 'users') <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                                @elseif($item['icon'] == 'help-circle') <circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                                @endif
                            </svg>
                        </span>
                        {{ $item['name'] }}
                    </div>
                    <span class="mm__icon-wrap">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="9 18 15 12 9 6"/></svg>
                    </span>
                </a>
            @endforeach
        </nav>
    </div>
</div>

<script>
    const themeSwitcher = document.getElementById('themeSwitcher');
    const burgerBtn = document.getElementById('burgerBtn');
    const mobileMenu = document.getElementById('mobileMenu');
    const pill = document.querySelector('.nav-pill');

    themeSwitcher.addEventListener('click', () => {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme') || 'dark';
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
    });

    function toggleMenu() {
        const isOpen = mobileMenu.classList.toggle('is-open');
        pill.classList.toggle('mm-active', isOpen);
        document.body.style.overflow = isOpen ? 'hidden' : '';
    }

    burgerBtn.addEventListener('click', toggleMenu);
</script>
