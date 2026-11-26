<?php
declare(strict_types=1);
require __DIR__ . '/../includes/config.php';

$u   = exigir_login(['admin']);
$pdo = db();

/**
 * Lê os blocos horários enviados pelo formulário (b[dia][n][inicio|fim]).
 * Devolve [[dia, 'HH:MM', 'HH:MM'], ...] e acrescenta mensagens a $erros se algo estiver mal.
 */
function ler_blocos($entrada, array &$erros): array
{
    $entrada = is_array($entrada) ? $entrada : [];
    $hora    = '/^([01]\d|2[0-3]):[0-5]\d$/';
    $blocos  = [];

    for ($dia = 1; $dia <= 7; $dia++) {
        $linhas = [];
        for ($n = 0; $n < 2; $n++) {
            $i = trim((string)($entrada[$dia][$n]['inicio'] ?? ''));
            $f = trim((string)($entrada[$dia][$n]['fim'] ?? ''));
            if ($i === '' && $f === '') { continue; }

            if (!preg_match($hora, $i) || !preg_match($hora, $f)) {
                $erros[] = nome_dia($dia) . ': preenche a hora de início e a de fim (ex.: 09:00).';
                continue;
            }
            if ($f <= $i) {
                $erros[] = nome_dia($dia) . ': a hora de fim tem de ser depois da hora de início.';
                continue;
            }
            $linhas[] = [$i, $f];
        }

        usort($linhas, fn($a, $b) => strcmp($a[0], $b[0]));
        if (count($linhas) === 2 && $linhas[1][0] < $linhas[0][1]) {
            $erros[] = nome_dia($dia) . ': os dois períodos não se podem sobrepor.';
        }
        foreach ($linhas as $l) { $blocos[] = [$dia, $l[0], $l[1]]; }
    }
    return $blocos;
}

// Transforma linhas da BD em [dia][n] => [inicio, fim] para preencher o formulário
function blocos_para_form(array $linhas): array
{
    $v = [];
    foreach ($linhas as $l) {
        $v[(int)$l['dia_semana']][] = [substr((string)$l['hora_inicio'], 0, 5), substr((string)$l['hora_fim'], 0, 5)];
    }
    return $v;
}

$dentistas  = $pdo->query('SELECT id, nome_completo FROM dentistas WHERE ativo = 1 ORDER BY nome_completo')->fetchAll();
$idsValidos = array_map('intval', array_column($dentistas, 'id'));

// Quem estamos a editar: 'clinica' ou o id de um dentista
$alvo       = (string)($_POST['alvo'] ?? $_GET['alvo'] ?? 'clinica');
$dentistaId = 0;
$ehClinica  = $alvo === 'clinica';

if (!$ehClinica) {
    if (!ctype_digit($alvo) || (int)$alvo <= 0 || !in_array((int)$alvo, $idsValidos, true)) {
        flash_definir('erro', 'O dentista selecionado não existe ou está inativo. O horário da clínica não foi alterado.');
        redirecionar('admin/horarios.php');
    }
    $dentistaId = (int)$alvo;
    $alvo = (string)$dentistaId;
}

$erros   = [];
$aviso   = '';
$valores = null;

// ---------- Guardar ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $entrada = $_POST['b'] ?? [];
    $blocos  = ler_blocos($entrada, $erros);

    if ($erros === []) {
        try {
            $pdo->beginTransaction();

            if ($ehClinica) {
                $pdo->exec('DELETE FROM horarios_funcionamento');
                $ins = $pdo->prepare('INSERT INTO horarios_funcionamento (dia_semana, hora_inicio, hora_fim) VALUES (?, ?, ?)');
                foreach ($blocos as $b) { $ins->execute([$b[0], $b[1], $b[2]]); }
            } else {
                $pdo->prepare('DELETE FROM disponibilidades_dentista WHERE dentista_id = ?')->execute([$dentistaId]);
                $ins = $pdo->prepare('INSERT INTO disponibilidades_dentista (dentista_id, dia_semana, hora_inicio, hora_fim) VALUES (?, ?, ?, ?)');
                foreach ($blocos as $b) { $ins->execute([$dentistaId, $b[0], $b[1], $b[2]]); }
            }

            $pdo->commit();
            registar_admin('guardar_horarios', $ehClinica ? 'horarios_funcionamento' : 'disponibilidades_dentista',
                           $ehClinica ? null : $dentistaId, count($blocos) . ' blocos');
            flash_definir('sucesso', 'Horário guardado.');
            redirecionar('admin/horarios.php?alvo=' . $alvo);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Erro ao guardar horários: ' . $e->getMessage());
            $erros[] = 'Ocorreu um erro. Tenta novamente.';
        }
    }

    // Se houve erros, mantém o que o utilizador escreveu
    $valores = [];
    for ($dia = 1; $dia <= 7; $dia++) {
        for ($n = 0; $n < 2; $n++) {
            $valores[$dia][$n] = [
                (string)($entrada[$dia][$n]['inicio'] ?? ''),
                (string)($entrada[$dia][$n]['fim'] ?? ''),
            ];
        }
    }
}

