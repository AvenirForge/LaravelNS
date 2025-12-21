<style>
    .features { padding: 100px 0; background: var(--bg); }
    .bento-grid {
        display: grid;
        grid-template-columns: repeat(12, 1fr);
        gap: 20px;
        auto-rows: minmax(160px, auto);
    }

    .bento-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 28px;
        padding: 40px;
        display: flex; flex-direction: column; justify-content: space-between;
        transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        position: relative; overflow: hidden;
    }

    .bento-card:hover {
        border-color: var(--primary);
        transform: translateY(-8px);
        box-shadow: 0 20px 40px rgba(0,0,0,0.4), 0 0 20px color-mix(in srgb, var(--primary) 20%, transparent);
    }

    .bento-card h3 {
        font-size: 24px; font-weight: 700; margin-bottom: 12px;
        display: flex; align-items: center; gap: 12px;
        color: var(--fg);
    }
    .bento-card p { color: var(--fg-muted); font-size: 16px; line-height: 1.6; margin: 0; }

    .icon-box {
        width: 48px; height: 48px; border-radius: 12px;
        background: rgba(255,255,255,0.03);
        display: grid; place-items: center; margin-bottom: 24px;
        border: 1px solid var(--border);
    }
    .icon-box svg { width: 24px; height: 24px; stroke: var(--primary); }

    .bento-1 { grid-column: span 7; grid-row: span 2; }
    .bento-2 { grid-column: span 5; grid-row: span 2; }
    .bento-3 { grid-column: span 5; grid-row: span 2; }
    .bento-4 { grid-column: span 7; grid-row: span 2; }

    @media (max-width: 1024px) {
        .bento-grid { grid-template-columns: 1fr; }
        .bento-1, .bento-2, .bento-3, .bento-4 { grid-column: span 1; grid-row: auto; }
    }
</style>

<section class="features" id="features">
    <div class="container">
        <div class="bento-grid">
            <div class="bento-card bento-1" data-reveal>
                <div class="icon-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                </div>
                <div>
                    <h3>Synchronizacja Danych</h3>
                    <p>Architektura systemu zapewnia spójność danych pomiędzy aplikacją mobilną a bazą danych w chmurze przy wykorzystaniu interfejsu API.</p>
                </div>
            </div>

            <div class="bento-card bento-2" data-reveal style="transition-delay: 0.1s">
                <div class="icon-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                </div>
                <div>
                    <h3>Wieloplatformowość</h3>
                    <p>Zastosowane technologie pozwalają na uruchomienie interfejsu użytkownika na różnych systemach operacyjnych z zachowaniem pełnej responsywności.</p>
                </div>
            </div>

            <div class="bento-card bento-3" data-reveal style="transition-delay: 0.2s">
                <div class="icon-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div>
                    <h3>Struktura Uprawnień</h3>
                    <p>Zaawansowany system RBAC umożliwia tworzenie rozbudowanych grup z gradacją ról: od właściciela (Owner), przez administratorów, aż po moderatorów i użytkowników.</p>
                </div>
            </div>

            <div class="bento-card bento-4" data-reveal style="transition-delay: 0.3s">
                <div class="icon-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </div>
                <div>
                    <h3>Bezpieczeństwo Zasobów</h3>
                    <p>System kontroli dostępu weryfikuje uprawnienia do modyfikacji i odczytu zawartości na poziomie poszczególnych rekordów bazy danych oraz grup.</p>
                </div>
            </div>
        </div>
    </div>
</section>
