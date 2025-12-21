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
        transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .faq-item-biz.open {
        border-color: var(--primary);
        box-shadow: 0 20px 40px -10px rgba(0,0,0,0.1);
        background: var(--bg2);
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
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: var(--bg);
        border: 1px solid var(--border);
        display: grid;
        place-items: center;
        transition: all 0.4s ease;
        flex-shrink: 0;
        color: var(--fg-muted);
    }
    .faq-item-biz.open .faq-icon-biz {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
        transform: rotate(135deg);
        box-shadow: 0 0 15px var(--primary-glow);
    }
</style>

<section class="faq" id="faq">
    <div class="container">
        <div class="faq-title-area" data-reveal>
            <h2 style="font-size: clamp(32px, 5vw, 56px); font-weight: 800; margin-bottom: 16px;">System <span class="accent">Wiedzy.</span></h2>
            <p style="color: var(--fg-muted); font-size: 18px;">Kluczowe informacje o architekturze, bezpieczeństwie i dostępności NoteSync.</p>
        </div>

        <div class="faq-list-biz">
            @php
                $faqs = [
                    [
                        'q' => 'Jak działa system grup i uprawnień?',
                        'a' => 'NoteSync opiera się na hierarchicznej strukturze grup. Każda grupa posiada właściciela (Owner), który może nadawać role Administratora, Moderatora lub Użytkownika. Pozwala to na precyzyjne zarządzanie dostępem do wspólnych zasobów i notatek wewnątrz dużych zespołów.'
                    ],
                    [
                        'q' => 'W jaki sposób zabezpieczone są moje dane?',
                        'a' => 'Bezpieczeństwo transmisji gwarantuje szyfrowane połączenie HTTPS. Wszystkie zasoby są składowane na certyfikowanych serwerach OVH, korzystających z zaawansowanej infrastruktury obronnej (Anti-DDoS). Dodatkowo, system RBAC weryfikuje uprawnienia użytkownika przy każdym zapytaniu do bazy danych.'
                    ],
                    [
                        'q' => 'Gdzie mogę pobrać aplikację na Androida?',
                        'a' => 'NoteSync dystrybuowany jest w formie pliku instalacyjnego (.apk), który pobierzesz bezpośrednio z naszej sekcji Hero. Po pobraniu należy zezwolić w ustawieniach telefonu na instalację aplikacji z nieznanych źródeł, aby cieszyć się pełną wersją systemu.'
                    ],
                    [
                        'q' => 'Jak uruchomić NoteSync na systemie iOS (iPhone)?',
                        'a' => 'Użytkownicy systemu iOS mogą uruchomić aplikację za pomocą platformy Expo Go. Należy pobrać Expo Go z App Store, a następnie zeskanować kod QR dostępny w naszym oknie testowym. Pozwala to na natychmiastowe uruchomienie natywnego interfejsu bez konieczności instalacji zewnętrznych plików.'
                    ]
                ];
            @endphp

            @foreach($faqs as $index => $item)
                <div class="faq-item-biz" data-reveal>
                    <button class="faq-q-biz" aria-expanded="false">
                        <span>{{ $item['q'] }}</span>
                        <div class="faq-icon-biz">
                            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"></path></svg>
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
    document.addEventListener('DOMContentLoaded', () => {
        const faqItems = document.querySelectorAll('.faq-item-biz');

        faqItems.forEach(item => {
            const btn = item.querySelector('.faq-q-biz');
            const content = item.querySelector('.faq-a-biz');

            btn.addEventListener('click', () => {
                const isOpen = item.classList.contains('open');

                faqItems.forEach(other => {
                    if (other !== item && other.classList.contains('open')) {
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
    });
</script>
