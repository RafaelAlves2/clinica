<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';

$tituloPagina = 'Marcar consulta';
require __DIR__ . '/includes/cabecalho.php';
?>

<section class="interior-hero">
    <div class="interior-hero-top">
        <div class="interior-hero-kicker">
            <span class="kicker-num">01 / 2026</span>
            <span class="kicker-line" aria-hidden="true"></span>
            <span>Marcar consulta</span>
        </div>
        <span class="interior-hero-aside">Leva 2 minutos</span>
    </div>
    <h1>Marcar consulta<span class="ponto">.</span></h1>
    <div class="interior-hero-bottom">
        <p>Como preferes começar?</p>
        <span>Escolhe uma opção ↓</span>
    </div>
</section>

<div class="funil-start">
    <a class="funil-start-card" href="dentistas.php?modo=marcar">
        <span class="funil-start-icon" aria-hidden="true">◉</span>
        <h2>Escolher um dentista</h2>
        <p>Já sabes com quem queres ser atendido? Vê a equipa e escolhe.</p>
        <span class="funil-start-cta">Ver dentistas →</span>
    </a>
    <a class="funil-start-card" href="tratamentos.php?modo=marcar">
        <span class="funil-start-icon" aria-hidden="true">✳</span>
        <h2>Escolher um tratamento</h2>
        <p>Sabes o que precisas? Escolhe o tratamento e mostramos quem o realiza.</p>
        <span class="funil-start-cta">Ver tratamentos →</span>
    </a>
</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>