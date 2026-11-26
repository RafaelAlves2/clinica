/* =====================================================================
   dashboard.js — filtros client-side para as listas da área pessoal
   Sem dependências. Respeita [hidden] nativo.
   Funciona em: minhas-marcacoes.php
   Atributos:
     - <div data-dashboard>
     - <button data-filtro="todas|futura|passada|cancelada">
     - <input data-search-input>
     - <article data-marcacao-estado="..." data-marcacao-search="...">
     - <span data-count>
     - <div data-filter-empty>
   ===================================================================== */
(function () {
    'use strict';

    var root = document.querySelector('[data-dashboard]');
    if (!root) { return; }

    var cards = Array.prototype.slice.call(root.querySelectorAll('[data-marcacao-estado]'));
    var buttons = Array.prototype.slice.call(root.querySelectorAll('[data-filtro]'));
    var search = root.querySelector('[data-search-input]');
    var count = root.querySelector('[data-count]');
    var empty = root.querySelector('[data-filter-empty]');

    if (!cards.length || !buttons.length) { return; }

    var active = 'todas';

    // Lê o filtro do URL (?estado=futura), se existir
    try {
        var qs = new URLSearchParams(location.search);
        var doUrl = qs.get('estado');
        if (doUrl && buttons.some(function (b) { return b.dataset.filtro === doUrl; })) {
            active = doUrl;
        }
    } catch (e) { /* browsers antigos */ }

    function normalizar(s) {
        return (s || '').toLocaleLowerCase('pt-PT').trim();
    }

    function apply() {
        var termo = normalizar(search ? search.value : '');
        var visiveis = 0;

        cards.forEach(function (card) {
            var estado = card.dataset.marcacaoEstado || '';
            var texto = normalizar(card.dataset.marcacaoSearch || '');
            var matchEstado = active === 'todas' || estado === active;
            var matchTexto = termo === '' || texto.indexOf(termo) !== -1;
            var mostrar = matchEstado && matchTexto;
            card.hidden = !mostrar;
            if (mostrar) { visiveis++; }
        });

        buttons.forEach(function (btn) {
            var sel = btn.dataset.filtro === active;
            btn.classList.toggle('is-active', sel);
            btn.setAttribute('aria-pressed', sel ? 'true' : 'false');
        });

        if (count) { count.textContent = String(visiveis); }
        if (empty) { empty.hidden = visiveis > 0; }
    }

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            active = btn.dataset.filtro || 'todas';
            try {
                if (location.protocol === 'http:' || location.protocol === 'https:') {
                    history.replaceState(null, '', location.pathname);
                }
            } catch (e) { /* no-op */ }
            apply();
        });
    });

    if (search) {
        search.addEventListener('input', apply);
    }

    apply();
})();