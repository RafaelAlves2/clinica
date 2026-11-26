<?php
declare(strict_types=1);
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/marcacao.php';
require __DIR__ . '/../includes/conta.php';
require __DIR__ . '/../includes/gestao.php';
require __DIR__ . '/../includes/pagamentos.php';

$u     = exigir_login(['admin', 'rececao', 'dentista']);
$pdo   = db();
$papel = $u['papel'];
$staff = in_array($papel, ['admin', 'rececao'], true);   // receção e admin gerem tudo

// Um dentista só vê a sua própria agenda
$dentistaProprio = null;
if ($papel === 'dentista') {
    $dentistaProprio = dentista_do_utilizador($pdo, $u['id']);
    if ($dentistaProprio === null) {
        $tituloPagina = 'Agenda';
        require __DIR__ . '/../includes/cabecalho.php';
        echo '<div class="alerta alerta-info">A tua conta ainda não está associada a um perfil de dentista. Pede ao administrador para a associar.</div>';
        require __DIR__ . '/../includes/rodape.php';
        exit;
    }
}

limpar_reservas_expiradas($pdo);
$agoraDT = agora_bd($pdo);
$agora   = $agoraDT->format('Y-m-d H:i:s');

// ---------- Filtros ----------
function ler_data_filtro(string $texto, string $padrao): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $texto, new DateTimeZone('UTC'));
    return ($d !== false && $d->format('Y-m-d') === $texto) ? $texto : $padrao;
}
$origem = $_POST !== [] ? $_POST : $_GET;
$de  = ler_data_filtro((string)($origem['de'] ?? ''), $agoraDT->format('Y-m-d'));
$ate = ler_data_filtro((string)($origem['ate'] ?? ''), $de);
if ($ate < $de) { $ate = $de; }
if (utc($ate . ' 00:00:00') > utc($de . ' 00:00:00')->modify('+62 days')) {
    $ate = utc($de . ' 00:00:00')->modify('+62 days')->format('Y-m-d');   // no máximo ~2 meses de cada vez
}
$filtroDentista = $dentistaProprio ?? (int)($origem['dentista'] ?? 0);
$filtroEstado   = (string)($origem['estado'] ?? 'ativas');
if (!in_array($filtroEstado, ['ativas', 'concluida', 'cancelada', 'todas'], true)) { $filtroEstado = 'ativas'; }

$voltar = 'admin/marcacoes.php?' . http_build_query(['de' => $de, 'ate' => $ate, 'dentista' => $filtroDentista ?: '', 'estado' => $filtroEstado]);

// ---------- Ações ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $acao = (string)($_POST['acao'] ?? '');

    try {
        if ($acao === 'concluir') {
            concluir_consulta($pdo, (int)($_POST['consulta'] ?? 0), $dentistaProprio);
            registar_admin('concluir_consulta', 'consultas', (int)($_POST['consulta'] ?? 0));
            flash_definir('sucesso', 'Consulta marcada como concluída.');
        } elseif ($acao === 'cancelar' && $staff) {
            $consultaId = (int)($_POST['consulta'] ?? 0);

            // O admin/receção cancela em nome do dono da marcação:
            // temos de descobrir quem é o utilizador dono antes de chamar a função,
            // porque cancelar_consulta() valida que o utilizadorId corresponde ao dono.
            $st = $pdo->prepare(
                'SELECT m.utilizador_id
                   FROM consultas c JOIN marcacoes m ON m.id = c.marcacao_id
                  WHERE c.id = ?'
            );
            $st->execute([$consultaId]);
            $donoId = $st->fetchColumn();
            if ($donoId === false) {
                throw new RuntimeException('Consulta não encontrada.');
            }

            $reembolso = cancelar_consulta($pdo, $consultaId, (int)$donoId, true);
            registar_admin('cancelar_consulta_clinica', 'consultas', $consultaId);
            flash_definir('sucesso', 'Consulta cancelada e paciente avisado.'
                . ($reembolso > 0 ? ' Reembolso registado: ' . formatar_preco($reembolso) . ' (demonstração).' : ''));
        } elseif ($acao === 'pagamento' && $staff) {
            $valor = round((float)str_replace(',', '.', (string)($_POST['valor'] ?? '0')), 2);
            $r = registar_pagamento_presencial_detalhado($pdo, [
                'marcacao_id' => (int)($_POST['marcacao'] ?? 0),
                'valor' => $valor,
                'metodo_detalhe' => (string)($_POST['metodo_detalhe'] ?? 'dinheiro'),
                'consulta_id' => !empty($_POST['consulta_id']) ? (int)$_POST['consulta_id'] : null,
                'data_pagamento' => (string)($_POST['data_pagamento'] ?? ''),
                'observacoes' => (string)($_POST['observacoes'] ?? ''),
                'staff_id' => (int)$u['id'],
            ]);
            $resto = (float)$r['resto'];
            flash_definir('sucesso', 'Pagamento registado (' . $r['referencia'] . ').' . ($resto > 0.0001 ? ' Falta pagar ' . formatar_preco($resto) . '.' : ' Valor liquidado.'));
        } elseif ($acao === 'notas') {
            $consultaId = (int)($_POST['consulta'] ?? 0);
            $notas = trim((string)($_POST['notas_clinicas'] ?? ''));
            if (mb_strlen($notas) > 10000) { $notas = mb_substr($notas, 0, 10000); }
            if ($papel === 'dentista') {
                $stN = $pdo->prepare('SELECT dentista_id FROM consultas WHERE id = ?');
                $stN->execute([$consultaId]);
                $did = $stN->fetchColumn();
                if ($did === false || (int)$did !== (int)$dentistaProprio) {
                    throw new RuntimeException('Não tem permissão para editar estas notas.');
                }
            } elseif ($papel !== 'admin') {
                throw new RuntimeException('Não tem permissão para editar notas clínicas.');
            }
            $pdo->prepare('UPDATE consultas SET notas_clinicas = ? WHERE id = ?')->execute([$notas !== '' ? $notas : null, $consultaId]);
            registar_admin('atualizar_notas_clinicas', 'consultas', $consultaId);
            flash_definir('sucesso', 'Notas clínicas atualizadas.');
        } else {
            flash_definir('erro', 'Ação não permitida.');
        }
    } catch (RuntimeException $e) {
        flash_definir('erro', $e->getMessage());
    } catch (PDOException $e) {
        error_log('Erro na agenda: ' . $e->getMessage());
        flash_definir('erro', 'Ocorreu um erro. Tenta novamente.');
    }
    redirecionar($voltar);
}

