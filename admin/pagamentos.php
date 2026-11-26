<?php
declare(strict_types=1);
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/marcacao.php';
require __DIR__ . '/../includes/gestao.php';
require __DIR__ . '/../includes/pagamentos.php';

$u   = exigir_login(['admin', 'rececao']);
$pdo = db();
limpar_reservas_expiradas($pdo);

// Ações administrativas
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $acao = (string)($_POST['acao'] ?? '');
    try {
        if ($acao === 'reembolso') {
            $pagamentoId = (int)($_POST['pagamento_id'] ?? 0);
            $valor = round((float)str_replace(',', '.', (string)($_POST['valor'] ?? '0')), 2);
            $motivo = (string)($_POST['motivo'] ?? '');
            registar_reembolso_administrativo($pdo, $pagamentoId, $valor, $motivo, (int)$u['id']);
            flash_definir('sucesso', 'Reembolso registado administrativamente. A devolução pelo meio de pagamento, quando aplicável, depende do processo da clínica/prestador.');
        } elseif ($acao === 'registar_divida') {
            $marcacaoId = (int)($_POST['marcacao_id'] ?? 0);
            $valor = round((float)str_replace(',', '.', (string)($_POST['valor'] ?? '0')), 2);
            $metodoDetalhe = (string)($_POST['metodo_detalhe'] ?? 'dinheiro');
            $consultaId = !empty($_POST['consulta_id']) ? (int)$_POST['consulta_id'] : null;
            $dataPagamento = (string)($_POST['data_pagamento'] ?? '');
            $observacoes = (string)($_POST['observacoes'] ?? '');
            registar_pagamento_presencial_detalhado($pdo, [
                'marcacao_id' => $marcacaoId, 'valor' => $valor, 'metodo_detalhe' => $metodoDetalhe,
                'consulta_id' => $consultaId, 'data_pagamento' => $dataPagamento,
                'observacoes' => $observacoes, 'staff_id' => (int)$u['id'],
            ]);
            flash_definir('sucesso', 'Pagamento presencial registado e estado financeiro atualizado.');
        }
    } catch (Throwable $e) {
        error_log('Pagamento admin: ' . $e->getMessage());
        flash_definir('erro', $e instanceof RuntimeException ? $e->getMessage() : 'Ocorreu um erro. Tenta novamente.');
    }
    $back = (string)($_POST['voltar'] ?? 'admin/pagamentos.php');
    if (strpos($back, 'admin/') !== 0 && strpos($back, 'pagamentos.php') === false) { $back = 'admin/pagamentos.php'; }
    redirecionar($back);
}

// Detalhe
$detalheId = (int)($_GET['id'] ?? 0);
$detalhe = null;
$detalheMarcacaoFin = null;
if ($detalheId > 0) {
    $st = $pdo->prepare(
        "SELECT p.*, m.referencia AS marcacao_ref, m.total_previsto, m.estado AS marcacao_estado, m.utilizador_id AS dono_id,
                u.nome_completo AS cliente, u.email, c.inicio AS consulta_inicio, c.paciente_id, pac.nome_completo AS paciente_nome,
                staff.nome_completo AS registado_por_nome
           FROM pagamentos p
           JOIN marcacoes m ON m.id = p.marcacao_id
           JOIN utilizadores u ON u.id = m.utilizador_id
           LEFT JOIN consultas c ON c.id = p.consulta_id
           LEFT JOIN pacientes pac ON pac.id = c.paciente_id
           LEFT JOIN utilizadores staff ON staff.id = p.registado_por
          WHERE p.id = ?"
    );
    $st->execute([$detalheId]);
    $detalhe = $st->fetch() ?: null;
    if ($detalhe) { $detalheMarcacaoFin = estado_financeiro_marcacao($pdo, (int)$detalhe['marcacao_id']); }
}

// Filtros
$estado   = (string)($_GET['estado'] ?? 'todos');
$metodo   = (string)($_GET['metodo'] ?? 'todos');
$de       = (string)($_GET['de'] ?? '');
$ate      = (string)($_GET['ate'] ?? '');
$q        = trim((string)($_GET['q'] ?? ''));

$estadosValidos = ['todos', 'pendente', 'em_processamento', 'pago', 'parcialmente_pago', 'recusado', 'falhado', 'cancelado', 'reembolsado', 'parcialmente_reembolsado'];
$metodosValidos = ['todos', 'presencial', 'eletronico_antecipado', 'sinal', 'mbway', 'multibanco', 'cartao_online', 'outro'];
if (!in_array($estado, $estadosValidos, true)) { $estado = 'todos'; }
if (!in_array($metodo, $metodosValidos, true)) { $metodo = 'todos'; }

