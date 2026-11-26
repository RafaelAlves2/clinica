<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';

$pdo = db();

$seguradoras = $pdo->query(
    'SELECT s.id, s.nome, s.tipo,
            (SELECT GROUP_CONCAT(pl.nome ORDER BY pl.nome SEPARATOR "||")
               FROM planos_beneficio pl WHERE pl.seguradora_id = s.id AND pl.ativo = 1) AS planos
       FROM seguradoras s WHERE s.ativo = 1 ORDER BY s.nome'
)->fetchAll();

$tituloPagina = 'Seguros e acordos';
require __DIR__ . '/includes/cabecalho.php';
?>

<section class="interior-hero">
    <div class="interior-hero-top">
        <div class="interior-hero-kicker">
            <span class="kicker-num">06 / 2026</span>
            <span class="kicker-line" aria-hidden="true"></span>
            <span>Seguros e acordos</span>
        </div>
        <span class="interior-hero-aside"><?= count($seguradoras) ?> acordo<?= count($seguradoras) === 1 ? '' : 's' ?></span>
    </div>
    <h1>Seguros e acordos<span class="ponto">.</span></h1>
    <div class="interior-hero-bottom">
        <p>
            Trabalhamos com vários seguros de saúde e planos dentários.
            A aplicação de qualquer desconto depende de validação prévia pela clínica.
        </p>
        <span>Vê os acordos ↓</span>
    </div>
</section>

<div class="alerta alerta-info" role="note">
    A informação abaixo é indicativa. A aplicação de qualquer desconto depende sempre
    de <strong>validação prévia pela clínica</strong> e das condições específicas da
    apólice. Podem existir restrições, exclusões ou períodos de carência.
    Confirma sempre com a receção antes da consulta.
</div>

<?php if (!$seguradoras): ?>
    <div class="alerta alerta-info">De momento não temos acordos publicados. Contacta-nos.</div>
<?php else: ?>
    <div class="grelha grelha-larga" data-stagger="80">
        <?php foreach ($seguradoras as $s): ?>
            <article class="cartao">
                <h2><?= e($s['nome']) ?></h2>
                <p class="ajuda" style="margin-top: 0;"><?= e($s['tipo']) ?></p>
                <?php if ($s['planos']): ?>
                    <p class="ajuda">Planos: <?= e(str_replace('||', ', ', $s['planos'])) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<p style="margin-top: var(--esp-5);">
    Tens um seguro ou plano que não está aqui?
    <a href="contactos.php">Contacta-nos</a> — podemos verificar a possibilidade de acordo.
</p>

<p>
    <a class="botao" href="seguros.php">Registar o meu benefício</a>
    <a class="botao botao-secundario" href="marcar.php">Marcar consulta</a>
</p>

<?php require __DIR__ . '/includes/rodape.php'; ?>