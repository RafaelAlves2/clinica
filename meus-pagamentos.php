<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/marcacao.php';
require __DIR__ . '/includes/conta.php';
require __DIR__ . '/includes/gestao.php';
require __DIR__ . '/includes/pagamentos.php';
require __DIR__ . '/includes/gateway.php';

$u = exigir_login();
$pdo = db();
limpar_reservas_expiradas($pdo);

// Resumo financeiro por marcação do próprio utilizador
$st = $pdo->prepare(
    "SELECT m.id, m.referencia, m.total_previsto, m.estado, m.tem_preco_estimado, m.criado_em,
            COALESCE(SUM(CASE WHEN p.estado IN ('pago','reembolsado','parcialmente_reembolsado') THEN GREATEST(0,p.valor-p.valor_reembolsado) ELSE 0 END),0) AS pago_liq,
            COALESCE(SUM(p.valor_reembolsado),0) AS reembolsado
       FROM marcacoes m
       LEFT JOIN pagamentos p ON p.marcacao_id = m.id
      WHERE m.utilizador_id = ?
      GROUP BY m.id
      ORDER BY m.criado_em DESC LIMIT 200"
);
$st->execute([$u['id']]);
$marcacoes = $st->fetchAll();

// Histórico de movimentos de pagamento do próprio utilizador
$st = $pdo->prepare(
    "SELECT p.id, p.marcacao_id, p.consulta_id, p.metodo, p.metodo_detalhe, p.valor, p.estado, p.valor_reembolsado,
            p.cartao_marca, p.cartao_ultimos4,
            p.data_pagamento, p.pago_em, p.reembolsado_em, p.criado_em, p.referencia_transacao, p.observacoes,
            m.referencia AS marcacao_ref
       FROM pagamentos p
       JOIN marcacoes m ON m.id = p.marcacao_id
      WHERE m.utilizador_id = ?
      ORDER BY COALESCE(p.data_pagamento, p.pago_em, p.criado_em) DESC, p.id DESC LIMIT 300"
);
$st->execute([$u['id']]);
$pagamentos = $st->fetchAll();

$onlineDisponivel = (int)config_valor($pdo, 'pagamento_online_ativo', 0) === 1
    && gateway_pagamento_disponivel($pdo);

$totalDivida = 0.0; $totalPago = 0.0;
foreach ($marcacoes as $m) {
    $div = max(0.0, round((float)$m['total_previsto'] - (float)$m['pago_liq'], 2));
    $totalDivida += $div;
    $totalPago += (float)$m['pago_liq'];
}

$tituloPagina = 'Pagamentos e dívidas';
require __DIR__ . '/includes/cabecalho.php';
echo estilos_conta();
?>