// ---------- Consultas do período ----------
$sql = 'SELECT c.id, c.inicio, c.fim, c.duracao_min, c.estado, c.preco_total, c.dentista_id, c.notas_clinicas,
               p.nome_completo AS paciente, p.data_nascimento,
               d.nome_completo AS dentista,
               m.id AS marcacao_id, m.referencia, m.estado AS marcacao_estado, m.total_previsto, m.tem_preco_estimado,
               ut.nome_completo AS contacto_nome, ut.telemovel AS contacto_tel
          FROM consultas c
          JOIN marcacoes m ON m.id = c.marcacao_id
          JOIN pacientes p ON p.id = c.paciente_id
          JOIN dentistas d ON d.id = c.dentista_id
          JOIN utilizadores ut ON ut.id = m.utilizador_id
         WHERE c.inicio >= ? AND c.inicio < ?';
$params = [$de . ' 00:00:00', utc($ate . ' 00:00:00')->modify('+1 day')->format('Y-m-d H:i:s')];

if ($filtroDentista > 0) { $sql .= ' AND c.dentista_id = ?'; $params[] = $filtroDentista; }
if ($filtroEstado === 'ativas')         { $sql .= " AND c.estado IN ('pendente','confirmada')"; }
elseif ($filtroEstado === 'concluida')  { $sql .= " AND c.estado = 'concluida'"; }
elseif ($filtroEstado === 'cancelada')  { $sql .= " AND c.estado = 'cancelada'"; }
$sql .= ' ORDER BY c.inicio, d.nome_completo LIMIT 300';

$st = $pdo->prepare($sql);
$st->execute($params);
$consultas = $st->fetchAll();

// Tratamentos e dinheiro (em lote)
$tratDe = [];
$falta  = [];
if ($consultas) {
    $ids = array_column($consultas, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT ct.consulta_id, t.nome, ct.preco_estimado
           FROM consultas_tratamentos ct JOIN tratamentos t ON t.id = ct.tratamento_id
          WHERE ct.consulta_id IN ($in) ORDER BY t.nome"
    );
    $st->execute($ids);
    foreach ($st as $r) { $tratDe[$r['consulta_id']][] = $r; }

    if ($staff) {
        foreach (array_unique(array_column($consultas, 'marcacao_id')) as $mid) {
            $falta[(int)$mid] = por_pagar($pdo, (int)$mid);
        }
    }
}

$listaDentistas = $staff ? $pdo->query('SELECT id, nome_completo FROM dentistas ORDER BY nome_completo')->fetchAll() : [];

// Agrupa por dia
$porDia = [];
foreach ($consultas as $c) { $porDia[substr($c['inicio'], 0, 10)][] = $c; }

