<style>
    .about {
        padding: 100px 0;
        background: transparent;
    }
    .about-grid {
        display: grid;
        grid-template-columns: 1.3fr 0.7fr;
        gap: 32px;
        align-items: stretch;
    }
    @media (max-width: 980px) {
        .about-grid { grid-template-columns: 1fr; }
    }

    .card-premium {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 32px;
        padding: 48px;
        box-shadow: var(--shadow);
        transition: transform 0.3s ease, border-color 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    .card-premium:hover {
        border-color: var(--primary);
        transform: translateY(-5px);
    }

    .badge-biz {
        font-size: 11px;
        font-weight: 900;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        color: white;
        background: linear-gradient(135deg, var(--primary), #3b82f6);
        padding: 8px 16px;
        border-radius: 12px;
        display: inline-block;
        margin-bottom: 24px;
        box-shadow: 0 10px 20px -5px rgba(59, 130, 246, 0.4);
    }

    .about h2 {
        font-size: clamp(32px, 4vw, 48px);
        font-weight: 800;
        line-height: 1.1;
        margin-bottom: 24px;
        letter-spacing: -1px;
    }

    .pill-tech {
        display: inline-flex;
        align-items: center;
        padding: 10px 18px;
        border-radius: 14px;
        background: var(--bg2);
        border: 1px solid var(--border);
        font-size: 14px;
        font-weight: 700;
        color: var(--fg);
        transition: all 0.2s ease;
    }
    .pill-tech:hover {
        background: var(--fg);
        color: var(--bg);
        transform: scale(1.05);
    }

    .team-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: grid;
        gap: 16px;
    }
    .team-item {
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 600;
        color: var(--fg-muted);
    }
    .team-item svg {
        color: var(--primary);
        width: 20px;
        height: 20px;
    }
</style>

<section class="about" id="about">
    <div class="container-ultra-wide about-grid">
        <div class="card-premium" data-reveal>
            <span class="badge-biz">Filozofia Produktu</span>
            <h2>Minimalizm, który napędza <span class="accent">Twoją wydajność.</span></h2>
            <p style="font-size: 18px; line-height: 1.6; color: var(--fg-muted); margin-bottom: 32px;">
                NoteSync narodził się z ambicji stworzenia środowiska wolnego od dystrakcji. Projektujemy narzędzia klasy biznesowej, które inteligentnie wspierają proces twórczy, pozostając niemal niezauważalne w codziennej pracy.
            </p>
            <div class="stack" style="display: flex; gap: 12px; flex-wrap: wrap;">
                <span class="pill-tech">React Native</span>
                <span class="pill-tech">Laravel</span>
                <span class="pill-tech">Blade</span>
                <span class="pill-tech">Expo GO</span>
            </div>
        </div>

        <div class="card-premium" data-reveal style="transition-delay: 0.2s; display: flex; flex-direction: column; justify-content: center;">
            <h3 style="font-size: 24px; font-weight: 800; margin-bottom: 24px;">Kompetencje</h3>
            <ul class="team-list">
                <li class="team-item">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                    Architektura UI/UX
                </li>
                <li class="team-item">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                    High-End Mobile Dev
                </li>
                <li class="team-item">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                    Global Infrastructure
                </li>
                <li class="team-item">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                    Data Security Protocol
                </li>
            </ul>
        </div>
    </div>
</section>
