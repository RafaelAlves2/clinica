<?php
declare(strict_types=1);
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/marcacao.php';
require __DIR__ . '/../includes/gestao.php';
require __DIR__ . '/../includes/pagamentos.php';

$u = exigir_login(['admin', 'rececao']);
$pdo = db();

$validarData = static function (string $valor, string $padrao): string {
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return $data && $data->format('Y-m-d') === $valor ? $valor : $padrao;
};
$de = $validarData((string)($_GET['de'] ?? ''), date('Y-m-01'));
$ate = $validarData((string)($_GET['ate'] ?? ''), date('Y-m-d'));
if ($de > $ate) { [$de, $ate] = [$ate, $de]; }
$deSql = $de . ' 00:00:00';
$ateSql = (new DateTimeImmutable($ate))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

$st = $pdo->prepare("SELECT COALESCE(SUM(p.valor),0)
    FROM pagamentos p
    WHERE p.estado IN ('pago','reembolsado','parcialmente_reembolsado')
      AND COALESCE(p.data_pagamento,p.pago_em,p.criado_em) >= ?
      AND COALESCE(p.data_pagamento,p.pago_em,p.criado_em) < ?");
$st->execute([$deSql, $ateSql]);
$recebidoBruto = round((float)$st->fetchColumn(), 2);

$st = $pdo->prepare("SELECT COALESCE(SUM(pr.valor),0)
    FROM pagamentos_reembolsos pr
    WHERE pr.reembolsado_em >= ? AND pr.reembolsado_em < ?");
$st->execute([$deSql, $ateSql]);
$reembolsadoPeriodo = round((float)$st->fetchColumn(), 2);

$st = $pdo->prepare("SELECT COALESCE(SUM(p.valor),0)
    FROM pagamentos p
    WHERE p.estado IN ('pendente','em_processamento','parcialmente_pago')
      AND p.criado_em >= ? AND p.criado_em < ?");
$st->execute([$deSql, $ateSql]);
$pendentePeriodo = round((float)$st->fetchColumn(), 2);

$st = $pdo->prepare("SELECT COALESCE(SUM(p.valor),0)
    FROM pagamentos p
    WHERE p.estado IN ('recusado','falhado')
      AND COALESCE(p.falhado_em,p.criado_em) >= ?
      AND COALESCE(p.falhado_em,p.criado_em) < ?");
$st->execute([$deSql, $ateSql]);
$falhadoPeriodo = round((float)$st->fetchColumn(), 2);

$tot = [
    'recebido_bruto' => $recebidoBruto,
    'recebido' => round($recebidoBruto - $reembolsadoPeriodo, 2),
    'pendente_reg' => $pendentePeriodo,
    'reembolsado' => $reembolsadoPeriodo,
    'falhado' => $falhadoPeriodo,
];

// Dívida global (marcações ativas) - não depende do período para ser útil
$st = $pdo->query("SELECT COALESCE(SUM(GREATEST(0,m.total_previsto - COALESCE(sub.pago,0))),0) FROM marcacoes m LEFT JOIN (SELECT marcacao_id, SUM(GREATEST(0,valor-valor_reembolsado)) pago FROM pagamentos WHERE estado IN ('pago','reembolsado','parcialmente_reembolsado') GROUP BY marcacao_id) sub ON sub.marcacao_id=m.id WHERE m.estado IN ('confirmada','concluida')");
$dividaGlobal = round((float)$st->fetchColumn(), 2);

// Recebimentos e reembolsos por método, cada um pela respetiva data.
$st = $pdo->prepare("SELECT p.metodo, p.metodo_detalhe, COUNT(*) n, COALESCE(SUM(p.valor),0) recebido
    FROM pagamentos p
    WHERE p.estado IN ('pago','reembolsado','parcialmente_reembolsado')
      AND COALESCE(p.data_pagamento,p.pago_em,p.criado_em) >= ?
      AND COALESCE(p.data_pagamento,p.pago_em,p.criado_em) < ?
    GROUP BY p.metodo, p.metodo_detalhe");
$st->execute([$deSql, $ateSql]);
$porMetodoMapa = [];
foreach ($st->fetchAll() as $linha) {
    $chave = (string)$linha['metodo'] . '|' . (string)($linha['metodo_detalhe'] ?? '');
    $porMetodoMapa[$chave] = [
        'metodo' => $linha['metodo'],
        'metodo_detalhe' => $linha['metodo_detalhe'],
        'n' => (int)$linha['n'],
        'recebido_bruto' => (float)$linha['recebido'],
        'reembolsado' => 0.0,
    ];
}
$st = $pdo->prepare("SELECT p.metodo, p.metodo_detalhe, COALESCE(SUM(pr.valor),0) reembolsado
    FROM pagamentos_reembolsos pr
    JOIN pagamentos p ON p.id = pr.pagamento_id
    WHERE pr.reembolsado_em >= ? AND pr.reembolsado_em < ?
    GROUP BY p.metodo, p.metodo_detalhe");
$st->execute([$deSql, $ateSql]);
foreach ($st->fetchAll() as $linha) {
    $chave = (string)$linha['metodo'] . '|' . (string)($linha['metodo_detalhe'] ?? '');
    if (!isset($porMetodoMapa[$chave])) {
        $porMetodoMapa[$chave] = [
            'metodo' => $linha['metodo'],
            'metodo_detalhe' => $linha['metodo_detalhe'],
            'n' => 0,
            'recebido_bruto' => 0.0,
            'reembolsado' => 0.0,
        ];
    }
    $porMetodoMapa[$chave]['reembolsado'] = (float)$linha['reembolsado'];
}
$porMetodo = array_values($porMetodoMapa);
foreach ($porMetodo as &$linha) {
    $linha['recebido'] = round((float)$linha['recebido_bruto'] - (float)$linha['reembolsado'], 2);
}
unset($linha);
usort($porMetodo, static fn(array $a, array $b): int => $b['recebido'] <=> $a['recebido']);

// Tratamentos mais rentáveis (receita líquida em consultas_tratamentos/preço final, período por data da consulta)
$st = $pdo->prepare("SELECT t.nome, COUNT(*) n,
    COALESCE(SUM(ct.preco_final),0) receita,
    COALESCE(SUM(ct.desconto),0) descontos
    FROM consultas_tratamentos ct
    JOIN tratamentos t ON t.id = ct.tratamento_id
    JOIN consultas c ON c.id = ct.consulta_id
    WHERE c.inicio >= ? AND c.inicio < ? AND c.estado <> 'cancelada'
    GROUP BY t.id, t.nome ORDER BY receita DESC LIMIT 15");
$st->execute([$deSql, $ateSql]);
$tratRentaveis = $st->fetchAll();

// Histórico financeiro por paciente (top por dívida/atividade)
$pacienteQ = trim((string)($_GET['paciente'] ?? ''));
$histPaciente = [];
if ($pacienteQ !== '') {
    // CORREÇÃO (A8): o total previsto soma consultas do próprio paciente (não duplica
    // marcações com várias consultas nem inclui consultas canceladas) e os pagamentos
    // só são atribuídos por consulta do paciente ou por marcação com um único paciente.
    // Pagamentos em marcações partilhadas sem consulta_id associada não são imputáveis.
    $st = $pdo->prepare("SELECT p.id, p.nome_completo, p.email, p.numero_utente_sns,
        COALESCE(SUM(c.preco_total),0) total_prev,
        COALESCE((SELECT SUM(GREATEST(0,pg.valor-pg.valor_reembolsado))
            FROM pagamentos pg
            WHERE pg.estado IN ('pago','reembolsado','parcialmente_reembolsado')
              AND (
                pg.consulta_id IN (SELECT cx.id FROM consultas cx WHERE cx.paciente_id=p.id)
                OR (
                    pg.consulta_id IS NULL
                    AND pg.marcacao_id IN (SELECT cy.marcacao_id FROM consultas cy WHERE cy.paciente_id=p.id)
                    AND (SELECT COUNT(DISTINCT cz.paciente_id) FROM consultas cz WHERE cz.marcacao_id=pg.marcacao_id)=1
                )
              )),0) pago
        FROM pacientes p
        LEFT JOIN consultas c ON c.paciente_id=p.id AND c.estado <> 'cancelada'
        WHERE p.nome_completo LIKE ? OR p.email LIKE ? OR p.numero_utente_sns LIKE ?
        GROUP BY p.id ORDER BY p.nome_completo LIMIT 30");
    $like = '%'.$pacienteQ.'%';
    $st->execute([$like,$like,$like]);
    $histPaciente = $st->fetchAll();
}

$tituloPagina = 'Relatório financeiro';
require __DIR__ . '/../includes/cabecalho.php';
echo estilos_gestao();
?>
<p><a href="admin/index.php">← Painel</a> · <a href="admin/pagamentos.php">Pagamentos</a></p>
<h1>Relatório financeiro</h1>

<form method="get" action="admin/financeiro.php" class="filtros-gestao">
    <div class="campo"><label for="de">De</label><input type="date" id="de" name="de" value="<?= e($de) ?>"></div>
    <div class="campo"><label for="ate">Até</label><input type="date" id="ate" name="ate" value="<?= e($ate) ?>"></div>
    <div class="campo"><label for="paciente">Histórico paciente</label><input type="text" id="paciente" name="paciente" value="<?= e($pacienteQ) ?>" placeholder="Nome, email ou nº utente SNS"></div>
    <div class="campo"><button type="submit" class="botao">Atualizar</button></div>
</form>

<div class="grelha seccao">
    <div class="cartao texto-centro"><p class="numero"><?= e(formatar_preco($tot['recebido_bruto'])) ?></p><p class="ajuda">Recebido bruto no período</p></div>
    <div class="cartao texto-centro"><p class="numero"><?= e(formatar_preco($tot['recebido'])) ?></p><p class="ajuda">Recebido líquido no período</p></div>
    <div class="cartao texto-centro"><p class="numero"><?= e(formatar_preco($tot['pendente_reg'])) ?></p><p class="ajuda">Pendente em registos (criados no período)</p></div>
    <div class="cartao texto-centro"><p class="numero"><?= e(formatar_preco($dividaGlobal)) ?></p><p class="ajuda">Total em dívida (global)</p></div>
    <div class="cartao texto-centro"><p class="numero"><?= e(formatar_preco($tot['reembolsado'])) ?></p><p class="ajuda">Reembolsos registados</p></div>
    <div class="cartao texto-centro"><p class="numero"><?= e(formatar_preco($tot['falhado'])) ?></p><p class="ajuda">Falhados/recusados</p></div>
</div>

<section class="cartao seccao"><h2>Pagamentos por método</h2>
<?php if (!$porMetodo): ?><p class="ajuda">Sem dados no período.</p><?php else: ?>
<div class="tabela-scroll"><table class="tabela"><thead><tr><th>Método</th><th>Movimentos recebidos</th><th>Recebido bruto</th><th>Reembolsado no período</th><th>Recebido líquido</th></tr></thead><tbody>
<?php foreach ($porMetodo as $r): ?><tr><td><?= e(pagamento_metodo_legivel($r['metodo'], $r['metodo_detalhe'] ?? null)) ?></td><td><?= (int)$r['n'] ?></td><td><?= e(formatar_preco($r['recebido_bruto'])) ?></td><td><?= e(formatar_preco($r['reembolsado'])) ?></td><td><?= e(formatar_preco($r['recebido'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?></section>

<section class="cartao seccao"><h2>Tratamentos mais rentáveis</h2>
<p class="ajuda">Baseado em <code>consultas_tratamentos.preco_final</code> (após benefícios aplicados na marcação) para consultas não canceladas no período.</p>
<?php if (!$tratRentaveis): ?><p class="ajuda">Sem dados no período.</p><?php else: ?>
<div class="tabela-scroll"><table class="tabela"><thead><tr><th>Tratamento</th><th>Qtd.</th><th>Receita (final)</th><th>Descontos aplicados</th></tr></thead><tbody>
<?php foreach ($tratRentaveis as $r): ?><tr><td><?= e($r['nome']) ?></td><td><?= (int)$r['n'] ?></td><td><?= e(formatar_preco($r['receita'])) ?></td><td><?= e(formatar_preco($r['descontos'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?></section>

<section class="cartao seccao"><h2>Histórico financeiro de pacientes</h2>
<?php if ($pacienteQ === ''): ?><p class="ajuda">Pesquise por nome, email ou nº utente SNS para consultar histórico financeiro.</p>
<?php elseif (!$histPaciente): ?><p class="ajuda">Sem resultados.</p>
<?php else: ?>
<div class="tabela-scroll"><table class="tabela"><thead><tr><th>Paciente</th><th>Contacto</th><th>Nº utente SNS</th><th>Total previsto (consultas)</th><th>Pago (estimado)</th></tr></thead><tbody>
<?php foreach ($histPaciente as $p): ?><tr><td><?= e($p['nome_completo']) ?></td><td class="ajuda"><?= e($p['email'] ?? '—') ?></td><td><?= e($p['numero_utente_sns'] ?? '—') ?></td><td><?= e(formatar_preco($p['total_prev'])) ?></td><td><?= e(formatar_preco($p['pago'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p class="ajuda">Totais por consultas não canceladas. Pagamentos em marcações partilhadas sem consulta associada não são imputados a um paciente específico. Para detalhe por movimento use <a href="admin/pagamentos.php?q=<?= e(urlencode($pacienteQ)) ?>">Pagamentos</a> com o termo de pesquisa.</p>
<?php endif; ?></section>

<?php require __DIR__ . '/../includes/rodape.php'; ?>
