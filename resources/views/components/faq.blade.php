<style>
    .faq {
        padding: 100px 0;
        background: transparent;
    }
    .faq-title-area {
        text-align: center;
        max-width: 700px;
        margin: 0 auto 60px;
    }
    .faq-list-biz {
        display: grid;
        gap: 16px;
        max-width: 900px;
        margin: 0 auto;
    }
    .faq-item-biz {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 24px;
        overflow: hidden;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .faq-item-biz.open {
        border-color: var(--primary);
        box-shadow: 0 20px 40px -10px rgba(0,0,0,0.1);
        background: var(--bg);
    }

    .faq-q-biz {
        width: 100%;
        padding: 24px 32px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: transparent;
        border: none;
        cursor: pointer;
        color: var(--fg);
        font-size: 18px;
        font-weight: 700;
        text-align: left;
    }

    .faq-a-biz {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.5s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
        opacity: 0;
    }
    .faq-item-biz.open .faq-a-biz {
        opacity: 1;
    }
    .faq-a-inner {
        padding: 0 32px 32px;
        color: var(--fg-muted);
        line-height: 1.6;
        font-size: 16px;
    }

    .faq-icon-biz {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--bg2);
        display: grid;
        place-items: center;
        transition: all 0.4s ease;
        flex-shrink: 0;
    }
    .faq-item-biz.open .faq-icon-biz {
        background: var(--primary);
        color: white;
        transform: rotate(135deg);
    }
</style>

<section class="faq" id="faq">
    <div class="container">
        <div class="faq-title-area" data-reveal>
            <h2 style="font-size: clamp(32px, 5vw, 56px); font-weight: 800; margin-bottom: 16px;">Centrum <span class="accent">Wsparcia.</span></h2>
            <p style="color: var(--fg-muted); font-size: 18px;">Poznaj szczegóły techniczne i funkcjonalne NoteSync.</p>
        </div>

        <div class="faq-list-biz">
            @php
                $faqs = [
                    ['q' => 'Czy model dystrybucji przewiduje opłaty?', 'a' => 'NoteSync oferuje bezpłatną infrastrukturę dla użytkowników indywidualnych. Wersje Enterprise z rozszerzonym protokołem kolaboracji podlegają indywidualnej wycenie biznesowej.'],
                    ['q' => 'Jak realizowana jest synchronizacja danych?', 'a' => 'Wykorzystujemy autorski silnik Delta-Sync, który analizuje binarne zmiany w plikach i przesyła wyłącznie zmodyfikowane pakiety, co redukuje opóźnienia do absolutnego minimum.'],
                    ['q' => 'Czy dane są dostępne poza ekosystemem?', 'a' => 'Zdecydowanie. Wspieramy otwarte standardy danych. Możesz w dowolnym momencie wygenerować pełny eksport w formatach Markdown, PDF lub JSON via API.'],
                    ['q' => 'Na jakich platformach operuje NoteSync?', 'a' => 'Aplikacja jest natywnie skompilowana dla systemów Android oraz iOS. Oferujemy również w pełni responsywną instancję Web dla pracy desktopowej.']
                ];
            @endphp

            @foreach($faqs as $index => $item)
                <div class="faq-item-biz" data-reveal style="--idx: {{ $index }}">
                    <button class="faq-q-biz" aria-expanded="false">
                        <span>{{ $item['q'] }}</span>
                        <div class="faq-icon-biz">
                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="3"><path d="M12 5v14M5 12h14"></path></svg>
                        </div>
                    </button>
                    <div class="faq-a-biz">
                        <div class="faq-a-inner">
                            {{ $item['a'] }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

<script>
    (function(){
        document.querySelectorAll('.faq-item-biz').forEach(item => {
            const btn = item.querySelector('.faq-q-biz');
            const content = item.querySelector('.faq-a-biz');

            btn.addEventListener('click', () => {
                const isOpen = item.classList.contains('open');

                // Zamknij inne (opcjonalnie - usuń jeśli chcesz otwierać wiele na raz)
                document.querySelectorAll('.faq-item-biz').forEach(other => {
                    if (other !== item) {
                        other.classList.remove('open');
                        other.querySelector('.faq-a-biz').style.maxHeight = '0px';
                        other.querySelector('.faq-q-biz').setAttribute('aria-expanded', 'false');
                    }
                });

                if (isOpen) {
                    item.classList.remove('open');
                    content.style.maxHeight = '0px';
                    btn.setAttribute('aria-expanded', 'false');
                } else {
                    item.classList.add('open');
                    content.style.maxHeight = content.scrollHeight + 'px';
                    btn.setAttribute('aria-expanded', 'true');
                }
            });
        });
    })();
</script>
