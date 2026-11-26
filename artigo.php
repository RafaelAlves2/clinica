<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';

$pdo  = db();
$slug = (string)($_GET['slug'] ?? '');

$st = $pdo->prepare('SELECT id, titulo, resumo, conteudo, categoria, criado_em FROM artigos WHERE slug = ? AND visivel = 1');
$st->execute([$slug]);
$artigo = $st->fetch();

if (!$artigo) {
    flash_definir('erro', 'Artigo não encontrado.');
    redirecionar('blog.php');
}

$paragrafos = array_values(array_filter(
    array_map('trim', preg_split('/\n\s*\n/', (string)$artigo['conteudo'])),
    static fn(string $p): bool => $p !== ''
));

$tituloPagina = $artigo['titulo'];
require __DIR__ . '/includes/cabecalho.php';
?>

<section class="interior-hero">
    <div class="interior-hero-top">
        <div class="interior-hero-kicker">
            <span class="kicker-num"><?= e($artigo['categoria'] ?: 'Artigo') ?></span>
            <span class="kicker-line" aria-hidden="true"></span>
            <span><?= e((new DateTime($artigo['criado_em']))->format('d/m/Y')) ?></span>
        </div>
        <a class="interior-hero-aside" href="blog.php">← Voltar</a>
    </div>
    <h1><?= e($artigo['titulo']) ?><span class="ponto">.</span></h1>
    <?php if ($artigo['resumo']): ?>
        <div class="interior-hero-bottom">
            <p><?= e($artigo['resumo']) ?></p>
            <span>Leitura informativa</span>
        </div>
    <?php endif; ?>
</section>

<article>
    <div class="artigo-conteudo">
        <?php foreach ($paragrafos as $p): ?>
            <p><?= nl2br(e($p)) ?></p>
        <?php endforeach; ?>
    </div>

    <div class="alerta alerta-info" role="note" style="margin-top: var(--esp-6);">
        Este artigo é meramente informativo e não constitui diagnóstico, aconselhamento
        clínico ou proposta comercial. Para uma avaliação, marca uma consulta.
    </div>
</article>

<p style="margin-top: var(--esp-5);">
    <a class="botao botao-secundario" href="blog.php">← Voltar à informação ao paciente</a>
    <a class="botao" href="marcar.php">Marcar consulta</a>
</p>

<?php require __DIR__ . '/includes/rodape.php'; ?>