<div class="dash-root">

    <div class="dash-page-top">
        <div>
            <span class="dash-kicker">Pagamentos</span>
            <h1>Pagamentos e dívidas</h1>
            <p>Resumo do que está pago e do que falta regularizar por marcação.</p>
        </div>
    </div>

    <?= submenu_conta('pagamentos', nao_lidas($pdo, $u['id'])) ?>

    <div class="dash-stat-grid">
        <a href="#por-marcacao">
            <span>Total pago</span>
            <strong><?= e(formatar_preco($totalPago)) ?></strong>
            <small>Líquido de reembolsos</small>
        </a>
        <a href="#por-marcacao">
            <span>Em dívida</span>
            <strong><?= e(formatar_preco($totalDivida)) ?></strong>
            <small><?= $totalDivida > 0.0001 ? 'Por regularizar' : 'Tudo em dia' ?></small>
        </a>
    </div>

    <div class="dash-section-title" id="por-marcacao">
        <div>
            <span class="dash-kicker">Por marcação</span>
            <h2>Resumo <span>financeiro</span></h2>
        </div>
    </div>

    <?php if (!$marcacoes): ?>
        <div class="dash-empty">
            <span class="dash-empty-symbol" aria-hidden="true">€</span>
            <h3>Ainda não tens marcações</h3>
            <p>Quando marcares a primeira consulta, o resumo aparece aqui.</p>
            <a class="botao" href="marcar.php">Marcar consulta</a>
        </div>
    <?php else: ?>
        <div class="dash-list">
        <?php foreach ($marcacoes as $m):
            $total = round((float)$m['total_previsto'], 2);
            $pago = round((float)$m['pago_liq'], 2);
            $div = max(0.0, round($total - $pago, 2));
            $estado = $div <= 0.0001 && $total > 0 ? 'Pago' : ($pago > 0 ? 'Parcialmente pago' : 'Pendente');
            $pillClasse = $div <= 0.0001 && $total > 0
                ? 'dash-pill-completed'
                : ($pago > 0 ? 'dash-pill-working' : 'dash-pill-planning');
            $pillLabel = $div <= 0.0001 && $total > 0
                ? 'Pago'
                : ($pago > 0 ? 'Parcial' : 'Pendente');
        ?>
            <div class="dash-list-row">
                <span class="dash-list-mark" aria-hidden="true">€</span>
                <div class="dash-list-copy">
                    <strong><?= e($m['referencia']) ?></strong>
                    <span><?= e((new DateTime($m['criado_em']))->format('d/m/Y H:i')) ?> · <?= e(rotulo('marcacao', $m['estado'])) ?></span>
                    <small>
                        Total: <?= e(formatar_preco($total)) ?>
                        · Pago: <?= e(formatar_preco($pago)) ?>
                        <?php if ((float)$m['reembolsado'] > 0): ?>
                            (reembolsado <?= e(formatar_preco($m['reembolsado'])) ?>)
                        <?php endif; ?>
                        · Em dívida: <strong><?= e(formatar_preco($div)) ?></strong>
                    </small>
                </div>
                <div class="dash-list-end">
                    <span class="dash-pill <?= e($pillClasse) ?>">
                        <span class="dash-pill-dot" aria-hidden="true"></span>
                        <?= e($pillLabel) ?>
                    </span>
                    <?php if ($onlineDisponivel && $m['estado'] !== 'cancelada' && (int)$m['tem_preco_estimado'] !== 1 && $div > 0.0001): ?>
                        <a class="dash-list-action" href="checkout.php?ref=<?= e(urlencode($m['referencia'])) ?>">Pagar</a>
                    <?php endif; ?>
                    <a class="dash-list-action" href="marcar-confirmacao.php?ref=<?= e(urlencode($m['referencia'])) ?>">Ver</a>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="dash-section-title">
        <div>
            <span class="dash-kicker">Histórico</span>
            <h2>Movimentos de <span>pagamento</span></h2>
        </div>
    </div>

    <?php if (!$pagamentos): ?>
        <div class="dash-empty">
            <span class="dash-empty-symbol" aria-hidden="true">≡</span>
            <h3>Sem registos de pagamento</h3>
            <p>Os movimentos aparecem aqui depois do primeiro pagamento.</p>
        </div>
    <?php else: ?>
        <div class="tabela-scroll">
            <table class="tabela">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Marcação</th>
                        <th>Método</th>
                        <th>Valor</th>
                        <th>Estado</th>
                        <th>Referência</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pagamentos as $p): ?>
                    <tr>
                        <td><?= e((new DateTime($p['data_pagamento'] ?: $p['pago_em'] ?: $p['criado_em']))->format('d/m/Y H:i')) ?></td>
                        <td>
                            <a href="marcar-confirmacao.php?ref=<?= e(urlencode($p['marcacao_ref'])) ?>"><?= e($p['marcacao_ref']) ?></a>
                        </td>
                        <td>
                            <?= e(pagamento_metodo_legivel($p['metodo'], $p['metodo_detalhe'] ?? null)) ?>
                            <?php if (($p['cartao_marca'] ?? '') !== '' && ($p['cartao_ultimos4'] ?? '') !== ''): ?>
                                <br><span class="ajuda"><?= e(pagamento_cartao_legivel((string)$p['cartao_marca'], (string)$p['cartao_ultimos4'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e(formatar_preco($p['valor'])) ?>
                            <?php if ((float)$p['valor_reembolsado'] > 0): ?>
                                <br><span class="ajuda">Reemb. <?= e(formatar_preco($p['valor_reembolsado'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="etiqueta"><?= e(pagamento_estado_legivel($p['estado'])) ?></span></td>
                        <td><span class="ajuda"><?= e($p['referencia_transacao'] ?? '—') ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>