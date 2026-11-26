<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/marcacao.php';

$u = exigir_login();
$pdo = db();
$ref = (string)($_GET['ref'] ?? '');

// NUNCA confirmar pagamento aqui. Apenas mostrar estado atual.
$st = $pdo->prepare('SELECT id, referencia, estado, utilizador_id FROM marcacoes WHERE referencia = ? AND utilizador_id = ?');
$st->execute([$ref, $u['id']]);
$m = $st->fetch();
if (!$m) {
    flash_definir('erro', 'Marcação não encontrada.');
    redirecionar('area-pessoal.php');
}

$tituloPagina = 'Regresso do pagamento';
require __DIR__ . '/includes/cabecalho.php';
echo estilos_marcacao();
?>

<section class="interior-hero">
    <div class="interior-hero-top">
        <div class="interior-hero-kicker">
            <span class="kicker-num">Pagamento</span>
            <span class="kicker-line" aria-hidden="true"></span>
            <span>Regresso do gateway</span>
        </div>
        <span class="interior-hero-aside"><?= e($m['referencia']) ?></span>
    </div>
    <h1>Estado do pagamento<span class="ponto">.</span></h1>
    <div class="interior-hero-bottom">
        <p>Estamos a aguardar a confirmação segura do gateway. O estado abaixo atualiza nos próximos minutos.</p>
        <span>Ver estado ↓</span>
    </div>
</section>

<?= passos_marcacao(5) ?>

<div class="alerta alerta-info" role="note">
    <strong>Importante:</strong> esta página não confirma pagamentos. A confirmação só é válida quando recebida e validada pelo servidor através do gateway (webhook/notificação autenticada). Atualiza as tuas marcações ou notificações dentro de momentos.
</div>

<section class="cartao">
    <h2>Marcação <?= e($m['referencia']) ?></h2>
    <dl class="dados">
        <dt>Estado atual</dt><dd><strong><?= e(rotulo('marcacao', $m['estado'])) ?></strong></dd>
    </dl>
    <p class="ajuda">
        Se o estado ainda estiver pendente, aguarda alguns minutos e atualiza a página.
        Se o pagamento foi efetuado mas o estado não atualizar, contacta a clínica com a referência em cima.
    </p>
    <p style="margin-top: var(--esp-4);">
        <a class="botao" href="marcar-confirmacao.php?ref=<?= e(urlencode($m['referencia'])) ?>">Ver marcação</a>
        <a class="botao botao-secundario" href="meus-pagamentos.php">Ver pagamentos</a>
        <a class="botao botao-secundario" href="notificacoes.php">Ver notificações</a>
    </p>
</section>

<?php require __DIR__ . '/includes/rodape.php'; ?>