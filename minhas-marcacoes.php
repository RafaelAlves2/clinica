<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/marcacao.php';
require __DIR__ . '/includes/conta.php';

$u   = exigir_login();
$pdo = db();

limpar_reservas_expiradas($pdo);

// ---------- Cancelar uma consulta ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    if (($_POST['acao'] ?? '') === 'cancelar') {
        try {
            $reembolso = cancelar_consulta($pdo, (int)($_POST['consulta'] ?? 0), $u['id']);
            flash_definir('sucesso', 'Consulta cancelada.'
                . ($reembolso > 0 ? ' Reembolso registado: ' . formatar_preco($reembolso) . ' (demonstração).' : ''));
        } catch (RuntimeException $e) {
            flash_definir('erro', $e->getMessage());
        } catch (PDOException $e) {
            error_log('Erro ao cancelar consulta: ' . $e->getMessage());
            flash_definir('erro', 'Ocorreu um erro. Tenta novamente.');
        }
    }
    redirecionar('minhas-marcacoes.php');
}

$agora = agora_bd($pdo)->format('Y-m-d H:i:s');
$horasCancelar = config_valor($pdo, 'cancelamento_antecedencia_h', 24);
$limite = agora_bd($pdo)->modify('+' . $horasCancelar . ' hours')->format('Y-m-d H:i:s');

// ---------- Todas as consultas das marcações deste utilizador ----------
$st = $pdo->prepare(
    'SELECT c.id, c.inicio, c.fim, c.duracao_min, c.estado, c.preco_total,
            p.nome_completo AS paciente, d.nome_completo AS dentista,
            m.id AS marcacao_id, m.referencia, m.estado AS marcacao_estado
       FROM consultas c
       JOIN marcacoes m ON m.id = c.marcacao_id
       JOIN pacientes p ON p.id = c.paciente_id
       JOIN dentistas d ON d.id = c.dentista_id
      WHERE m.utilizador_id = ?
      ORDER BY c.inicio'
);
$st->execute([$u['id']]);
$todas = $st->fetchAll();

$proximas = $passadas = $canceladas = [];
foreach ($todas as $c) {
    if ($c['estado'] === 'cancelada') {
        $canceladas[] = $c;
    } elseif (in_array($c['estado'], ['pendente', 'confirmada'], true) && $c['inicio'] >= $agora) {
        $proximas[] = $c;
    } else {
        $passadas[] = $c;
    }
}
$passadas   = array_reverse($passadas);
$canceladas = array_reverse($canceladas);

