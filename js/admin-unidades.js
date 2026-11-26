(function () {
    'use strict';

    var mapaElemento = document.getElementById('unidade-mapa-editor');
    if (!mapaElemento || typeof L === 'undefined') return;

    var campoLat = document.getElementById('unidade-latitude');
    var campoLng = document.getElementById('unidade-longitude');
    var linkGoogle = document.getElementById('unidade-google-maps');
    var botaoAtualizar = document.getElementById('unidade-localizar-coordenadas');
    var campoMorada = document.getElementById('unidade-endereco');
    var campoCodigoPostal = document.getElementById('unidade-codigo-postal');
    var campoCidade = document.getElementById('unidade-cidade');
    var campoPais = document.getElementById('unidade-pais');

    function numero(campo) {
        var valor = String(campo.value || '').trim().replace(',', '.');
        if (valor === '' || !Number.isFinite(Number(valor))) return null;
        return Number(valor);
    }

    function atualizarLinkGoogle() {
        var partes = [
            campoMorada && campoMorada.value,
            campoCodigoPostal && campoCodigoPostal.value,
            campoCidade && campoCidade.value,
            campoPais && campoPais.value
        ].filter(Boolean);
        linkGoogle.href = partes.length
            ? 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(partes.join(', '))
            : 'https://www.google.com/maps';
    }

    var latInicial = numero(campoLat);
    var lngInicial = numero(campoLng);
    var coordenadasValidas = latInicial !== null && lngInicial !== null
        && latInicial >= -90 && latInicial <= 90 && lngInicial >= -180 && lngInicial <= 180;

    var mapa = L.map(mapaElemento, {
        scrollWheelZoom: true,
        zoomControl: true
    }).setView(coordenadasValidas ? [latInicial, lngInicial] : [39.5, -8], coordenadasValidas ? 15 : 6);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'
    }).addTo(mapa);

    var marcador = null;

    function definirCoordenadas(lat, lng, centrar) {
        if (!Number.isFinite(lat) || !Number.isFinite(lng)
            || lat < -90 || lat > 90 || lng < -180 || lng > 180) return;

        campoLat.value = lat.toFixed(7).replace(/0+$/, '').replace(/\.$/, '');
        campoLng.value = lng.toFixed(7).replace(/0+$/, '').replace(/\.$/, '');

        var posicao = [lat, lng];
        if (!marcador) {
            marcador = L.marker(posicao, { draggable: true }).addTo(mapa);
            marcador.on('dragend', function (evento) {
                var ponto = evento.target.getLatLng();
                definirCoordenadas(ponto.lat, ponto.lng, false);
            });
        } else {
            marcador.setLatLng(posicao);
        }
        if (centrar) mapa.setView(posicao, Math.max(mapa.getZoom(), 15));
    }

    mapa.on('click', function (evento) {
        definirCoordenadas(evento.latlng.lat, evento.latlng.lng, true);
    });

    if (coordenadasValidas) definirCoordenadas(latInicial, lngInicial, false);

    botaoAtualizar.addEventListener('click', function () {
        var lat = numero(campoLat);
        var lng = numero(campoLng);
        if (lat === null || lng === null) {
            window.alert('Indica uma latitude e uma longitude válidas.');
            return;
        }
        definirCoordenadas(lat, lng, true);
    });

    [campoLat, campoLng].forEach(function (campo) {
        campo.addEventListener('change', function () {
            var lat = numero(campoLat);
            var lng = numero(campoLng);
            if (lat !== null && lng !== null) definirCoordenadas(lat, lng, true);
        });
    });

    [campoMorada, campoCodigoPostal, campoCidade, campoPais].forEach(function (campo) {
        if (campo) campo.addEventListener('input', atualizarLinkGoogle);
    });

    atualizarLinkGoogle();
    window.setTimeout(function () { mapa.invalidateSize(); }, 150);
})();