$pedidosPendentes = $staff ? (int)$pdo->query("SELECT COUNT(*) FROM pedidos_alteracao WHERE estado = 'pendente'")->fetchColumn() : 0;

$tituloPagina = 'Agenda';
require __DIR__ . '/../includes/cabecalho.php';
echo estilos_gestao();
?>

<p><a href="admin/index.php">← Painel</a></p>
<h1><?= $dentistaProprio ? 'A minha agenda' : 'Agenda e marcações' ?></h1>

<?php if ($pedidosPendentes > 0): ?>
    <div class="alerta alerta-info" role="status">
        Há <?= $pedidosPendentes ?> pedido(s) de alteração por tratar. <a href="admin/pedidos.php">Ver pedidos</a>
    </div>
<?php endif; ?>

<form method="get" action="admin/marcacoes.php" class="filtros-gestao">
    <div class="campo">
        <label for="de">De</label>
        <input type="date" id="de" name="de" value="<?= e($de) ?>">
    </div>
    <div class="campo">
        <label for="ate">Até</label>
        <input type="date" id="ate" name="ate" value="<?= e($ate) ?>">
    </div>
    <?php if ($staff): ?>
    <div class="campo">
        <label for="dentista">Dentista</label>
        <select id="dentista" name="dentista">
            <option value="0">Todos</option>
            <?php foreach ($listaDentistas as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= $filtroDentista === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['nome_completo']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="campo">
        <label for="estado">Estado</label>
        <select id="estado" name="estado">
            <?php foreach (['ativas' => 'Por realizar', 'concluida' => 'Concluídas', 'cancelada' => 'Canceladas', 'todas' => 'Todas'] as $v => $t): ?>
                <option value="<?= e($v) ?>" <?= $filtroEstado === $v ? 'selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="campo"><button type="submit" class="botao">Filtrar</button></div>
</form>

<?php if (!$consultas): ?>
    <div class="alerta alerta-info">Não há consultas neste período com estes filtros.</div>
<?php endif; ?>

<?php foreach ($porDia as $dia => $lista): ?>
    <section class="seccao">
        <h2><?= e(data_extenso(utc($dia . ' 00:00:00'))) ?> <span class="ajuda">(<?= count($lista) ?>)</span></h2>
        <div class="grelha grelha-larga">
        <?php foreach ($lista as $c):
            $ini = utc($c['inicio']); $fim = utc($c['fim']);
            $idade = $c['data_nascimento'] ? (int)utc($c['data_nascimento'] . ' 00:00:00')->diff($agoraDT)->y : null;
            $podeConcluir = $c['estado'] === 'confirmada' && $c['inicio'] <= $agora;
            $podeCancelar = $staff && in_array($c['estado'], ['pendente', 'confirmada'], true) && $c['inicio'] > $agora;
            $faltaMarc = $falta[(int)$c['marcacao_id']] ?? 0.0;
        ?>
            <article class="cartao linha-consulta">
                <strong><?= e($ini->format('H:i')) ?> – <?= e($fim->format('H:i')) ?>
                    <span class="estado-etiqueta estado-<?= e($c['estado']) ?>"><?= e(rotulo('marcacao', $c['estado'])) ?></span>
                </strong>
                <span><?= e($c['paciente']) ?><?= $idade !== null ? ' (' . $idade . ' anos)' : '' ?></span>
                <?php if (!$dentistaProprio): ?><span class="ajuda">Dentista: <?= e($c['dentista']) ?></span><?php endif; ?>
                <?php if (!empty($tratDe[$c['id']])): ?>
                    <span class="ajuda">Tratamentos: <?= e(implode(', ', array_map(
                        fn($t) => $t['nome'] . ((int)$t['preco_estimado'] ? ' (preço a confirmar)' : ''), $tratDe[$c['id']]))) ?></span>
                <?php endif; ?>

                <?php if ($staff): ?>
                    <span class="ajuda">Contacto: <?= e($c['contacto_nome']) ?> · <?= e($c['contacto_tel']) ?></span>
                    <span class="ajuda">Marcação <?= e($c['referencia']) ?> · total <?= e(formatar_preco($c['total_previsto'])) ?>
                        · <?= $faltaMarc > 0 ? 'por pagar ' . e(formatar_preco($faltaMarc)) : 'paga' ?></span>
                <?php endif; ?>

                <div class="acoes">
                    <?php if ($podeConcluir): ?>
                        <form method="post" action="admin/marcacoes.php">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="acao" value="concluir">
                            <input type="hidden" name="consulta" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="de" value="<?= e($de) ?>"><input type="hidden" name="ate" value="<?= e($ate) ?>">
                            <input type="hidden" name="dentista" value="<?= (int)$filtroDentista ?>"><input type="hidden" name="estado" value="<?= e($filtroEstado) ?>">
                            <button type="submit" class="botao">Marcar como concluída</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($podeCancelar): ?>
                        <form method="post" action="admin/marcacoes.php" onsubmit="return confirm('Cancelar esta consulta e avisar o paciente?');">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="acao" value="cancelar">
                            <input type="hidden" name="consulta" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="de" value="<?= e($de) ?>"><input type="hidden" name="ate" value="<?= e($ate) ?>">
                            <input type="hidden" name="dentista" value="<?= (int)$filtroDentista ?>"><input type="hidden" name="estado" value="<?= e($filtroEstado) ?>">
                            <button type="submit" class="botao botao-secundario">Cancelar</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($staff && $faltaMarc > 0 && in_array($c['marcacao_estado'], ['confirmada', 'concluida'], true)): ?>
                        <details style="width:100%"><summary>Registar pagamento presencial (falta <?= e(formatar_preco($faltaMarc)) ?>)</summary>
                        <form method="post" action="admin/marcacoes.php" style="margin-top:.4rem;display:grid;gap:.35rem">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="acao" value="pagamento">
                            <input type="hidden" name="marcacao" value="<?= (int)$c['marcacao_id'] ?>">
                            <input type="hidden" name="consulta_id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="de" value="<?= e($de) ?>"><input type="hidden" name="ate" value="<?= e($ate) ?>">
                            <input type="hidden" name="dentista" value="<?= (int)$filtroDentista ?>"><input type="hidden" name="estado" value="<?= e($filtroEstado) ?>">
                            <label class="ajuda" for="v<?= (int)$c['id'] ?>">Valor (€)</label>
                            <input type="number" id="v<?= (int)$c['id'] ?>" name="valor" step="0.01" min="0.01" max="<?= e((string)$faltaMarc) ?>" value="<?= e(number_format($faltaMarc, 2, '.', '')) ?>" required>
                            <label class="ajuda" for="md<?= (int)$c['id'] ?>">Método</label>
                            <select id="md<?= (int)$c['id'] ?>" name="metodo_detalhe">
                                <?php foreach (pagamentos_metodos_presenciais() as $mk=>$ml): ?><option value="<?= e($mk) ?>"><?= e($ml) ?></option><?php endforeach; ?>
                            </select>
                            <label class="ajuda" for="dp<?= (int)$c['id'] ?>">Data/hora</label>
                            <input type="datetime-local" id="dp<?= (int)$c['id'] ?>" name="data_pagamento" value="<?= e(date('Y-m-d\TH:i')) ?>">
                            <label class="ajuda" for="ob<?= (int)$c['id'] ?>">Observações</label>
                            <input type="text" id="ob<?= (int)$c['id'] ?>" name="observacoes" maxlength="255" placeholder="Opcional">
                            <button type="submit" class="botao botao-secundario">Registar pagamento</button>
                        </form></details>
                    <?php endif; ?>

                    <?php $podeNotas = (($papel === 'dentista' && $dentistaProprio && (int)$c['dentista_id'] === (int)$dentistaProprio) || $papel === 'admin'); ?>
                    <?php if ($podeNotas): ?>
                        <details style="margin-top:.5rem;width:100%"><summary>Notas clínicas <?= !empty($c['notas_clinicas']) ? '(registadas)' : '' ?></summary>
                            <?php if (!empty($c['notas_clinicas'])): ?><div class="ajuda" style="white-space:pre-wrap;margin:.5rem 0"><?= e($c['notas_clinicas']) ?></div><?php endif; ?>
                            <form method="post" action="admin/marcacoes.php"><?= csrf_campo() ?>
                                <input type="hidden" name="acao" value="notas"><input type="hidden" name="consulta" value="<?= (int)$c['id'] ?>">
                                <input type="hidden" name="de" value="<?= e($de) ?>"><input type="hidden" name="ate" value="<?= e($ate) ?>">
                                <input type="hidden" name="dentista" value="<?= (int)$filtroDentista ?>"><input type="hidden" name="estado" value="<?= e($filtroEstado) ?>">
                                <div class="campo"><label for="notas<?= (int)$c['id'] ?>">Registo clínico (acesso restrito)</label>
                                <textarea id="notas<?= (int)$c['id'] ?>" name="notas_clinicas" rows="4" maxlength="10000"><?= e((string)($c['notas_clinicas'] ?? '')) ?></textarea></div>
                                <button type="submit" class="botao botao-secundario">Guardar notas</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<?php if (count($consultas) >= 300): ?>
    <div class="alerta alerta-info">Só se mostram as primeiras 300 consultas. Reduz o período ou usa os filtros.</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/rodape.php'; ?>