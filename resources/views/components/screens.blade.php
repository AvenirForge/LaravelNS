@php $shots = [1, 2, 3, 4]; @endphp

<style>
    .shots {
        padding: 80px 0 40px;
        background: transparent;
    }

    .container-ultra-wide {
        max-width: 1720px;
        margin: 0 auto;
        padding: 0 clamp(20px, 4vw, 60px);
    }

    .gridShots {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: clamp(20px, 3vw, 40px);
        align-items: start;
    }

    @media (max-width: 1400px) {
        .gridShots {
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
        }
    }

    @media (max-width: 768px) {
        .gridShots {
            grid-template-columns: 1fr;
            max-width: 450px;
            margin: 0 auto;
            gap: 40px;
        }
    }

    .shot-btn {
        display: block;
        width: 100%;
        background: var(--bg2);
        border: 1px solid var(--border);
        border-radius: 32px;
        padding: 16px;
        box-shadow: 0 20px 40px -15px rgba(0,0,0,0.15);
        cursor: zoom-in;
        transition: transform 0.5s cubic-bezier(0.2, 1, 0.3, 1),
        box-shadow 0.4s ease,
        border-color 0.3s ease;
        overflow: hidden;
        outline: none;
    }

    .shot-btn:hover {
        transform: translateY(-12px) scale(1.03);
        box-shadow: 0 40px 80px -20px rgba(0,0,0,0.35);
        border-color: var(--primary);
    }

    .shot {
        width: 100%;
        height: auto;
        border-radius: 20px;
        display: block;
        object-fit: contain;
        image-rendering: auto;
    }

    .lightbox {
        position: fixed;
        inset: 0;
        background: rgba(var(--bg-rgb, 10, 10, 10), 0.94);
        backdrop-filter: blur(20px) saturate(150%);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.4s ease;
        z-index: 9999;
    }

    .lightbox.open {
        opacity: 1;
        pointer-events: auto;
    }

    .lightbox img {
        max-width: 100%;
        max-height: 90vh;
        object-fit: contain;
        border-radius: 24px;
        box-shadow: 0 50px 120px rgba(0,0,0,0.8);
        transform: translateY(30px) scale(0.9);
        transition: transform 0.5s cubic-bezier(0.19, 1, 0.22, 1);
    }

    .lightbox.open img {
        transform: translateY(0) scale(1);
    }

    .lightbox-close {
        position: absolute;
        top: clamp(20px, 5vh, 40px);
        right: clamp(20px, 5vw, 40px);
        background: var(--fg);
        color: var(--bg);
        border: none;
        border-radius: 16px;
        width: 54px;
        height: 54px;
        cursor: pointer;
        display: grid;
        place-items: center;
        transition: all 0.3s ease;
        z-index: 10000;
    }

    .lightbox-close:hover {
        transform: scale(1.1) rotate(90deg);
        filter: brightness(0.9);
    }

    .gridShots > [data-reveal] {
        transition-duration: 0.8s;
    }
    .gridShots > [data-reveal]:nth-child(1) { transition-delay: 0.1s; }
    .gridShots > [data-reveal]:nth-child(2) { transition-delay: 0.25s; }
    .gridShots > [data-reveal]:nth-child(3) { transition-delay: 0.4s; }
    .gridShots > [data-reveal]:nth-child(4) { transition-delay: 0.55s; }
</style>

<main class="shots" id="screens">
    <div class="container-ultra-wide">
        <div class="gridShots">
            @foreach($shots as $num)
                <button class="shot-btn" data-shot-num="{{ $num }}" data-reveal aria-label="Powiększ zrzut ekranu {{ $num }}">
                    <img class="shot" data-shot-img="{{ $num }}" src="/assets/images/dark/{{ $num }}.avif" alt="Interfejs NoteSync Pro - Ekran {{ $num }}" loading="lazy" width="400" height="866" />
                </button>
            @endforeach
        </div>
    </div>
</main>

<script>
    (function() {
        const getUrl = (n, t) => `/assets/images/${t}/${n}.avif`;

        const refreshImages = () => {
            const theme = document.documentElement.getAttribute('data-theme') || 'dark';

            if (theme === 'dark') return;

            document.querySelectorAll('[data-shot-img]').forEach(img => {
                img.src = getUrl(img.getAttribute('data-shot-img'), theme);
            });
        };

        new MutationObserver(m => {
            if(m[0].attributeName === 'data-theme') {
                const theme = document.documentElement.getAttribute('data-theme');
                document.querySelectorAll('[data-shot-img]').forEach(img => {
                    img.src = getUrl(img.getAttribute('data-shot-img'), theme);
                });
            }
        }).observe(document.documentElement, { attributes: true });

        const lb = document.createElement('div');
        lb.className = 'lightbox';
        lb.innerHTML = `
            <button class="lightbox-close" aria-label="Zamknij podgląd">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
            <img id="lbImg" src="" alt="Podgląd zrzutu ekranu" />
        `;
        document.body.appendChild(lb);
        const lbImg = lb.querySelector('#lbImg');

        document.querySelectorAll('.shot-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const sourceImg = btn.querySelector('img');
                lbImg.src = sourceImg.src;
                lbImg.alt = sourceImg.alt;
                lb.classList.add('open');
                document.body.style.overflow = 'hidden';
            });
        });

        const close = () => {
            lb.classList.remove('open');
            document.body.style.overflow = '';
        };

        lb.addEventListener('click', e => (e.target === lb || e.target.closest('.lightbox-close')) && close());
        document.addEventListener('keydown', e => e.key === 'Escape' && close());

        refreshImages();
    })();
</script>
