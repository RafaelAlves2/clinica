<?php
declare(strict_types=1);

require __DIR__ . '/includes/config.php';

$pdo = db();
$unidades = $pdo->query(
    'SELECT id, nome, descricao, endereco, codigo_postal, cidade, distrito, pais,
            telefone, email, horario, latitude, longitude, principal, ordem
       FROM unidades
      WHERE ativo = 1
      ORDER BY principal DESC, ordem ASC, nome ASC'
)->fetchAll();

$dadosGlobo = array_map(static function (array $unidade): array {
    return [
        'id' => (int)$unidade['id'],
        'nome' => (string)$unidade['nome'],
        'descricao' => (string)($unidade['descricao'] ?? ''),
        'endereco' => (string)$unidade['endereco'],
        'codigo_postal' => (string)($unidade['codigo_postal'] ?? ''),
        'cidade' => (string)$unidade['cidade'],
        'distrito' => (string)($unidade['distrito'] ?? ''),
        'pais' => (string)$unidade['pais'],
        'telefone' => (string)($unidade['telefone'] ?? ''),
        'email' => (string)($unidade['email'] ?? ''),
        'horario' => (string)($unidade['horario'] ?? ''),
        'latitude' => $unidade['latitude'] !== null ? (float)$unidade['latitude'] : null,
        'longitude' => $unidade['longitude'] !== null ? (float)$unidade['longitude'] : null,
        'principal' => (int)$unidade['principal'],
    ];
}, $unidades);

$tituloPagina = 'As nossas unidades';
$css_extra = ['css/vendor/leaflet.css', 'css/unidades.css'];
require __DIR__ . '/includes/cabecalho.php';
?>

<div class="unidades-publicas">
    <header class="unidades-publicas-topo">
        <div>
            <p class="unidades-kicker">Estamos perto de si</p>
            <h1>As nossas unidades</h1>
            <p class="unidades-intro">Explore o globo, escolha uma unidade e consulte a morada, os contactos e a localização no mapa.</p>
        </div>
        <a class="botao botao-secundario" href="contactos.php">Contactar a clínica</a>
    </header>

    <?php if (!$unidades): ?>
        <section class="unidades-vazio">
            <h2>Estamos a preparar esta informação.</h2>
            <p>As unidades disponíveis serão apresentadas aqui em breve.</p>
        </section>
    <?php else: ?>
        <section class="unidades-globo-layout" aria-label="Explorar as unidades da clínica">
            <div class="unidades-globo-painel">
                <div class="unidades-globo-painel-topo">
                    <div>
                        <h2>Explore o mundo</h2>
                        <p>Rode o globo e selecione um dos marcadores para descobrir a unidade.</p>
                    </div>
                    <span class="unidades-globo-hint">3D interativo · Arraste para rodar</span>
                </div>
                <div id="unidades-globo" role="img" aria-label="Globo terrestre 3D com marcadores das unidades ativas"></div>
            </div>

            <aside class="unidades-detalhe" id="unidades-detalhe" aria-live="polite" aria-atomic="true">
                <p class="ajuda">A carregar informação da unidade…</p>
            </aside>
        </section>

        <section class="unidades-mapa-bloco" aria-labelledby="unidades-mapa-titulo">
            <div class="unidades-mapa-bloco-topo">
                <div>
                    <h2 id="unidades-mapa-titulo">Localização detalhada</h2>
                    <p class="ajuda">Mapa da unidade selecionada, com ligação para obter direções.</p>
                </div>
                <span class="unidades-kicker">Mapa</span>
            </div>
            <div id="unidades-mapa" aria-label="Mapa da unidade selecionada">
                <p class="unidades-mapa-vazio">Seleciona uma unidade para consultar o mapa.</p>
            </div>
        </section>

        <section class="unidades-lista-publica" aria-labelledby="unidades-lista-titulo">
            <header>
                <div>
                    <h2 id="unidades-lista-titulo">Escolha uma unidade</h2>
                    <p><?= count($unidades) === 1 ? '1 unidade disponível' : count($unidades) . ' unidades disponíveis' ?></p>
                </div>
            </header>
            <div class="unidades-cartoes">
                <?php foreach ($unidades as $unidade): ?>
                    <button class="unidades-cartao" type="button"
                            data-unidade-selecionar="<?= (int)$unidade['id'] ?>"
                            aria-pressed="false">
                        <strong><?= e($unidade['nome']) ?></strong>
                        <?php if ((int)$unidade['principal'] === 1): ?>
                            <span class="unidade-etiqueta principal">Unidade principal</span>
                        <?php endif; ?>
                        <span><?= e($unidade['cidade']) ?> · <?= e($unidade['pais']) ?></span>
                        <span><?= e($unidade['endereco']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <p class="unidades-creditos">
        Globo 3D com textura terrestre e céu de demonstração do projeto <a href="https://github.com/vasturiano/globe.gl" target="_blank" rel="noopener">globe.gl</a>.
        Cartografia detalhada © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a>.
    </p>
</div>

<script src="js/vendor/globe.gl.min.js"></script>
<script src="js/vendor/leaflet.js"></script>
<script>
window.unidadesClinica = <?= json_encode(
    $dadosGlobo,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
) ?: '[]' ?>;
</script>
<script src="js/unidades.js"></script>

<?php require __DIR__ . '/includes/rodape.php'; ?>
