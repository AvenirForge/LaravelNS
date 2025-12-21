<style>
    .hero {
        position: relative;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding-top: var(--nav);
        background-color: var(--bg);
        isolation: isolate;
        overflow: hidden;
    }

    .hero::before {
        content: "";
        position: absolute;
        inset: 0;
        z-index: -2;
        background-image: url('{{ asset('notesync-bg.avif') }}');
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        filter: var(--hero-img-filter, brightness(0.5) saturate(1.2));
        transition: filter 0.4s ease;
    }

    .hero::after {
        content: "";
        position: absolute;
        inset: 0;
        z-index: -1;
        background: linear-gradient(
            to bottom,
            var(--hero-grad-top, rgba(18, 18, 18, 0.7)) 0%,
            var(--hero-grad-mid, rgba(18, 18, 18, 0.3)) 50%,
            var(--bg) 100%
        );
        transition: background 0.4s ease;
    }

    html[data-theme="light"] .hero::before {
        --hero-img-filter: brightness(1.1) saturate(0.9) opacity(0.25);
    }

    html[data-theme="light"] .hero::after {
        --hero-grad-top: rgba(255, 255, 255, 0.6);
        --hero-grad-mid: rgba(255, 255, 255, 0.2);
    }

    .hero-box {
        position: relative;
        z-index: 2;
        max-width: 1000px;
        padding: 0 24px;
        width: 100%;
    }

    .hero h1 {
        font-size: clamp(40px, 8vw, 90px);
        font-weight: 900;
        letter-spacing: -0.05em;
        line-height: 0.9;
        margin-bottom: 28px;
        color: var(--fg);
    }

    .hero h1 span.accent {
        background: linear-gradient(135deg, #3b82f6, #10b981);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .hero .lead {
        font-size: clamp(16px, 2vw, 20px);
        color: var(--fg-muted);
        margin: 0 auto 50px;
        max-width: 700px;
        line-height: 1.6;
        font-weight: 400;
    }

    .cta-group {
        display: flex;
        gap: 20px;
        justify-content: center;
        flex-wrap: wrap;
    }

    .btn-premium {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 18px 38px;
        border-radius: 18px;
        font-weight: 700;
        font-size: 15px;
        letter-spacing: 0.3px;
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        cursor: pointer;
        text-decoration: none;
        overflow: hidden;
        border: none;
    }

    .btn-primary {
        background: var(--fg);
        color: var(--bg);
        box-shadow: 0 20px 40px -10px color-mix(in srgb, var(--fg) 40%, transparent);
    }

    .btn-primary:hover {
        transform: translateY(-5px) scale(1.02);
        box-shadow: 0 30px 60px -12px color-mix(in srgb, var(--fg) 50%, transparent);
    }

    .btn-secondary {
        background: color-mix(in srgb, var(--fg) 5%, transparent);
        color: var(--fg);
        border: 1px solid var(--border);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
    }

    .btn-secondary:hover {
        background: color-mix(in srgb, var(--fg) 10%, transparent);
        border-color: var(--fg);
        transform: translateY(-5px);
    }

    .btn-icon-svg {
        width: 20px;
        height: 20px;
        margin-right: 12px;
        flex-shrink: 0;
    }

    /* --- EXPO MODAL STYLES --- */
    .expo-modal {
        position: fixed;
        inset: 0;
        z-index: 2000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.3s ease;
        background: rgba(0,0,0,0.85);
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
    }

    .expo-modal.open {
        opacity: 1;
        pointer-events: auto;
    }

    .modal-card {
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: 35px;
        padding: 45px;
        width: 100%;
        max-width: 460px;
        text-align: center;
        box-shadow: 0 40px 100px -20px rgba(0,0,0,0.6);
        transform: scale(0.9);
        transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .expo-modal.open .modal-card {
        transform: scale(1);
    }

    .qr-container {
        background: white;
        padding: 20px;
        border-radius: 24px;
        display: inline-block;
        margin-bottom: 24px;
        box-shadow: 0 15px 35px rgba(0,0,0,0.2);
    }

    .qr-container img {
        width: 200px;
        height: 200px;
        display: block;
    }

    /* Domyślnie przycisk bezpośredni ukryty (Desktop) */
    .btn-expo-direct {
        display: none;
        width: 100%;
        margin-bottom: 12px;
        background: #4630EB; /* Expo Blue */
        color: #fff;
        padding: 16px;
        border-radius: 16px;
        text-decoration: none;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        transition: transform 0.2s, background 0.2s, box-shadow 0.2s;
        border: none;
        align-items: center;
        justify-content: center;
        box-shadow: 0 10px 25px -5px rgba(70, 48, 235, 0.4);
    }

    .btn-expo-direct:hover {
        background: #3b28c9;
        transform: translateY(-2px);
    }

    .btn-expo-direct:active {
        transform: scale(0.97);
    }

    .close-btn {
        margin-top: 8px;
        width: 100%;
        padding: 14px;
        background: transparent;
        color: var(--fg-muted);
        border: none;
        cursor: pointer;
        font-weight: 600;
        transition: color 0.2s;
        font-size: 14px;
    }

    .close-btn:hover {
        color: var(--fg);
        text-decoration: underline;
    }

    /* --- MOBILE LOGIC --- */
    /* Na urządzeniach mobilnych ukrywamy QR (bo nie zeskanujesz ekranu) i pokazujemy przycisk */
    @media (max-width: 768px) {
        .qr-section { display: none; }
        .desktop-msg { display: none; }
        .btn-expo-direct { display: flex; }
        .mobile-msg { display: block !important; }
    }

    /* Domyślnie ukrywamy wiadomość mobilną na desktopie */
    .mobile-msg { display: none; }
</style>

<header class="hero">
    <div class="hero-box">
        <h1 data-reveal>
            Cyfrowy minimalizm <br>
            <span class="accent">maksymalna produktywność.</span>
        </h1>

        <p class="lead" data-reveal style="transition-delay: 0.1s">
            NoteSync to narzędzie dla profesjonalistów, którzy wymagają od swoich notatek
            czegoś więcej niż tylko tekstu.
        </p>

        <div class="cta-group" data-reveal style="transition-delay: 0.2s">
            <a href="{{ asset('assets/downloads/NoteSync_185B.apk') }}" class="btn-premium btn-primary" download>
                <svg class="btn-icon-svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M17.523 15.3414c-.5511 0-.9993-.4486-.9993-.9997s.4482-.9997.9993-.9997c.5511 0 .9993.4486.9993.9997s-.4482.9997-.9993.9997m-11.046 0c-.5511 0-.9993-.4486-.9993-.9997s.4482-.9997.9993-.9997c.5511 0 .9993.4486.9993.9997s-.4482.9997-.9993.9997m11.4045-6.02l1.9973-3.4592a.416.416 0 00-.1521-.5676.416.416 0 00-.5676.1521l-2.0223 3.503C15.5902 8.4139 13.8533 8.0264 12 8.0264s-3.5902.3875-5.1367.9233L4.841 5.4467a.416.416 0 00-.5676-.1521.416.416 0 00-.1521.5676l1.9973 3.4592C2.6889 11.1859 0 14.4939 0 18.3542h24c0-3.8603-2.6889-7.1683-6.1185-9.0328"/>
                </svg>
                <span>Pobierz APK</span>
            </a>

            <button id="triggerModal" class="btn-premium btn-secondary">
                <svg class="btn-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="16 18 22 12 16 6"></polyline>
                    <polyline points="8 6 2 12 8 18"></polyline>
                </svg>
                <span>Przetestuj w Expo</span>
            </button>
        </div>
    </div>
</header>

<div id="expoModal" class="expo-modal">
    <div class="modal-card">
        <div class="qr-section">
            <div class="qr-container">
                <img src="{{ asset('assets/images/expogo-qr.png') }}" alt="QR Expo Code">
            </div>
            <h2 style="margin-bottom: 12px; font-weight: 800;">Zeskanuj telefonem</h2>
            <p class="desktop-msg" style="color: var(--fg-muted); font-size: 15px; line-height: 1.6; margin-bottom: 24px;">
                Otwórz aplikację <strong>Expo Go</strong> lub aparat na swoim telefonie i zeskanuj kod,
                aby natychmiast uruchomić wersję demonstracyjną NoteSync.
            </p>
        </div>

        <div class="mobile-msg" style="margin-bottom: 24px;">
            <h2 style="margin-bottom: 12px; font-weight: 800;">Uruchom Demo</h2>
            <p style="color: var(--fg-muted); font-size: 15px; line-height: 1.6;">
                Wykryto urządzenie mobilne. Kliknij poniżej, aby otworzyć projekt bezpośrednio w aplikacji Expo Go.
            </p>
        </div>

        <a href="exp://u.expo.dev/7624933a-67a8-4444-934c-6232537f597b?release-channel=default" class="btn-expo-direct">
            <svg class="btn-icon-svg" style="margin-right: 10px;" viewBox="0 0 24 24" fill="currentColor">
                <path d="M17.525 21.015L24 12L17.525 2.985H6.475L0 12L6.475 21.015H17.525Z"/>
            </svg>
            Otwórz w Expo Go
        </a>

        <button id="closeModal" class="close-btn">Zamknij</button>
    </div>
</div>

<script>
    (function() {
        const modal = document.getElementById('expoModal');
        const openBtn = document.getElementById('triggerModal');
        const closeBtn = document.getElementById('closeModal');

        const toggleModal = (state) => {
            modal.classList.toggle('open', state);
            document.body.style.overflow = state ? 'hidden' : '';
        };

        if (openBtn) openBtn.addEventListener('click', () => toggleModal(true));
        if (closeBtn) closeBtn.addEventListener('click', () => toggleModal(false));

        window.addEventListener('click', (e) => {
            if (e.target === modal) toggleModal(false);
        });
    })();
</script>