$where = [];
$params = [];
if ($estado !== 'todos') { $where[] = 'p.estado = ?'; $params[] = $estado; }
if ($metodo !== 'todos') { $where[] = 'p.metodo = ?'; $params[] = $metodo; }
if ($de !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) { $where[] = 'COALESCE(p.data_pagamento,p.pago_em,p.criado_em) >= ?'; $params[] = $de . ' 00:00:00'; }
if ($ate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) { $where[] = 'COALESCE(p.data_pagamento,p.pago_em,p.criado_em) < ?'; $params[] = date('Y-m-d', strtotime($ate . ' +1 day')) . ' 00:00:00'; }
if ($q !== '') { $where[] = '(m.referencia LIKE ? OR u.nome_completo LIKE ? OR u.email LIKE ? OR p.referencia_transacao LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like,$like,$like,$like); }

$sql = 'SELECT p.id, p.marcacao_id, p.consulta_id, p.metodo, p.metodo_detalhe, p.valor, p.estado, p.prestador, p.gateway, p.gateway_metodo,
               p.referencia_transacao, p.cartao_marca, p.cartao_ultimos4, p.valor_reembolsado, p.criado_em, p.pago_em, p.data_pagamento, p.registado_por, p.observacoes,
               m.referencia AS marcacao_ref, m.total_previsto, m.estado AS marcacao_estado,
               u.nome_completo AS cliente, u.email, staff.nome_completo AS staff_nome
          FROM pagamentos p
          JOIN marcacoes m ON m.id = p.marcacao_id
          JOIN utilizadores u ON u.id = m.utilizador_id
          LEFT JOIN utilizadores staff ON staff.id = p.registado_por'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY COALESCE(p.data_pagamento,p.pago_em,p.criado_em) DESC, p.id DESC LIMIT 600';
$st = $pdo->prepare($sql);
$st->execute($params);
$pagamentos = $st->fetchAll();

// Os cartões e totais são calculados sobre TODO o resultado filtrado.
// A tabela visual mostra apenas os 600 movimentos mais recentes.
$sqlBase = ' FROM pagamentos p
          JOIN marcacoes m ON m.id = p.marcacao_id
          JOIN utilizadores u ON u.id = m.utilizador_id
          LEFT JOIN utilizadores staff ON staff.id = p.registado_por';
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$st = $pdo->prepare('SELECT COUNT(*)' . $sqlBase . $whereSql);
$st->execute($params);
$totalRegistosFiltro = (int)$st->fetchColumn();

$st = $pdo->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN p.estado IN ('pago','parcialmente_reembolsado','reembolsado') THEN GREATEST(0,p.valor-p.valor_reembolsado) ELSE 0 END),0) recebido,
        COALESCE(SUM(CASE WHEN p.estado IN ('pendente','em_processamento','parcialmente_pago') THEN p.valor ELSE 0 END),0) pendente,
        COALESCE(SUM(p.valor_reembolsado),0) reembolsado,
        COALESCE(SUM(CASE WHEN p.estado IN ('recusado','falhado') THEN p.valor ELSE 0 END),0) falhado"
    . $sqlBase . $whereSql
);
$st->execute($params);
$totaisFiltro = $st->fetch() ?: [];
$totalRecebido = round((float)($totaisFiltro['recebido'] ?? 0), 2);
$totalPendente = round((float)($totaisFiltro['pendente'] ?? 0), 2);
$totalReembolsado = round((float)($totaisFiltro['reembolsado'] ?? 0), 2);
$totalFalhado = round((float)($totaisFiltro['falhado'] ?? 0), 2);

$st = $pdo->prepare(
    "SELECT p.metodo, p.metodo_detalhe,
            COALESCE(SUM(CASE WHEN p.estado IN ('pago','parcialmente_reembolsado','reembolsado') THEN GREATEST(0,p.valor-p.valor_reembolsado) ELSE 0 END),0) recebido
       " . $sqlBase . $whereSql . "
      GROUP BY p.metodo, p.metodo_detalhe
      ORDER BY recebido DESC"
);
$st->execute($params);
$porMetodo = [];
foreach ($st->fetchAll() as $linha) {
    $mk = pagamento_metodo_legivel($linha['metodo'], $linha['metodo_detalhe'] ?? null);
    $porMetodo[$mk] = (float)$linha['recebido'];
}
arsort($porMetodo);

