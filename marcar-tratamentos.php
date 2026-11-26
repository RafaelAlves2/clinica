<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/marcacao.php';

$u   = exigir_login();
$pdo = db();

$c         = carregar_marcacao($pdo, $u['id']);
$dentista  = $c['dentista'];
$pacientes = $c['pacientes'];
$prefixo   = $c['prefixo'];
$horarios  = $_SESSION['marcacao']['horarios'] ?? [];

foreach ($pacientes as $p) {
    if (!isset($horarios[(int)$p['id']])) {
        flash_definir('info', $c['avisos'] ? implode(' ', $c['avisos']) : 'Escolhe primeiro o horário de todas as pessoas.');
        redirecionar('marcar-calendario.php');
    }
}

$disponiveis = tratamentos_do_dentista($pdo, (int)$dentista['id']);

$inicial = (int)($_SESSION['marcacao']['tratamento_id'] ?? 0);
$sel = [];
foreach ($pacientes as $p) {
    $pid = (int)$p['id'];
    $sel[$pid] = $_SESSION['marcacao']['tratamentos'][$pid]
        ?? (isset($disponiveis[$inicial]) ? [$inicial] : []);
}

$erros = [];
$mostrarLinkCalendario = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $disponiveis) {
    csrf_validar();

    $escolhas = [];
    foreach ($pacientes as $p) {
        $pid = (int)$p['id'];
        $ids = array_values(array_unique(array_map('intval', (array)($_POST['trat'][$pid] ?? []))));
        $sel[$pid] = $ids;

        foreach ($ids as $id) {
            if (!isset($disponiveis[$id])) {
                $erros[] = 'Há um tratamento inválido escolhido para ' . $p['nome_completo'] . '.';
                continue 2;
            }
        }
        if ($ids === []) {
            $erros[] = 'Escolhe pelo menos um tratamento para ' . $p['nome_completo'] . '.';
            continue;
        }
        $escolhas[$pid] = $ids;
    }

    if ($erros === []) {
        $novasDuracoes = [];
        try {
            $pdo->beginTransaction();
            foreach ($escolhas as $pid => $ids) {
                $dur = totais_tratamentos($disponiveis, $ids)['duracao'];
                $novasDuracoes[$pid] = $dur;

                if ($dur !== (int)$horarios[$pid]['duracao']) {
                    try {
                        reservar_horario($pdo, (int)$dentista['id'], $pid, $horarios[$pid]['inicio'], $dur, $prefixo);
                    } catch (RuntimeException $e) {
                        $nome = $c['porId'][$pid]['nome_completo'];
                        $mostrarLinkCalendario = true;
                        throw new RuntimeException(
                            'Para ' . $nome . ': a duração total (' . formatar_duracao($dur) .
                            ') não cabe nesse horário. Escolhe outra hora no calendário ou menos tratamentos.'
                        );
                    }
                }
            }
            $pdo->commit();

            foreach ($escolhas as $pid => $ids) {
                $_SESSION['marcacao']['tratamentos'][$pid] = $ids;
                $_SESSION['marcacao']['horarios'][$pid]['duracao'] = $novasDuracoes[$pid];
            }
            renovar_reservas($pdo, $prefixo);
            redirecionar('marcar-resumo.php');
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $erros[] = $e->getMessage();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Erro nos tratamentos: ' . $e->getMessage());
            $erros[] = 'Ocorreu um erro. Tenta novamente.';
        }
    }
}

$tituloPagina = 'Marcar: tratamentos';
require __DIR__ . '/includes/cabecalho.php';
echo estilos_marcacao();
?>

<div class="dash-root">

    <div class="dash-page-top">
        <div>
            <span class="dash-kicker">Marcação · Passo 3</span>
            <h1>Tratamentos da marcação</h1>
            <p>Marcação com <strong><?= e($dentista['nome_completo']) ?></strong>. O tratamento escolhido no início é mantido (podes adicionar outros). A duração da consulta ajusta-se automaticamente.</p>
        </div>
    </div>

    <?= passos_marcacao(3) ?>

    <?php foreach ($erros as $erro): ?>
        <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
    <?php endforeach; ?>
    <?php if ($mostrarLinkCalendario): ?>
        <p><a class="botao botao-secundario" href="marcar-calendario.php">← Voltar ao calendário</a></p>
    <?php endif; ?>

    <?php if (!$disponiveis): ?>
        <div class="alerta alerta-info">
            Este dentista ainda não tem tratamentos associados. Escolhe outro dentista ou volta mais tarde.
            <a href="dentistas.php?modo=marcar">Ver dentistas</a>
        </div>
    <?php else: ?>
        <form method="post" action="marcar-tratamentos.php" novalidate>
            <?= csrf_campo() ?>

            <?php foreach ($pacientes as $p): $pid = (int)$p['id']; $ini = utc($horarios[$pid]['inicio']); ?>
                <fieldset class="campo">
                    <legend><?= e($p['nome_completo']) ?>
                        <span class="ajuda">(<?= e(data_extenso($ini)) ?>, <?= e($ini->format('H:i')) ?>)</span>
                    </legend>
                    <div class="checks">
                        <?php foreach ($disponiveis as $t): ?>
                            <label class="check">
                                <input type="checkbox" name="trat[<?= $pid ?>][]" value="<?= (int)$t['id'] ?>"
                                       <?= in_array((int)$t['id'], $sel[$pid], true) ? 'checked' : '' ?>>
                                <span>
                                    <?= e($t['nome']) ?>
                                    <span class="ajuda">
                                        — <?= $t['tipo_preco'] === 'fixo' ? '' : 'estimado ' ?><?= e(formatar_preco($t['preco'])) ?>,
                                        <?= e(formatar_duracao((int)$t['duracao_min'])) ?>
                                        <?= $t['tipo_preco'] === 'fixo' ? '' : '(sujeito a avaliação)' ?>
                                    </span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>

            <a class="botao botao-secundario" href="marcar-calendario.php">← Horário</a>
            <button type="submit" class="botao">Ver resumo →</button>
        </form>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>