// ---------- Carregar da base de dados ----------
if ($valores === null) {
    if (!$ehClinica && ($_GET['copiar'] ?? '') === '1') {
        // Pré-preenche com o horário da clínica (só fica guardado se carregares em Guardar)
        $linhas = $pdo->query('SELECT dia_semana, hora_inicio, hora_fim FROM horarios_funcionamento ORDER BY dia_semana, hora_inicio')->fetchAll();
        $aviso  = 'Horário da clínica copiado para o formulário. Carrega em «Guardar» para confirmar.';
    } elseif ($ehClinica) {
        $linhas = $pdo->query('SELECT dia_semana, hora_inicio, hora_fim FROM horarios_funcionamento ORDER BY dia_semana, hora_inicio')->fetchAll();
    } else {
        $st = $pdo->prepare('SELECT dia_semana, hora_inicio, hora_fim FROM disponibilidades_dentista WHERE dentista_id = ? ORDER BY dia_semana, hora_inicio');
        $st->execute([$dentistaId]);
        $linhas = $st->fetchAll();
    }
    $dadosForm = blocos_para_form($linhas);
    $valores   = [];
    for ($dia = 1; $dia <= 7; $dia++) {
        for ($n = 0; $n < 2; $n++) {
            $valores[$dia][$n] = $dadosForm[$dia][$n] ?? ['', ''];
        }
    }
}

$nomeAlvo = $ehClinica ? 'Clínica' : '';
foreach ($dentistas as $d) {
    if ((int)$d['id'] === $dentistaId) { $nomeAlvo = $d['nome_completo']; }
}

$tituloPagina = 'Horários';
require __DIR__ . '/../includes/cabecalho.php';
?>

<style>
    .tabela input[type="time"] {
        padding: .4rem .5rem; font-size: 1rem; font-family: inherit;
        border: 1px solid var(--borda); border-radius: 8px;
        background: var(--fundo); color: var(--texto);
    }
    .separadores { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1.5rem; }
</style>

<p><a href="admin/index.php">← Painel</a></p>
<h1>Horários</h1>

<nav class="separadores" aria-label="Escolher horário a editar">
    <a class="botao <?= $ehClinica ? '' : 'botao-secundario' ?>" href="admin/horarios.php?alvo=clinica">Clínica</a>
    <?php foreach ($dentistas as $d): ?>
        <a class="botao <?= (int)$d['id'] === $dentistaId ? '' : 'botao-secundario' ?>"
           href="admin/horarios.php?alvo=<?= (int)$d['id'] ?>"><?= e($d['nome_completo']) ?></a>
    <?php endforeach; ?>
</nav>

<section class="cartao">
    <h2><?= $ehClinica ? 'Horário de funcionamento da clínica' : 'Horário de ' . e($nomeAlvo) ?></h2>
    <p class="ajuda">
        Deixa em branco os dias em que está fechado. Para ter pausa de almoço, usa os dois períodos
        (ex.: 09:00–13:00 e 14:00–18:00).
        <?= $ehClinica ? '' : 'O calendário só mostra horas que estejam dentro do horário da clínica.' ?>
    </p>

    <?php if ($aviso !== ''): ?><div class="alerta alerta-info" role="status"><?= e($aviso) ?></div><?php endif; ?>
    <?php foreach ($erros as $erro): ?>
        <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
    <?php endforeach; ?>

    <form method="post" action="admin/horarios.php" novalidate>
        <?= csrf_campo() ?>
        <input type="hidden" name="alvo" value="<?= e($alvo) ?>">

        <div class="tabela-scroll">
            <table class="tabela">
                <thead>
                    <tr><th>Dia</th><th colspan="2">1.º período</th><th colspan="2">2.º período</th></tr>
                    <tr><th></th><th>Início</th><th>Fim</th><th>Início</th><th>Fim</th></tr>
                </thead>
                <tbody>
                <?php for ($dia = 1; $dia <= 7; $dia++): ?>
                    <tr>
                        <th scope="row"><?= e(nome_dia($dia)) ?></th>
                        <?php for ($n = 0; $n < 2; $n++): ?>
                            <td><input type="time" name="b[<?= $dia ?>][<?= $n ?>][inicio]"
                                       value="<?= e($valores[$dia][$n][0]) ?>"
                                       aria-label="<?= e(nome_dia($dia)) ?>, <?= $n + 1 ?>.º período, início"></td>
                            <td><input type="time" name="b[<?= $dia ?>][<?= $n ?>][fim]"
                                       value="<?= e($valores[$dia][$n][1]) ?>"
                                       aria-label="<?= e(nome_dia($dia)) ?>, <?= $n + 1 ?>.º período, fim"></td>
                        <?php endfor; ?>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <p>
            <button type="submit" class="botao">Guardar horário</button>
            <?php if (!$ehClinica): ?>
                <a class="botao botao-secundario" href="admin/horarios.php?alvo=<?= (int)$dentistaId ?>&amp;copiar=1">Copiar horário da clínica</a>
            <?php endif; ?>
        </p>
    </form>
</section>

<?php require __DIR__ . '/../includes/rodape.php'; ?>