// Dívida total (marcação) - consulta separada para refletir realidade, respeitando filtros de período apenas indiretamente? Melhor global claro.
$st = $pdo->query("SELECT COALESCE(SUM(GREATEST(0, m.total_previsto - sub.pago)),0) FROM marcacoes m LEFT JOIN (SELECT marcacao_id, SUM(GREATEST(0,valor-valor_reembolsado)) pago FROM pagamentos WHERE estado IN ('pago','reembolsado','parcialmente_reembolsado') GROUP BY marcacao_id) sub ON sub.marcacao_id=m.id WHERE m.estado IN ('confirmada','concluida')");
$totalDividaGlobal = round((float)$st->fetchColumn(), 2);

$tituloPagina = 'Pagamentos';
require __DIR__ . '/../includes/cabecalho.php';
echo estilos_gestao();
?>

<p><a href="admin/index.php">← Painel</a> · <a href="admin/financeiro.php">Relatório financeiro</a></p>
<h1>Pagamentos</h1>

<div class="grelha seccao">
    <div class="cartao texto-centro"><p class="numero"><?= e(formatar_preco($totalRecebido)) ?></p><p class="ajuda">Recebido (líquido, filtro)</p></div>
    <div class="cartao texto-centro"><p class="numero"><?= e(formatar_preco($totalPendente)) ?></p><p class="ajuda">Por receber (registos pendentes)</p></div>
    <div class="cartao texto-centro"><p class="numero"><?= e(formatar_preco($totalDividaGlobal)) ?></p><p class="ajuda">Dívida global (marcações ativas)</p></div>
    <div class="cartao texto-centro"><p class="numero"><?= e(formatar_preco($totalReembolsado)) ?></p><p class="ajuda">Reembolsado (registado)</p></div>
</div>

