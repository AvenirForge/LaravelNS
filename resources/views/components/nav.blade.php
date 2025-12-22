@php
    $navItems = [
        ['name' => 'Home', 'path' => '#top', 'icon' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'],
        ['name' => 'Funkcje', 'path' => '#features', 'icon' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>'],
        ['name' => 'Ekrany', 'path' => '#screens', 'icon' => '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/>'],
        ['name' => 'O nas', 'path' => '#about', 'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>'],
        ['name' => 'Pytania', 'path' => '#faq', 'icon' => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>'],
    ];
@endphp

<style>
    .navbar {
        position: absolute;
        top: 24px;
        left: 0;
        right: 0;
        z-index: 1000;
        display: flex;
        justify-content: center;
        padding: 0 24px;
        pointer-events: none;
        height: 60px;
    }

    .nav-pill {
        pointer-events: auto;
        background: rgba(10, 10, 10, 0.6);
        backdrop-filter: blur(16px) saturate(180%);
        -webkit-backdrop-filter: blur(16px) saturate(180%);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 999px;
        padding: 0 8px 0 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        max-width: 1000px;
        height: 100%;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        transition: transform 0.3s ease, background-color 0.3s ease;
    }

    html[data-theme="light"] .nav-pill {
        background: rgba(255, 255, 255, 0.85);
        border-color: rgba(0, 0, 0, 0.06);
        box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.1);
    }

    .brand {
        font-weight: 800;
        font-size: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        color: var(--fg);
        text-decoration: none;
        letter-spacing: -0.02em;
    }

    .brand-logo {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        object-fit: cover;
    }

    .nav-links {
        display: flex;
        gap: 4px;
        height: 100%;
        align-items: center;
    }

    .nav-link {
        padding: 8px 16px;
        border-radius: 99px;
        font-size: 13px;
        font-weight: 600;
        color: var(--fg-muted);
        transition: all 0.2s ease;
        text-decoration: none;
        letter-spacing: 0.02em;
    }

    .nav-link:hover {
        color: var(--fg);
        background: rgba(255, 255, 255, 0.08);
    }

    html[data-theme="light"] .nav-link:hover {
        background: rgba(0, 0, 0, 0.05);
    }

    .nav-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        padding-left: 12px;
    }

    .icon-btn {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: transparent;
        border: 1px solid transparent;
        color: var(--fg-muted);
        cursor: pointer;
        display: grid;
        place-items: center;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        padding: 0;
    }

    .icon-btn:hover {
        background: rgba(255, 255, 255, 0.1);
        color: var(--fg);
    }

    html[data-theme="light"] .icon-btn:hover {
        background: rgba(0, 0, 0, 0.05);
    }

    .sun-icon { display: block; }
    .moon-icon { display: none; }

    html[data-theme="light"] .sun-icon { display: none; }
    html[data-theme="light"] .moon-icon { display: block; }

    .burger {
        display: none;
        margin-right: 4px;
    }

    .burger-line {
        width: 20px;
        height: 2px;
        background-color: currentColor;
        transition: transform 0.3s ease;
    }

    .burger-line + .burger-line {
        margin-top: 5px;
    }

    .mm {
        position: fixed;
        inset: 0;
        z-index: 1050;
        visibility: hidden;
        pointer-events: none;
    }

    .mm.is-open {
        visibility: visible;
        pointer-events: auto;
    }

    .mm-overlay {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(8px);
        opacity: 0;
        transition: opacity 0.4s ease;
    }

    .mm.is-open .mm-overlay {
        opacity: 1;
    }

    .mm-drawer {
        position: absolute;
        top: 24px;
        right: 24px;
        width: calc(100% - 48px);
        max-width: 320px;
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: 24px;
        padding: 24px;
        transform: translateY(-10px) scale(0.95);
        opacity: 0;
        transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 20px 40px rgba(0,0,0,0.4);
    }

    .mm.is-open .mm-drawer {
        transform: translateY(0) scale(1);
        opacity: 1;
    }

    .mm-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        padding-bottom: 16px;
        border-bottom: 1px solid var(--border);
    }

    .mm-title {
        font-size: 14px;
        font-weight: 700;
        color: var(--fg);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .mm-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .mm-link {
        display: flex;
        align-items: center;
        padding: 12px;
        color: var(--fg-muted);
        font-weight: 600;
        text-decoration: none;
        border-radius: 12px;
        transition: 0.2s;
    }

    .mm-link svg {
        margin-right: 12px;
        color: var(--primary);
        opacity: 0.8;
    }

    .mm-link:active, .mm-link:hover {
        background: var(--bg2);
        color: var(--fg);
    }

    @media (max-width: 768px) {
        .nav-links { display: none; }
        .burger { display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .navbar { padding: 0 16px; }
        .nav-pill { padding: 0 8px 0 16px; }
    }
</style>

<nav class="navbar" id="navbar">
    <div class="nav-pill">
        <a href="#top" class="brand">
            <img src="{{ asset('logo.avif') }}" class="brand-logo" alt="NoteSync Home" loading="lazy" width="28" height="28" />
            <span>NoteSync</span>
        </a>

        <div class="nav-links">
            @foreach($navItems as $item)
                <a href="{{ $item['path'] }}" class="nav-link">{{ $item['name'] }}</a>
            @endforeach
        </div>

        <div class="nav-actions">
            <button id="themeToggle" class="icon-btn" aria-label="Przełącz motyw">
                <svg class="sun-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                <svg class="moon-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
            </button>

            <button id="menuToggle" class="icon-btn burger" aria-label="Otwórz menu" aria-expanded="false">
                <div class="burger-line"></div>
                <div class="burger-line"></div>
            </button>
        </div>
    </div>
</nav>

<div id="mobileMenu" class="mm">
    <div class="mm-overlay" id="menuOverlay"></div>
    <div class="mm-drawer">
        <div class="mm-header">
            <span class="mm-title">Nawigacja</span>
            <button id="menuClose" class="icon-btn" aria-label="Zamknij menu">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <nav class="mm-list">
            @foreach($navItems as $item)
                <a href="{{ $item['path'] }}" class="mm-link js-nav-link">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $item['icon'] !!}</svg>
                    {{ $item['name'] }}
                </a>
            @endforeach
        </nav>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const themeBtn = document.getElementById('themeToggle');
        const menuBtn = document.getElementById('menuToggle');
        const menuClose = document.getElementById('menuClose');
        const menuOverlay = document.getElementById('menuOverlay');
        const mobileMenu = document.getElementById('mobileMenu');
        const html = document.documentElement;

        const toggleTheme = () => {
            const isDark = html.getAttribute('data-theme') === 'dark';
            const newTheme = isDark ? 'light' : 'dark';
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);

            themeBtn.style.transform = 'rotate(180deg)';
            setTimeout(() => themeBtn.style.transform = 'rotate(0deg)', 300);
        };

        if(themeBtn) themeBtn.addEventListener('click', toggleTheme);

        const toggleMenu = (show) => {
            const isOpen = show ?? !mobileMenu.classList.contains('is-open');
            mobileMenu.classList.toggle('is-open', isOpen);
            menuBtn.setAttribute('aria-expanded', isOpen);
            document.body.style.overflow = isOpen ? 'hidden' : '';
        };

        if(menuBtn) menuBtn.addEventListener('click', () => toggleMenu(true));
        if(menuClose) menuClose.addEventListener('click', () => toggleMenu(false));
        if(menuOverlay) menuOverlay.addEventListener('click', () => toggleMenu(false));

        document.querySelectorAll('.js-nav-link').forEach(link => {
            link.addEventListener('click', () => toggleMenu(false));
        });
    });
</script>
