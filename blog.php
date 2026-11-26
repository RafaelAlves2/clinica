<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';

$pdo = db();
$artigos = $pdo->query(
    'SELECT id, titulo, slug, resumo, categoria, criado_em
       FROM artigos WHERE visivel = 1 ORDER BY criado_em DESC'
)->fetchAll();

$tituloPagina = 'Informação ao paciente';
require __DIR__ . '/includes/cabecalho.php';
?>

<section class="interior-hero">
    <div class="interior-hero-top">
        <div class="interior-hero-kicker">
            <span class="kicker-num">04 / 2026</span>
            <span class="kicker-line" aria-hidden="true"></span>
            <span>Informação ao paciente</span>
        </div>
        <span class="interior-hero-aside"><?= count($artigos) ?> artigo<?= count($artigos) === 1 ? '' : 's' ?></span>
    </div>
    <h1>Informação ao paciente<span class="ponto">.</span></h1>
    <div class="interior-hero-bottom">
        <p>
            Artigos informativos sobre saúde oral, prevenção e tratamentos.
            Esta informação não substitui uma consulta de avaliação clínica.
        </p>
        <span>Lê com calma ↓</span>
    </div>
</section>

<?php if (!$artigos): ?>
    <div class="alerta alerta-info">Ainda não há artigos publicados.</div>
<?php else: ?>
    <div class="grelha grelha-larga" data-stagger="80">
        <?php foreach ($artigos as $a): ?>
            <a class="cartao blog-card" href="artigo.php?slug=<?= e(urlencode($a['slug'])) ?>">
                <?php if ($a['categoria']): ?>
                    <span class="etiqueta"><?= e($a['categoria']) ?></span>
                <?php endif; ?>
                <h2><?= e($a['titulo']) ?></h2>
                <?php if ($a['resumo']): ?>
                    <p class="ajuda" style="margin:0;"><?= e($a['resumo']) ?></p>
                <?php endif; ?>
                <span class="blog-card-date"><?= e((new DateTime($a['criado_em']))->format('d/m/Y')) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/rodape.php'; ?>