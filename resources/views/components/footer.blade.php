<style>
    .site-footer {
        position: relative;
        background-color: var(--bg);
        padding: 40px 0;
        margin-top: 80px;
        isolation: isolate;
    }

    .footer-divider {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 1px;
        background: linear-gradient(90deg,
        transparent 0%,
        var(--border) 10%,
        var(--border) 90%,
        transparent 100%
        );
    }

    .footer-wrapper {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
    }

    .footer-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .footer-brand {
        font-size: 18px;
        font-weight: 800;
        letter-spacing: -0.03em;
        color: var(--fg);
        text-decoration: none;
    }

    .footer-copy {
        font-size: 13px;
        /* Zmieniono z --fg-muted na kolor o wyższym kontraście */
        color: #A3A3A3;
        font-weight: 500;
        border-left: 1px solid var(--border);
        padding-left: 12px;
    }

    html[data-theme="light"] .footer-copy {
        color: #4B5563;
    }

    .scroll-up-subtle {
        width: 38px;
        height: 38px;
        background: transparent;
        border: 1px solid var(--border);
        border-radius: 10px;
        color: var(--fg);
        display: grid;
        place-items: center;
        cursor: pointer;
        transition: all 0.3s ease;
        flex-shrink: 0;
    }

    .scroll-up-subtle:hover {
        border-color: var(--fg);
        color: var(--primary);
        transform: translateY(-2px);
        background: var(--bg2);
    }

    .scroll-up-subtle svg {
        width: 18px;
        height: 18px;
    }

    @media (max-width: 640px) {
        .footer-copy {
            display: none;
        }

        .footer-brand {
            font-size: 16px;
        }
    }
</style>

<footer class="site-footer">
    <div class="footer-divider"></div>

    <div class="container footer-wrapper">
        <div class="footer-info">
            <a href="#top" class="footer-brand">NoteSync</a>
            <span class="footer-copy">
                © {{ now()->year }} Wszelkie prawa zastrzeżone.
            </span>
        </div>

        <button type="button" class="scroll-up-subtle" id="footerScrollTop" aria-label="Wróć na górę">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 15l-6-6-6 6"/>
            </svg>
        </button>
    </div>
</footer>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('footerScrollTop');
        const target = document.getElementById('top') || document.body;

        if (btn) {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            });
        }
    });
</script>
