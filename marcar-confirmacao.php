<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/marcacao.php';
require __DIR__ . '/includes/pagamentos.php';
require __DIR__ . '/includes/gateway.php';

$u   = exigir_login();
$pdo = db();

limpar_reservas_expiradas($pdo);

$ref = (string)($_GET['ref'] ?? '');
$st = $pdo->prepare('SELECT * FROM marcacoes WHERE referencia = ? AND utilizador_id = ?');
$st->execute([$ref, $u['id']]);
$marc = $st->fetch();
if (!$marc) {
    flash_definir('erro', 'Marcação não encontrada.');
    redirecionar('area-pessoal.php');
}

$st = $pdo->prepare(
    'SELECT c.id, c.inicio, c.fim, c.duracao_min, c.estado, c.preco_total,
            p.nome_completo AS paciente, d.nome_completo AS dentista
       FROM consultas c
       JOIN pacientes p ON p.id = c.paciente_id
       JOIN dentistas d ON d.id = c.dentista_id
      WHERE c.marcacao_id = ?
      ORDER BY c.inicio, p.nome_completo'
);
$st->execute([$marc['id']]);
$consultas = $st->fetchAll();

$tratDe = [];
if ($consultas) {
    $ids = array_column($consultas, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare(
        "SELECT ct.consulta_id, ct.preco_original, ct.desconto, ct.preco_final, ct.preco_estimado, t.nome
           FROM consultas_tratamentos ct JOIN tratamentos t ON t.id = ct.tratamento_id
          WHERE ct.consulta_id IN ($in) ORDER BY t.nome"
    );
    $st->execute($ids);
    foreach ($st as $r) { $tratDe[$r['consulta_id']][] = $r; }
}

$st = $pdo->prepare('SELECT * FROM pagamentos WHERE marcacao_id = ? ORDER BY id');
$st->execute([$marc['id']]);
$pagamentos = $st->fetchAll();
$ultimo = $pagamentos ? end($pagamentos) : null;

$fin = estado_financeiro_marcacao($pdo, (int)$marc['id']);
$pago = (float)($fin['pago'] ?? 0);
$porPagar = (float)($fin['divida'] ?? 0);
$reembolsado = (float)($fin['reembolsado'] ?? 0);
$estadoFin = (string)($fin['estado_financeiro'] ?? '');

$tituloPagina = 'Confirmação da marcação';
require __DIR__ . '/includes/cabecalho.php';
echo estilos_marcacao();
?>

<div class="dash-root">

    <div class="dash-page-top">
        <div>
            <span class="dash-kicker">Confirmação</span>
            <h1>Detalhes da marcação</h1>
            <p>Referência <strong><?= e($marc['referencia']) ?></strong></p>
        </div>
    </div>

    <div class="confirmacao-hero" data-estado="<?= e($marc['estado']) ?>">
        <span class="confirmacao-hero-icon" aria-hidden="true">
            <?= $marc['estado'] === 'cancelada' ? '×' : ($marc['estado'] === 'confirmada' ? '✓' : '◷') ?>
        </span>
        <h1>Marcação <?= e(rotulo('marcacao', $marc['estado'])) ?></h1>
        <p>
            <?php if ($marc['estado'] === 'cancelada'): ?>
                Esta marcação foi cancelada. Podes marcar uma nova na área pessoal.
            <?php elseif ($marc['estado'] === 'confirmada'): ?>
                A tua marcação está confirmada. Vais receber uma notificação com os detalhes antes da consulta.
            <?php else: ?>
                A marcação aguarda confirmação do pagamento. Consulta abaixo os detalhes.
            <?php endif; ?>
        </p>
    </div>

    <section class="cartao seccao">
        <h2>Resumo</h2>
        <dl class="dados">
            <dt>Referência</dt><dd><strong><?= e($marc['referencia']) ?></strong></dd>
            <dt>Estado da marcação</dt><dd><?= e(rotulo('marcacao', $marc['estado'])) ?></dd>
            <dt>Estado do pagamento</dt><dd><?= $ultimo ? e(rotulo('pagamento', $ultimo['estado'])) : '—' ?></dd>
            <?php if ($ultimo): ?>
                <dt>Método</dt><dd><?= e(rotulo('metodo', $ultimo['metodo'])) ?></dd>
                <?php if (($ultimo['cartao_marca'] ?? '') !== '' && ($ultimo['cartao_ultimos4'] ?? '') !== ''): ?>
                    <dt>Cartão</dt><dd><?= e(pagamento_cartao_legivel((string)$ultimo['cartao_marca'], (string)$ultimo['cartao_ultimos4'])) ?></dd>
                <?php endif; ?>
            <?php endif; ?>
        </dl>
        <?php if ($marc['estado'] === 'cancelada' && $marc['motivo_cancelamento']): ?>
            <div class="alerta alerta-erro" role="alert"><?= e($marc['motivo_cancelamento']) ?></div>
        <?php endif; ?>
    </section>

    <?php foreach ($consultas as $c): $ini = utc($c['inicio']); $fim = utc($c['fim']); ?>
        <section class="cartao seccao">
            <h2><?= e($c['paciente']) ?></h2>
            <dl class="dados">
                <dt>Dentista</dt><dd><?= e($c['dentista']) ?></dd>
                <dt>Data</dt><dd><?= e(data_extenso($ini)) ?></dd>
                <dt>Hora</dt><dd><?= e($ini->format('H:i')) ?> – <?= e($fim->format('H:i')) ?> (<?= e(formatar_duracao((int)$c['duracao_min'])) ?>)</dd>
                <dt>Estado</dt><dd><?= e(rotulo('marcacao', $c['estado'])) ?></dd>
            </dl>
            <div class="tabela-scroll">
                <table class="tabela">
                    <thead><tr><th>Tratamento</th><th>Preço</th><th>Desconto</th><th>A pagar</th></tr></thead>
                    <tbody>
                    <?php foreach ($tratDe[$c['id']] ?? [] as $t): ?>
                        <tr>
                            <td><?= e($t['nome']) ?><?= (int)$t['preco_estimado'] ? ' <span class="ajuda">(estimado)</span>' : '' ?></td>
                            <td><?= e(formatar_preco($t['preco_original'])) ?></td>
                            <td><?= e(formatar_preco($t['desconto'])) ?></td>
                            <td><?= e(formatar_preco($t['preco_final'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>

    <section class="cartao seccao">
        <h2>Valores</h2>
        <dl class="dados">
            <dt>Total previsto</dt><dd><strong><?= e(formatar_preco($marc['total_previsto'])) ?></strong></dd>
            <dt>Estado financeiro</dt><dd><?= e($estadoFin) ?></dd>
            <dt>Já pago (líquido)</dt><dd><?= e(formatar_preco($pago)) ?></dd>
            <?php if ($reembolsado > 0.0001): ?>
                <dt>Reembolsado</dt><dd><?= e(formatar_preco($reembolsado)) ?></dd>
            <?php endif; ?>
            <?php if ($marc['estado'] !== 'cancelada'): ?>
                <dt>Por pagar</dt><dd><?= e(formatar_preco($porPagar)) ?></dd>
            <?php endif; ?>
        </dl>
        <?php if ($marc['tem_preco_estimado']): ?>
            <p class="ajuda">Alguns preços são estimados e serão confirmados pelo dentista após a avaliação clínica.</p>
        <?php endif; ?>
        <?php if ($marc['estado'] === 'confirmada' && $porPagar > 0.0001): ?>
            <p class="ajuda">Podes pagar online o valor em falta ou liquidá-lo na clínica, no dia da consulta.</p>
        <?php endif; ?>
    </section>

    <?php if ($marc['estado'] !== 'cancelada' && (int)$marc['tem_preco_estimado'] !== 1 && $porPagar > 0.0001
              && (int)config_valor($pdo, 'pagamento_online_ativo', 0) === 1
              && gateway_pagamento_disponivel($pdo)): ?>
        <p><a class="botao" href="checkout.php?ref=<?= e(urlencode($marc['referencia'])) ?>">Pagar online</a></p>
    <?php endif; ?>

    <p style="margin-top: var(--esp-5);">
        <a class="botao" href="area-pessoal.php">A minha área</a>
        <a class="botao botao-secundario" href="index.php">Página inicial</a>
    </p>

</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>