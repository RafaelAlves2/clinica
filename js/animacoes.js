/* =====================================================================
   animacoes.js - animações ao scroll (reveal) + stagger + cursor + magnetic
   Sem dependências. Respeita prefers-reduced-motion.

   Modo automático:
     - Anima automaticamente <h1>, <section class="seccao">, .cartao,
       .editorial, .faq, .testemunho, .passos-preparacao li, .tabela-editorial tr,
       .grelha > *, .grelha-larga > *, .grelha-info > *.
     - Os elementos com [data-animar] continuam a funcionar (override manual).
     - Os elementos com data-animar="off" são ignorados.
     - Os elementos com [data-stagger] animam os filhos em sequência.
     - Botões com [data-magnetic] ou .botao-hero têm efeito magnético em desktop.
   ===================================================================== */
(function () {
    'use strict';

    var reduzir = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- 1. Seletores que animam automaticamente ---------- */
    var SELETORES_AUTO = [
        'main > h1',
        'main > section.seccao',
        'main > section.cartao',
        'main > .cartao',
        '.grelha > .cartao',
        '.grelha-larga > .cartao',
        '.grelha-info > .cartao',
        '.editorial',
        '.faq',
        '.testemunho',
        '.passos-preparacao > li',
        '.tabela-editorial > tbody > tr',
        '.consulta-cartao',
        '.linha-consulta'
    ];

    function prepararElementosAutomaticos() {
        var vistos = new Set();

        SELETORES_AUTO.forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                if (vistos.has(el)) { return; }
                if (el.hasAttribute('data-animar')) { return; }
                vistos.add(el);

                var tipo = 'up';
                if (el.matches('.tabela-editorial > tbody > tr')) {
                    tipo = 'fade';
                }

                el.setAttribute('data-animar', tipo);
            });
        });
    }

    /* ---------- 2. Reveal ao scroll ---------- */
    function iniciarReveal() {
        var elementos = document.querySelectorAll('[data-animar]:not([data-animar="off"])');
        if (!elementos.length) { return; }

        if (!('IntersectionObserver' in window) || reduzir) {
            elementos.forEach(function (el) { el.classList.add('animado'); });
            return;
        }

        var observer = new IntersectionObserver(function (entradas) {
            entradas.forEach(function (entrada) {
                if (!entrada.isIntersecting) { return; }
                var el = entrada.target;
                var atraso = parseInt(el.getAttribute('data-animar-atraso') || '0', 10);
                if (atraso > 0) {
                    setTimeout(function () { el.classList.add('animado'); }, atraso);
                } else {
                    el.classList.add('animado');
                }
                observer.unobserve(el);
            });
        }, {
            threshold: 0.12,
            rootMargin: '0px 0px -40px 0px'
        });

        elementos.forEach(function (el) { observer.observe(el); });
    }

    /* ---------- 3. Stagger automático ---------- */
    function iniciarStagger() {
        document.querySelectorAll('[data-stagger]').forEach(function (grupo) {
            var passo = parseInt(grupo.getAttribute('data-stagger') || '80', 10);
            Array.prototype.forEach.call(grupo.children, function (filho, i) {
                if (!filho.hasAttribute('data-animar')) {
                    filho.setAttribute('data-animar', 'up');
                }
                if (!filho.hasAttribute('data-animar-atraso')) {
                    filho.setAttribute('data-animar-atraso', String(i * passo));
                }
            });
        });
    }

    /* ---------- 4. Cursor personalizado (opcional) ---------- */
    function iniciarCursor() {
        if (reduzir) { return; }
        if (!document.querySelector('[data-cursor]')) { return; }
        if (!window.matchMedia || !matchMedia('(pointer: fine)').matches) { return; }

        var cursor = document.createElement('div');
        cursor.className = 'cursor-personalizado';
        cursor.setAttribute('aria-hidden', 'true');
        document.body.appendChild(cursor);

        var x = 0, y = 0, alvoX = 0, alvoY = 0;
        var frameCursor = null;

        function animarCursor() {
            frameCursor = null;
            x += (alvoX - x) * 0.22;
            y += (alvoY - y) * 0.22;
            cursor.style.transform = 'translate(' + x + 'px, ' + y + 'px)';

            // Só mantém a animação ativa enquanto o cursor ainda está a aproximar-se.
            if (Math.abs(alvoX - x) > 0.2 || Math.abs(alvoY - y) > 0.2) {
                frameCursor = requestAnimationFrame(animarCursor);
            }
        }

        document.addEventListener('mousemove', function (e) {
            alvoX = e.clientX;
            alvoY = e.clientY;
            if (frameCursor === null && !document.hidden) {
                frameCursor = requestAnimationFrame(animarCursor);
            }
        }, { passive: true });

        document.addEventListener('visibilitychange', function () {
            if (document.hidden && frameCursor !== null) {
                cancelAnimationFrame(frameCursor);
                frameCursor = null;
            } else if (!document.hidden && (Math.abs(alvoX - x) > 0.2 || Math.abs(alvoY - y) > 0.2)) {
                frameCursor = requestAnimationFrame(animarCursor);
            }
        });

        document.addEventListener('mouseover', function (e) {
            var alvo = e.target.closest('a, button, .botao, input, select, textarea');
            cursor.classList.toggle('cursor-ativo', !!alvo);
        });

        document.addEventListener('mouseleave', function () { cursor.classList.add('cursor-fora'); });
        document.addEventListener('mouseenter', function () { cursor.classList.remove('cursor-fora'); });
    }

    /* ---------- 5. Efeito magnético em CTAs (desktop only) ---------- */
    function iniciarMagnetico() {
        if (reduzir) { return; }
        if (!window.matchMedia || !matchMedia('(pointer: fine)').matches) { return; }

        document.querySelectorAll('[data-magnetic], .botao-hero').forEach(function (btn) {
            if (btn.dataset.magneticInit === '1') { return; }
            btn.dataset.magneticInit = '1';

            btn.addEventListener('pointermove', function (e) {
                var r = btn.getBoundingClientRect();
                var dx = (e.clientX - r.left - r.width / 2) * 0.08;
                var dy = (e.clientY - r.top - r.height / 2) * 0.12;
                btn.style.transform = 'translate(' + dx + 'px, ' + dy + 'px)';
            });

            btn.addEventListener('pointerleave', function () {
                btn.style.transform = '';
            });

            btn.addEventListener('blur', function () {
                btn.style.transform = '';
            });
        });
    }

    /* ---------- 6. Slideshow do hero (automático, suave) ---------- */
    function iniciarHeroSlideshow() {
        var hero = document.querySelector('.hero-com-slides');
        if (!hero || hero.dataset.slideshowInit === '1') { return; }
        var slides = hero.querySelectorAll('.hero-slide');
        if (slides.length <= 1) { return; }
        hero.dataset.slideshowInit = '1';

        if (reduzir) { return; }

        var indice = 0;
        var intervaloMs = 5000;
        var temporizador = null;

        function irPara(novo) {
            slides[indice].classList.remove('ativo');
            indice = (novo + slides.length) % slides.length;
            slides[indice].classList.add('ativo');
        }

        function avancar() { irPara(indice + 1); }

        function iniciar() {
            if (temporizador !== null) { return; }
            temporizador = window.setInterval(avancar, intervaloMs);
        }

        function parar() {
            if (temporizador !== null) {
                window.clearInterval(temporizador);
                temporizador = null;
            }
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { parar(); } else { iniciar(); }
        });

        iniciar();
    }

    /* ---------- 7. Navegação mobile: mostrar o link da página atual ----------
       Em ecrãs estreitos a nav é uma faixa horizontal com scroll. Sem isto,
       o link ativo podia ficar cortado fora da área visível. */
    function manterLinkAtivoVisivel() {
        var nav = document.querySelector('.topo nav');
        var ativo = nav ? nav.querySelector('a[aria-current="page"]') : null;
        if (!nav || !ativo || nav.scrollWidth <= nav.clientWidth) { return; }

        var filhos = Array.prototype.slice.call(nav.children).filter(function (el) {
            return el.offsetWidth > 0;
        });
        var indice = filhos.indexOf(ativo);
        if (indice < 0) { return; }

        var gap = parseFloat(getComputedStyle(nav).columnGap) || 0;
        var larguraNav = nav.clientWidth;
        var inicio = indice;
        var total = 0;

        for (var i = indice; i >= 0; i--) {
            var largura = filhos[i].getBoundingClientRect().width;
            var necessario = largura + (i === indice ? 0 : gap);
            if (total + necessario > larguraNav) { break; }
            total += necessario;
            inicio = i;
        }

        var navLeft = nav.getBoundingClientRect().left;
        var alvo = filhos[inicio].getBoundingClientRect().left - navLeft;
        nav.scrollLeft = Math.max(0, nav.scrollLeft + alvo);
    }

    /* ---------- 8. Arranque ---------- */
    document.addEventListener('DOMContentLoaded', function () {
        prepararElementosAutomaticos();
        iniciarStagger();
        iniciarReveal();
        iniciarCursor();
        iniciarMagnetico();
        iniciarHeroSlideshow();
        manterLinkAtivoVisivel();
        // Fallback: se algo falhar, nunca deixar conteúdo invisível
        setTimeout(function () {
            document.querySelectorAll('[data-animar]:not(.animado)').forEach(function (el) {
                el.classList.add('animado');
            });
        }, 2500);
    });
})();