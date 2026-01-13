<style>
    /* --- HERO BASE STYLES --- */
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

    /* --- BUTTONS --- */
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

    /* --- EXPO MODAL STYLES (ULTRA MODERN) --- */
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
        transition: opacity 0.4s cubic-bezier(0.33, 1, 0.68, 1);
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
    }

    .expo-modal.open {
        opacity: 1;
        pointer-events: auto;
    }

    .modal-card {
        background: var(--bg2);
        border: 1px solid var(--border);
        border-radius: 32px;
        padding: 48px;
        width: 100%;
        max-width: 480px;
        text-align: center;
        box-shadow: 0 40px 80px -20px rgba(0, 0, 0, 0.5);
        transform: scale(0.95) translateY(10px);
        transition: transform 0.4s cubic-bezier(0.33, 1, 0.68, 1);
        position: relative;
        overflow: hidden;
    }

    .expo-modal.open .modal-card {
        transform: scale(1) translateY(0);
    }

    /* Desktop QR Styles */
    .qr-container {
        background: white;
        padding: 20px;
        border-radius: 24px;
        display: inline-block;
        margin-bottom: 24px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        position: relative;
    }

    .qr-container::after {
        content: '';
        position: absolute;
        inset: -2px;
        border-radius: 26px;
        background: linear-gradient(135deg, var(--primary), var(--accent));
        z-index: -1;
        opacity: 0.5;
    }

    .qr-container img {
        width: 200px;
        height: 200px;
        display: block;
    }

    /* Mobile "Sheet" Handle */
    .sheet-handle {
        display: none;
        width: 40px;
        height: 4px;
        background: var(--border);
        border-radius: 2px;
        margin: 0 auto 24px;
        opacity: 0.5;
    }

    /* Direct Button (Mobile) */
    .btn-expo-direct {
        display: none;
        width: 100%;
        margin-bottom: 16px;
        background: linear-gradient(135deg, #4630EB, #5b46f5);
        color: #fff;
        padding: 18px;
        border-radius: 20px;
        text-decoration: none;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-size: 16px;
        transition: transform 0.2s, box-shadow 0.2s;
        border: 1px solid rgba(255, 255, 255, 0.1);
        align-items: center;
        justify-content: center;
        box-shadow: 0 10px 30px -5px rgba(70, 48, 235, 0.5);
        position: relative;
        overflow: hidden;
    }

    /* Button Glow Effect */
    .btn-expo-direct::before {
        content: '';
        position: absolute;
        top: 0; left: -100%; width: 100%; height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
        animation: shimmer 3s infinite;
    }

    @keyframes shimmer {
        0% { left: -100%; }
        20% { left: 100%; }
        100% { left: 100%; }
    }

    .btn-expo-direct:active {
        transform: scale(0.96);
    }

    .close-btn {
        margin-top: 8px;
        width: 100%;
        padding: 16px;
        background: transparent;
        color: var(--fg-muted);
        border: none;
        cursor: pointer;
        font-weight: 600;
        transition: color 0.2s;
        font-size: 15px;
    }

    .close-btn:hover {
        color: var(--fg);
    }

    /* --- MOBILE SPECIFIC OVERRIDES (SHEET UI) --- */
    @media (max-width: 768px) {
        .qr-section { display: none; }
        .desktop-msg { display: none; }
        .btn-expo-direct { display: flex; }
        .mobile-msg { display: block !important; }
        .sheet-handle { display: block; }

        .expo-modal {
            align-items: flex-end; /* Align to bottom */
            padding: 0;
        }

        .modal-card {
            width: 100%;
            max-width: 100%;
            border-radius: 32px 32px 0 0; /* Sheet rounded top */
            padding: 24px 24px 48px 24px; /* Extra padding bottom for safe area */
            transform: translateY(100%);
            margin: 0;
            border-bottom: none;
            box-shadow: 0 -10px 40px rgba(0,0,0,0.3);
            background: var(--bg); /* Solid bg for performance or blurry */
        }

        @supports (backdrop-filter: blur(20px)) {
            .modal-card {
                background: rgba(10, 10, 10, 0.9);
                backdrop-filter: blur(20px) saturate(180%);
                -webkit-backdrop-filter: blur(20px) saturate(180%);
                border: 1px solid rgba(255,255,255,0.1);
                border-bottom: none;
            }
            html[data-theme="light"] .modal-card {
                background: rgba(255, 255, 255, 0.9);
            }
        }

        .expo-modal.open .modal-card {
            transform: translateY(0);
        }
    }

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
            <a href="{{ asset('assets/downloads/NoteSync_192.apk') }}" class="btn-premium btn-primary" download>
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
        <div class="sheet-handle"></div>

        <div class="qr-section">
            <div class="qr-container">
                <img src="{{ asset('assets/images/expogo-qr.png') }}" alt="QR Expo Code">
            </div>
            <h2 style="margin-bottom: 12px; font-weight: 800; font-size: 24px;">Skanuj i Testuj</h2>
            <p class="desktop-msg" style="color: var(--fg-muted); font-size: 16px; line-height: 1.6; margin-bottom: 24px;">
                Uruchom aparat lub aplikację <strong>Expo Go</strong> na iOS i zeskanuj kod,
                aby natychmiast załadować wersję deweloperską.
            </p>
        </div>

        <div class="mobile-msg" style="margin-bottom: 32px;">
            <div style="width: 60px; height: 60px; background: rgba(70, 48, 235, 0.1); border-radius: 16px; color: #4630EB; display: grid; place-items: center; margin: 0 auto 20px;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            </div>
            <h2 style="margin-bottom: 12px; font-weight: 800; font-size: 22px;">Uruchom Demo</h2>
            <p style="color: var(--fg-muted); font-size: 16px; line-height: 1.5;">
                Kliknij poniżej, aby otworzyć projekt bezpośrednio w aplikacji Expo Go na Twoim telefonie.
            </p>
        </div>

        <a href="exp://57.128.224.234:8081" class="btn-expo-direct">
            <svg class="btn-icon-svg" style="margin-right: 12px;" viewBox="0 0 24 24" fill="currentColor">
                <path d="M17.525 21.015L24 12L17.525 2.985H6.475L0 12L6.475 21.015H17.525Z"/>
            </svg>
            Otwórz w Expo Go
        </a>

        <button id="closeModal" class="close-btn">Anuluj</button>
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

        let touchStartY = 0;
        modal.addEventListener('touchstart', e => {
            touchStartY = e.changedTouches[0].screenY;
        }, {passive: true});

        modal.addEventListener('touchend', e => {
            const touchEndY = e.changedTouches[0].screenY;
            if (touchStartY < touchEndY - 50) {
                toggleModal(false);
            }
        }, {passive: true});
    })();
</script>
