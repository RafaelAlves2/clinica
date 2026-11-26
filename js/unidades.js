(function () {
    'use strict';

    var pontos = Array.isArray(window.unidadesClinica) ? window.unidadesClinica : [];
    var globoElemento = document.getElementById('unidades-globo');
    var detalheElemento = document.getElementById('unidades-detalhe');
    var listaElementos = document.querySelectorAll('[data-unidade-selecionar]');
    var mapaElemento = document.getElementById('unidades-mapa');
    var mapa = null;
    var camadaMarcadores = null;
    var unidadeSelecionada = null;

    function escapar(valor) {
        return String(valor == null ? '' : valor).replace(/[&<>"']/g, function (caracter) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[caracter];
        });
    }

    function moradaCompleta(unidade) {
        return [
            unidade.endereco,
            unidade.codigo_postal,
            unidade.cidade,
            unidade.distrito,
            unidade.pais
        ].filter(Boolean).join(', ');
    }

    function temCoordenadas(unidade) {
        return unidade.latitude !== null && unidade.longitude !== null
            && unidade.latitude !== '' && unidade.longitude !== ''
            && Number.isFinite(Number(unidade.latitude))
            && Number.isFinite(Number(unidade.longitude));
    }

    function linkGoogle(unidade) {
        return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(moradaCompleta(unidade));
    }

    function selecionarUnidade(id, centrarGlobo) {
        var unidade = pontos.find(function (item) { return Number(item.id) === Number(id); });
        if (!unidade) return;
        unidadeSelecionada = unidade;

        if (detalheElemento) {
            var telefone = unidade.telefone
                ? '<a href="tel:' + escapar(unidade.telefone.replace(/[^+\d]/g, '')) + '">' + escapar(unidade.telefone) + '</a>'
                : '';
            var email = unidade.email
                ? '<a href="mailto:' + escapar(unidade.email) + '">' + escapar(unidade.email) + '</a>'
                : '';
            var contactos = [telefone, email].filter(Boolean).join('<span aria-hidden="true">·</span>');
            detalheElemento.innerHTML =
                '<div class="unidade-detalhe-etiquetas">' +
                    (Number(unidade.principal) === 1 ? '<span class="unidade-etiqueta principal">Unidade principal</span>' : '') +
                    '<span class="unidade-etiqueta ativa">Em funcionamento</span>' +
                '</div>' +
                '<h2>' + escapar(unidade.nome) + '</h2>' +
                (unidade.descricao ? '<p>' + escapar(unidade.descricao) + '</p>' : '') +
                '<div class="unidade-detalhe-linha"><span class="unidade-detalhe-icone" aria-hidden="true">⌖</span><span>' + escapar(moradaCompleta(unidade)) + '</span></div>' +
                (contactos ? '<div class="unidade-detalhe-linha"><span class="unidade-detalhe-icone" aria-hidden="true">↗</span><span>' + contactos + '</span></div>' : '') +
                (unidade.horario ? '<div class="unidade-detalhe-linha"><span class="unidade-detalhe-icone" aria-hidden="true">◷</span><span>' + escapar(unidade.horario) + '</span></div>' : '') +
                '<div class="unidade-detalhe-acoes"><a class="botao" href="' + escapar(linkGoogle(unidade)) + '" target="_blank" rel="noopener">Como chegar <span aria-hidden="true">↗</span></a></div>';
        }

        listaElementos.forEach(function (botao) {
            var ativo = Number(botao.getAttribute('data-unidade-selecionar')) === Number(unidade.id);
            botao.classList.toggle('selecionada', ativo);
            botao.setAttribute('aria-pressed', ativo ? 'true' : 'false');
        });

        atualizarMapa(unidade);
        if (centrarGlobo && window.globoUnidades && temCoordenadas(unidade)) {
            window.globoUnidades.pointOfView({
                lat: Number(unidade.latitude),
                lng: Number(unidade.longitude),
                altitude: 1.8
            }, 900);
        }
    }

    function atualizarMapa(unidade) {
        if (!mapaElemento || typeof L === 'undefined') return;
        var latitude = Number(unidade.latitude);
        var longitude = Number(unidade.longitude);

        if (!temCoordenadas(unidade)) {
            if (mapa) {
                mapa.remove();
                mapa = null;
                camadaMarcadores = null;
            }
            mapaElemento.innerHTML = '<p class="unidades-mapa-vazio">Esta unidade ainda não tem uma posição definida no mapa.</p>';
            return;
        }

        if (!mapa) {
            mapa = L.map(mapaElemento, { scrollWheelZoom: false }).setView([latitude, longitude], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'
            }).addTo(mapa);
            camadaMarcadores = L.layerGroup().addTo(mapa);
        }

        mapaElemento.classList.remove('sem-mapa');
        camadaMarcadores.clearLayers();
        L.marker([latitude, longitude]).addTo(camadaMarcadores)
            .bindPopup('<strong>' + escapar(unidade.nome) + '</strong><br>' + escapar(moradaCompleta(unidade)));
        mapa.setView([latitude, longitude], 15);
        window.setTimeout(function () { mapa.invalidateSize(); }, 80);
    }

    function iniciarGlobo() {
        if (!globoElemento || typeof Globe !== 'function') {
            if (globoElemento) globoElemento.innerHTML = '<p class="unidades-globo-fallback">Não foi possível iniciar a visualização 3D neste navegador. Consulta as unidades na lista abaixo.</p>';
            return;
        }

        var unidadesComPonto = pontos.filter(temCoordenadas);

        if (!unidadesComPonto.length) {
            globoElemento.innerHTML = '<p class="unidades-globo-fallback">Ainda não existem unidades com coordenadas geográficas.</p>';
            return;
        }

        var globo = Globe()(globoElemento)
            .width(globoElemento.clientWidth || 760)
            .height(globoElemento.clientHeight || 560)
            .globeImageUrl('img/globe/earth-night.jpg')
            .backgroundImageUrl('img/globe/night-sky.png')
            .showAtmosphere(true)
            .atmosphereColor('#79b8b0')
            .atmosphereAltitude(0.18)
            .pointsData(unidadesComPonto)
            .pointLat(function (item) { return Number(item.latitude); })
            .pointLng(function (item) { return Number(item.longitude); })
            .pointColor(function (item) { return Number(item.principal) === 1 ? '#e6c78f' : '#78d5c2'; })
            .pointAltitude(function (item) { return Number(item.principal) === 1 ? 0.13 : 0.09; })
            .pointRadius(function (item) { return Number(item.principal) === 1 ? 0.42 : 0.32; })
            .pointResolution(12)
            .pointLabel(function (item) {
                return '<div class="globo-tooltip"><strong>' + escapar(item.nome) + '</strong><span>' +
                    escapar(item.cidade + ', ' + item.pais) + '</span></div>';
            })
            .onPointClick(function (item) { selecionarUnidade(item.id, false); });

        var inicial = unidadesComPonto.find(function (item) { return Number(item.principal) === 1; }) || unidadesComPonto[0];
        globo.pointOfView({ lat: Number(inicial.latitude), lng: Number(inicial.longitude), altitude: 2.4 }, 0);
        if (globo.controls) {
            globo.controls().autoRotate = true;
            globo.controls().autoRotateSpeed = 0.32;
            globo.controls().enablePan = false;
        }
        globoElemento.addEventListener('mouseenter', function () {
            if (globo.controls) globo.controls().autoRotate = false;
        });
        globoElemento.addEventListener('mouseleave', function () {
            if (globo.controls) globo.controls().autoRotate = true;
        });
        window.globoUnidades = globo;
    }

    listaElementos.forEach(function (botao) {
        botao.addEventListener('click', function () {
            selecionarUnidade(botao.getAttribute('data-unidade-selecionar'), true);
        });
    });

    iniciarGlobo();

    var inicial = pontos.find(function (item) { return Number(item.principal) === 1; }) || pontos[0];
    if (inicial) selecionarUnidade(inicial.id, false);
})();