<?php if ($porMetodo): ?>
<section class="cartao seccao"><h2>Recebido por método (filtro atual)</h2>
    <div class="tabela-scroll"><table class="tabela"><thead><tr><th>Método</th><th>Valor líquido</th></tr></thead><tbody>
    <?php foreach ($porMetodo as $mk => $mv): if ($mv <= 0) continue; ?><tr><td><?= e($mk) ?></td><td><?= e(formatar_preco($mv)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endif; ?>

<form method="get" action="admin/pagamentos.php" class="filtros-gestao">
    <div class="campo"><label for="q">Pesquisar</label><input type="text" id="q" name="q" value="<?= e($q) ?>" placeholder="Referência, cliente, email"></div>
    <div class="campo"><label for="estado">Estado</label><select id="estado" name="estado"><?php foreach ($estadosValidos as $v): ?><option value="<?= e($v) ?>" <?= $estado===$v?'selected':'' ?>><?= $v==='todos'?'Todos':e(pagamento_estado_legivel($v)) ?></option><?php endforeach; ?></select></div>
    <div class="campo"><label for="metodo">Método</label><select id="metodo" name="metodo"><?php foreach ($metodosValidos as $v): ?><option value="<?= e($v) ?>" <?= $metodo===$v?'selected':'' ?>><?= $v==='todos'?'Todos':e(pagamento_metodo_legivel($v)) ?></option><?php endforeach; ?></select></div>
    <div class="campo"><label for="de">De</label><input type="date" id="de" name="de" value="<?= e($de) ?>"></div>
    <div class="campo"><label for="ate">Até</label><input type="date" id="ate" name="ate" value="<?= e($ate) ?>"></div>
    <div class="campo"><button type="submit" class="botao">Filtrar</button></div>
</form>

<?php if ($totalRegistosFiltro > count($pagamentos)): ?>
    <p class="ajuda">A mostrar os <?= count($pagamentos) ?> movimentos mais recentes de <?= $totalRegistosFiltro ?>. Os totais acima incluem todos os movimentos que correspondem aos filtros.</p>
<?php endif; ?>

<?php if ($detalhe): ?>
<section class="cartao seccao" id="detalhe">
    <h2>Detalhe do pagamento #<?= (int)$detalhe['id'] ?></h2>
    <dl class="dados">
        <dt>Cliente</dt><dd><?= e($detalhe['cliente']) ?> (<?= e($detalhe['email']) ?>)</dd>
        <dt>Marcação</dt><dd><a href="marcar-confirmacao.php?ref=<?= e(urlencode($detalhe['marcacao_ref'])) ?>"><?= e($detalhe['marcacao_ref']) ?></a> · <?= e(rotulo('marcacao',$detalhe['marcacao_estado'])) ?></dd>
        <dt>Consulta associada</dt><dd><?= $detalhe['consulta_id'] ? 'Consulta #'.(int)$detalhe['consulta_id'].($detalhe['consulta_inicio']?' · '.e((new DateTime($detalhe['consulta_inicio']))->format('d/m/Y H:i')):'').($detalhe['paciente_nome']?' · '.e($detalhe['paciente_nome']):'') : '— (nível marcação)' ?></dd>
        <dt>Valor</dt><dd><strong><?= e(formatar_preco($detalhe['valor'])) ?></strong><?php if ((float)$detalhe['valor_reembolsado']>0): ?> · reembolsado <?= e(formatar_preco($detalhe['valor_reembolsado'])) ?><?php endif; ?></dd>
        <dt>Método</dt><dd><?= e(pagamento_metodo_legivel($detalhe['metodo'], $detalhe['metodo_detalhe'] ?? null)) ?><?php if ($detalhe['gateway']): ?> · gateway <?= e($detalhe['gateway']) ?> (<?= e($detalhe['gateway_metodo'] ?? '—') ?>)<?php endif; ?></dd>
        <dt>Cartão</dt><dd><?= ($detalhe['cartao_marca'] ?? '') !== '' && ($detalhe['cartao_ultimos4'] ?? '') !== '' ? e(pagamento_cartao_legivel((string)$detalhe['cartao_marca'], (string)$detalhe['cartao_ultimos4'])) : '— (sem dados de cartão guardados)' ?></dd>
        <dt>Estado</dt><dd><span class="estado-etiqueta"><?= e(pagamento_estado_legivel($detalhe['estado'])) ?></span></dd>
        <dt>Quando</dt><dd>Registo: <?= e((new DateTime($detalhe['criado_em']))->format('d/m/Y H:i')) ?><?php if ($detalhe['data_pagamento']): ?> · Pagamento: <?= e((new DateTime($detalhe['data_pagamento']))->format('d/m/Y H:i')) ?><?php elseif($detalhe['pago_em']): ?> · Pago: <?= e((new DateTime($detalhe['pago_em']))->format('d/m/Y H:i')) ?><?php endif; ?></dd>
        <dt>Referência transação</dt><dd><?= e($detalhe['referencia_transacao'] ?? '—') ?><?php if ($detalhe['idempotency_key']): ?> · idempotência: <span class="ajuda"><?= e($detalhe['idempotency_key']) ?></span><?php endif; ?></dd>
        <dt>Registado por</dt><dd><?= e($detalhe['registado_por_nome'] ?? '— (automático/cliente)') ?></dd>
        <dt>Observações</dt><dd><?= $detalhe['observacoes'] ? nl2br(e($detalhe['observacoes'])) : '—' ?></dd>
    </dl>
    <?php
    $st = $pdo->prepare(
        'SELECT pr.*, staff.nome_completo AS registado_por_nome
           FROM pagamentos_reembolsos pr
           LEFT JOIN utilizadores staff ON staff.id = pr.registado_por
          WHERE pr.pagamento_id = ?
          ORDER BY pr.reembolsado_em DESC, pr.id DESC'
    );
    $st->execute([(int)$detalhe['id']]);
    $movimentosReembolso = $st->fetchAll();
    ?>
    <h3>Histórico de reembolsos</h3>
    <?php if (!$movimentosReembolso): ?>
        <p class="ajuda">Ainda não existem movimentos de reembolso registados.</p>
    <?php else: ?>
        <div class="tabela-scroll"><table class="tabela">
            <thead><tr><th>Data</th><th>Origem</th><th>Valor</th><th>Responsável / motivo</th></tr></thead>
            <tbody>
            <?php foreach ($movimentosReembolso as $mov): ?>
                <tr>
                    <td><?= e((new DateTime($mov['reembolsado_em']))->format('d/m/Y H:i')) ?></td>
                    <td><?= e(match ($mov['origem']) { 'gateway' => 'Stripe', 'historico' => 'Histórico importado', default => 'Registo administrativo' }) ?></td>
                    <td><?= e(formatar_preco($mov['valor'])) ?></td>
                    <td><?= e(trim((string)($mov['registado_por_nome'] ?? '') . ' ' . (string)($mov['motivo'] ?? '')) ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <?php if ($detalheMarcacaoFin): ?>
        <p class="ajuda">Estado financeiro da marcação: total <?= e(formatar_preco($detalheMarcacaoFin['total'])) ?> · pago <?= e(formatar_preco($detalheMarcacaoFin['pago'])) ?> · dívida <?= e(formatar_preco($detalheMarcacaoFin['divida'])) ?> (<?= e($detalheMarcacaoFin['estado_financeiro']) ?>).</p>
    <?php endif; ?>
    <?php $dispReembolso = max(0.0, round((float)$detalhe['valor'] - (float)$detalhe['valor_reembolsado'], 2)); if (in_array($detalhe['estado'], ['pago','parcialmente_reembolsado'], true) && $dispReembolso > 0): ?>
        <details><summary>Registar reembolso administrativo</summary>
            <form method="post" action="admin/pagamentos.php" style="margin-top:.5rem">
                <?= csrf_campo() ?><input type="hidden" name="acao" value="reembolso"><input type="hidden" name="pagamento_id" value="<?= (int)$detalhe['id'] ?>"><input type="hidden" name="voltar" value="admin/pagamentos.php?id=<?= (int)$detalhe['id'] ?>">
                <div class="campo"><label for="rvalor">Valor a reembolsar (€)</label><input type="number" id="rvalor" name="valor" step="0.01" min="0.01" max="<?= e(number_format($dispReembolso,2,'.','')) ?>" value="<?= e(number_format($dispReembolso,2,'.','')) ?>" required></div>
                <div class="campo"><label for="rmotivo">Motivo/observações</label><input type="text" id="rmotivo" name="motivo" maxlength="200"></div>
                <p class="ajuda">Isto regista o reembolso no sistema. Não devolve automaticamente dinheiro por MB WAY/cartão/prestador; esse processo é operacional/contratual.</p>
                <button type="submit" class="botao botao-secundario">Registar reembolso</button>
            </form>
        </details>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!$pagamentos): ?>
    <div class="alerta alerta-info">Não há pagamentos com estes filtros.</div>
<?php else: ?>
    <div class="tabela-scroll">
        <table class="tabela">
            <thead><tr><th>Data</th><th>Cliente</th><th>Marcação</th><th>Método</th><th>Valor</th><th>Estado</th><th>Registado por</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pagamentos as $p): ?>
                <tr>
                    <td><?= e((new DateTime($p['data_pagamento'] ?: $p['pago_em'] ?: $p['criado_em']))->format('d/m/Y H:i')) ?></td>
                    <td><strong><?= e($p['cliente']) ?></strong><br><span class="ajuda"><?= e($p['email']) ?></span></td>
                    <td><a href="marcar-confirmacao.php?ref=<?= e(urlencode($p['marcacao_ref'])) ?>"><?= e($p['marcacao_ref']) ?></a><?php if ($p['consulta_id']): ?><br><span class="ajuda">Consulta #<?= (int)$p['consulta_id'] ?></span><?php endif; ?></td>
                    <td><?= e(pagamento_metodo_legivel($p['metodo'], $p['metodo_detalhe'] ?? null)) ?><?php if (($p['cartao_marca'] ?? '') !== '' && ($p['cartao_ultimos4'] ?? '') !== ''): ?><br><span class="ajuda"><?= e(pagamento_cartao_legivel((string)$p['cartao_marca'], (string)$p['cartao_ultimos4'])) ?></span><?php endif; ?></td>
                    <td><?= e(formatar_preco($p['valor'])) ?><?php if ((float)$p['valor_reembolsado'] > 0): ?><br><span class="ajuda">reemb. <?= e(formatar_preco($p['valor_reembolsado'])) ?></span><?php endif; ?></td>
                    <td><span class="estado-etiqueta"><?= e(pagamento_estado_legivel($p['estado'])) ?></span></td>
                    <td class="ajuda"><?= e($p['staff_nome'] ?? ($p['gateway'] ? 'Gateway: '. $p['gateway'] : '—')) ?></td>
                    <td><a class="botao botao-secundario" href="admin/pagamentos.php?id=<?= (int)$p['id'] ?>&estado=<?= e(urlencode($estado)) ?>&metodo=<?= e(urlencode($metodo)) ?>&de=<?= e(urlencode($de)) ?>&ate=<?= e(urlencode($ate)) ?>&q=<?= e(urlencode($q)) ?>#detalhe">Detalhe</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/rodape.php'; ?>
