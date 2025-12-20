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
        gap: 16px;
        justify-content: center;
        flex-wrap: wrap;
    }

    .btn-premium {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 16px 36px;
        border-radius: 14px;
        font-weight: 600;
        font-size: 14px;
        letter-spacing: 0.3px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        text-decoration: none;
        overflow: hidden;
    }

    .btn-primary {
        background: var(--fg);
        color: var(--bg);
        border: 1px solid var(--fg);
    }

    .btn-primary:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 24px -8px color-mix(in srgb, var(--fg) 50%, transparent);
        filter: brightness(0.9);
    }

    /* NAPRAWA: Wymuszenie jasnego koloru ikony na ciemnym przycisku Androida */
    .btn-primary .btn-icon img {
        filter: invert(1) brightness(2);
    }
    /* Jeśli w trybie dark var(--bg) jest ciemny, ikona powinna wrócić do naturalnego/odwróconego stanu pasującego do tła */
    html[data-theme="dark"] .btn-primary .btn-icon img {
        filter: none;
    }

    .btn-secondary {
        background: rgba(255, 255, 255, 0.03);
        color: var(--fg);
        border: 1px solid var(--border);
        backdrop-filter: blur(10px);
    }

    .btn-secondary:hover {
        background: var(--bg2);
        border-color: var(--fg-muted);
        transform: translateY(-3px);
    }

    .btn-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 20px;
        margin-right: 12px;
    }

    .btn-icon img {
        transform: scale(0.5);
        display: block;
    }

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
        background: rgba(0,0,0,0.8);
        backdrop-filter: blur(12px);
    }

    .expo-modal.open {
        opacity: 1;
        pointer-events: auto;
    }

    .modal-card {
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: 30px;
        padding: 40px;
        width: 100%;
        max-width: 480px;
        text-align: center;
        box-shadow: 0 30px 60px -12px rgba(0,0,0,0.5);
    }

    .qr-container {
        background: white;
        padding: 20px;
        border-radius: 20px;
        display: inline-block;
        margin-bottom: 24px;
    }

    .qr-container img {
        width: 200px;
        height: 200px;
        display: block;
    }

    .close-btn {
        margin-top: 24px;
        width: 100%;
        padding: 12px;
        background: var(--bg2);
        border: 1px solid var(--border);
        color: var(--fg);
        border-radius: 12px;
        cursor: pointer;
        font-weight: 600;
    }
</style>

<header class="hero">
    <div class="hero-box">
        <h1 data-reveal>
            Cyfrowy minimalizm <br>
            <span class="accent">maksymalna produktywność.</span>
        </h1>

        <p class="lead" data-reveal style="transition-delay: 0.1s">
            NoteSync to narzędzie dla profesjonalistów, którzy wymagają od swoich notatek
            czegoś więcej niż tylko tekstu. Bezpieczeństwo, szybkość i styl w jednej funkcjonalnej aplikacji.
        </p>

        <div class="cta-group" data-reveal style="transition-delay: 0.2s">
            <a href="{{ asset('assets/downloads/NoteSync_185B.apk') }}" class="btn-premium btn-primary" download>
                <div class="btn-icon">
                    <img src="{{ asset('assets/images/android.svg') }}" alt="Android">
                </div>
                <span>Pobierz aplikację</span>
            </a>

            <button id="triggerModal" class="btn-premium btn-secondary">
                <div class="btn-icon">
                    <img src="{{ asset('assets/images/expogo.svg') }}" alt="Expo" class="invert-on-light">
                </div>
                <span>Przetestuj w Expo</span>
            </button>
        </div>
    </div>
</header>

<div id="expoModal" class="expo-modal">
    <div class="modal-card">
        <div class="qr-container">
            <img src="{{ asset('assets/images/expogo-qr.png') }}" alt="QR Expo">
        </div>
        <h2 style="margin-bottom: 12px;">Skanuj i działaj</h2>
        <p style="color: var(--fg-muted); font-size: 14px; line-height: 1.5;">
            Otwórz aplikację <strong>Expo GO</strong> na swoim smartfonie i zeskanuj powyższy kod,
            aby uruchomić wersję demonstracyjną NoteSync w czasie rzeczywistym.
        </p>
        <button id="closeModal" class="close-btn">Zamknij okno</button>
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