// Tratamentos e pedidos de alteração pendentes
$tratDe = [];
$pedidoDe = [];
$avaliacaoDe = [];
if ($todas) {
    $ids = array_column($todas, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $st = $pdo->prepare(
        "SELECT ct.consulta_id, t.nome, ct.preco_final
           FROM consultas_tratamentos ct JOIN tratamentos t ON t.id = ct.tratamento_id
          WHERE ct.consulta_id IN ($in) ORDER BY t.nome"
    );
    $st->execute($ids);
    foreach ($st as $r) { $tratDe[$r['consulta_id']][] = $r; }

    $st = $pdo->prepare("SELECT consulta_id, novo_inicio FROM pedidos_alteracao WHERE estado = 'pendente' AND consulta_id IN ($in)");
    $st->execute($ids);
    foreach ($st as $r) { $pedidoDe[$r['consulta_id']] = $r['novo_inicio']; }

    $st = $pdo->prepare("SELECT consulta_id, estado FROM avaliacoes WHERE consulta_id IN ($in)");
    $st->execute($ids);
    foreach ($st as $r) { $avaliacaoDe[$r['consulta_id']] = $r['estado']; }
}

// Mapa estado→chave de filtro
function marcacao_estado_chave(string $estado): string
{
    if ($estado === 'cancelada') { return 'cancelada'; }
    if ($estado === 'concluida') { return 'passada'; }
    return 'futura';
}

// Marca visual (símbolo)
function marcacao_estado_simbolo(string $estado): string
{
    if ($estado === 'cancelada') { return '×'; }
    if ($estado === 'concluida') { return '✓'; }
    if ($estado === 'confirmada') { return '◉'; }
    return '◷';
}

// Cartão de uma consulta (linha de dashboard)
function mostrar_consulta(array $c, array $tratDe, array $pedidoDe, array $avaliacaoDe, string $limite, int $horas, bool $acoes): void
{
    $ini = utc($c['inicio']);
    $fim = utc($c['fim']);
    $chave = marcacao_estado_chave((string)$c['estado']);
    $simbolo = marcacao_estado_simbolo((string)$c['estado']);
    $searchTxt = strtolower($c['paciente'] . ' ' . $c['dentista'] . ' ' . $c['referencia'] . ' ' . $c['estado']);
    ?>
    <article class="dash-list-row"
             data-marcacao-estado="<?= e($chave) ?>"
             data-marcacao-search="<?= e($searchTxt) ?>">
        <span class="dash-list-mark" aria-hidden="true"><?= e($simbolo) ?></span>
        <div class="dash-list-copy">
            <strong>
                <?= e(data_extenso($ini)) ?> · <?= e($ini->format('H:i')) ?>–<?= e($fim->format('H:i')) ?>
            </strong>
            <span><?= e($c['paciente']) ?> · <?= e($c['dentista']) ?></span>
            <small>
                <?= e(rotulo('marcacao', $c['estado'])) ?> ·
                <a href="marcar-confirmacao.php?ref=<?= e(urlencode($c['referencia'])) ?>"><?= e($c['referencia']) ?></a>
                · <?= e(formatar_preco($c['preco_total'])) ?>
            </small>

            <?php if (!empty($tratDe[$c['id']])): ?>
                <small>Tratamentos: <?= e(implode(', ', array_column($tratDe[$c['id']], 'nome'))) ?></small>
            <?php endif; ?>

            <?php if (isset($pedidoDe[$c['id']])): ?>
                <small>Pedido de alteração pendente para <?= e((new DateTime($pedidoDe[$c['id']]))->format('d/m/Y H:i')) ?>.</small>
            <?php endif; ?>

            <?php if ($c['estado'] === 'concluida'): ?>
                <?php if (isset($avaliacaoDe[$c['id']])): ?>
                    <small>
                        Avaliação:
                        <?= $avaliacaoDe[$c['id']] === 'publicada' ? 'publicada' : ($avaliacaoDe[$c['id']] === 'rejeitada' ? 'não publicada' : 'pendente de moderação') ?>
                    </small>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="dash-list-end">
            <?php if ($c['estado'] === 'concluida' && !isset($avaliacaoDe[$c['id']])): ?>
                <a class="dash-list-action" href="avaliar.php?consulta=<?= (int)$c['id'] ?>">Avaliar</a>
            <?php endif; ?>

            <?php if ($acoes): ?>
                <?php if ($c['inicio'] >= $limite): ?>
                    <a class="dash-list-action" href="alterar-consulta.php?id=<?= (int)$c['id'] ?>">Alterar</a>
                    <form method="post" action="minhas-marcacoes.php" class="form-inline"
                          onsubmit="return confirm('Queres mesmo cancelar esta consulta?');" style="margin:0;">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="acao" value="cancelar">
                        <input type="hidden" name="consulta" value="<?= (int)$c['id'] ?>">
                        <button type="submit" class="dash-list-action dash-action-cancelar">
                            Cancelar
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </article>

    <?php if ($acoes && $c['inicio'] < $limite): ?>
        <p class="ajuda" style="margin:0 0 var(--esp-3); font-size:0.8rem;">
            Já não é possível cancelar nem alterar online (menos de <?= (int)$horas ?> horas). Contacta a clínica.
        </p>
    <?php endif; ?>
    <?php
}

$tituloPagina = 'As minhas marcações';
require __DIR__ . '/includes/cabecalho.php';
echo estilos_conta();
?>

<div class="dash-root">

    <div class="dash-page-top">
        <div>
            <span class="dash-kicker">As minhas marcações</span>
            <h1>Marcações</h1>
            <p>Consulta, altera ou cancela as tuas consultas. Cancelamento online com pelo menos <?= (int)$horasCancelar ?> horas de antecedência.</p>
        </div>
    </div>

    <?= submenu_conta('marcacoes', nao_lidas($pdo, $u['id'])) ?>

    <?php if (!$todas): ?>
        <div class="dash-empty">
            <span class="dash-empty-symbol" aria-hidden="true">◷</span>
            <h3>Ainda não tens marcações</h3>
            <p>Marca a tua primeira consulta e começa a cuidar do teu sorriso.</p>
            <a class="botao" href="dentistas.php?modo=marcar">Marcar consulta</a>
        </div>
    <?php else: ?>
        <div data-dashboard>
            <div class="dash-toolbar">
                <div class="dash-filter-tabs" role="tablist" aria-label="Filtrar marcações">
                    <button type="button" data-filtro="todas" class="is-active" aria-pressed="true">Todas</button>
                    <button type="button" data-filtro="futura" aria-pressed="false">Próximas</button>
                    <button type="button" data-filtro="passada" aria-pressed="false">Passadas</button>
                    <button type="button" data-filtro="cancelada" aria-pressed="false">Canceladas</button>
                </div>
                <label class="dash-search">
                    <span aria-hidden="true">⌕</span>
                    <input type="search" placeholder="Procurar por dentista ou referência" data-search-input aria-label="Procurar marcações">
                </label>
            </div>

            <p class="dash-filter-count"><span data-count><?= count($todas) ?></span> marcação(ões) visível(is)</p>

            <div class="dash-list">
                <?php foreach ($todas as $c): ?>
                    <?php mostrar_consulta($c, $tratDe, $pedidoDe, $avaliacaoDe, $limite, $horasCancelar, $c['estado'] !== 'cancelada' && $c['estado'] !== 'concluida'); ?>
                <?php endforeach; ?>
            </div>

            <div class="dash-empty" data-filter-empty hidden>
                <span class="dash-empty-symbol" aria-hidden="true">⌕</span>
                <h3>Nenhuma marcação corresponde</h3>
                <p>Experimenta outro filtro ou limpa a pesquisa.</p>
            </div>
        </div>
    <?php endif; ?>

</div>

<script src="js/dashboard.js" defer></script>

<?php require __DIR__ . '/includes/rodape.php'; ?>