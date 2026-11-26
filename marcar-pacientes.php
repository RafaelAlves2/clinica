<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/marcacao.php';

$u   = exigir_login();
$pdo = db();

// ---------- Dentista escolhido ----------
$dentistaGet   = filter_input(INPUT_GET, 'dentista', FILTER_VALIDATE_INT) ?: 0;
$tratamentoGet = filter_input(INPUT_GET, 'tratamento', FILTER_VALIDATE_INT) ?: 0;

if ($dentistaGet > 0) {
    $st = $pdo->prepare('SELECT id FROM dentistas WHERE id = ? AND ativo = 1');
    $st->execute([$dentistaGet]);
    if (!$st->fetchColumn()) {
        flash_definir('erro', 'Esse dentista não está disponível.');
        redirecionar('dentistas.php?modo=marcar');
    }
    if (($_SESSION['marcacao']['dentista_id'] ?? 0) !== $dentistaGet) {
        if (!empty($_SESSION['marcacao']['token'])) {
            libertar_reservas($pdo, $_SESSION['marcacao']['token']);
        }
        $_SESSION['marcacao'] = ['dentista_id' => $dentistaGet, 'tratamento_id' => $tratamentoGet];
    } elseif ($tratamentoGet > 0) {
        $_SESSION['marcacao']['tratamento_id'] = $tratamentoGet;
    }
}

$dentistaId = (int)($_SESSION['marcacao']['dentista_id'] ?? 0);
if ($dentistaId === 0) {
    redirecionar('dentistas.php?modo=marcar');
}

$st = $pdo->prepare('SELECT id, nome_completo FROM dentistas WHERE id = ? AND ativo = 1');
$st->execute([$dentistaId]);
$dentista = $st->fetch();
if (!$dentista) {
    unset($_SESSION['marcacao']);
    redirecionar('dentistas.php?modo=marcar');
}

function pacientes_autorizados(PDO $pdo, int $utilizadorId): array
{
    $st = $pdo->prepare(
        "SELECT p.id, p.nome_completo, p.data_nascimento, a.relacao
           FROM autorizacoes_paciente a
           JOIN pacientes p ON p.id = a.paciente_id
          WHERE a.utilizador_id = ? AND a.pode_marcar = 1 AND p.ativo = 1
          ORDER BY (a.relacao = 'proprio') DESC, p.nome_completo"
    );
    $st->execute([$utilizadorId]);
    return $st->fetchAll();
}

$autorizados    = pacientes_autorizados($pdo, $u['id']);
$idsAutorizados = array_map('intval', array_column($autorizados, 'id'));

$st = $pdo->prepare('SELECT nome_completo, telemovel FROM utilizadores WHERE id = ?');
$st->execute([$u['id']]);
$conta = $st->fetch();

$contacto = $_SESSION['marcacao']['contacto'] ?? ['nome' => $conta['nome_completo'], 'telemovel' => $conta['telemovel']];
$escolhidos = $_SESSION['marcacao']['pacientes'] ?? [];
$errosFam   = [];
$errosCont  = [];
$formFam    = ['nome' => '', 'nascimento' => '', 'relacao' => 'filho', 'telemovel' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'adicionar_familiar') {
        $formFam['nome']       = trim((string)($_POST['nome'] ?? ''));
        $formFam['nascimento'] = trim((string)($_POST['nascimento'] ?? ''));
        $formFam['relacao']    = (string)($_POST['relacao'] ?? '');
        $formFam['telemovel']  = preg_replace('/\s+/', '', trim((string)($_POST['telemovel'] ?? '')));

        if (mb_strlen($formFam['nome']) < 3 || mb_strlen($formFam['nome']) > 150) {
            $errosFam[] = 'Indica o nome completo do familiar.';
        }
        $nasc = DateTime::createFromFormat('Y-m-d', $formFam['nascimento']);
        if ($nasc === false || $nasc->format('Y-m-d') !== $formFam['nascimento']
            || $formFam['nascimento'] > date('Y-m-d') || $formFam['nascimento'] < '1900-01-01') {
            $errosFam[] = 'Indica uma data de nascimento válida.';
        }
        if (!in_array($formFam['relacao'], ['filho', 'pai_mae', 'conjuge', 'outro'], true)) {
            $errosFam[] = 'Escolhe a relação com o familiar.';
        }
        if ($formFam['telemovel'] !== '' && !preg_match('/^\+?\d{9,15}$/', $formFam['telemovel'])) {
            $errosFam[] = 'O telemóvel do familiar não é válido (só números).';
        }

        if ($errosFam === []) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO pacientes (nome_completo, data_nascimento, telemovel) VALUES (?, ?, ?)')
                    ->execute([$formFam['nome'], $formFam['nascimento'], $formFam['telemovel'] ?: null]);
                $pid = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO autorizacoes_paciente (utilizador_id, paciente_id, relacao) VALUES (?, ?, ?)')
                    ->execute([$u['id'], $pid, $formFam['relacao']]);
                $pdo->commit();
                flash_definir('sucesso', 'Familiar adicionado. Já o podes escolher abaixo.');
                redirecionar('marcar-pacientes.php');
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('Erro ao adicionar familiar: ' . $e->getMessage());
                $errosFam[] = 'Ocorreu um erro. Tenta novamente.';
            }
        }
    }

    if ($acao === 'continuar') {
        $escolhidos = array_values(array_unique(array_map('intval', (array)($_POST['pacientes'] ?? []))));
        $contacto = [
            'nome'      => trim((string)($_POST['contacto_nome'] ?? '')),
            'telemovel' => preg_replace('/\s+/', '', trim((string)($_POST['contacto_telemovel'] ?? ''))),
        ];

        foreach ($escolhidos as $pid) {
            if (!in_array($pid, $idsAutorizados, true)) {
                $errosCont[] = 'Há pacientes escolhidos que não podes marcar.';
                break;
            }
        }
        if ($escolhidos === []) {
            $errosCont[] = 'Escolhe pelo menos uma pessoa para a consulta.';
        }
        if (count($escolhidos) > MAX_PACIENTES) {
            $errosCont[] = 'Podes marcar no máximo ' . MAX_PACIENTES . ' pessoas de cada vez.';
        }
        if (mb_strlen($contacto['nome']) < 3) {
            $errosCont[] = 'Indica o nome da pessoa de contacto.';
        }
        if (!preg_match('/^\+?\d{9,15}$/', $contacto['telemovel'])) {
            $errosCont[] = 'Indica um telemóvel de contacto válido (só números).';
        }

        if ($errosCont === []) {
            $prefixo = prefixo_reserva();
            $horarios = $_SESSION['marcacao']['horarios'] ?? [];
            foreach (array_keys($horarios) as $pidAntigo) {
                if (!in_array((int)$pidAntigo, $escolhidos, true)) {
                    libertar_reservas($pdo, $prefixo, (int)$pidAntigo);
                    unset($_SESSION['marcacao']['horarios'][$pidAntigo]);
                }
            }
            $_SESSION['marcacao']['pacientes'] = $escolhidos;
            $_SESSION['marcacao']['contacto']  = $contacto;
            redirecionar('marcar-calendario.php');
        }
    }
}

if ($escolhidos === [] && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    foreach ($autorizados as $a) {
        if ($a['relacao'] === 'proprio') { $escolhidos[] = (int)$a['id']; }
    }
}

$etiquetas = ['proprio' => 'Tu', 'filho' => 'Filho/a', 'pai_mae' => 'Pai/Mãe', 'conjuge' => 'Cônjuge', 'outro' => 'Outro'];

$tituloPagina = 'Marcar: pacientes';
require __DIR__ . '/includes/cabecalho.php';
echo estilos_marcacao();
?>

<div class="dash-root">

    <div class="dash-page-top">
        <div>
            <span class="dash-kicker">Marcação · Passo 1</span>
            <h1>Quem vai à consulta?</h1>
            <p>Marcação com <strong><?= e($dentista['nome_completo']) ?></strong> · <a href="dentistas.php?modo=marcar">mudar de dentista</a>.</p>
        </div>
    </div>

    <?= passos_marcacao(1) ?>

    <form method="post" action="marcar-pacientes.php" novalidate>
        <?= csrf_campo() ?>
        <input type="hidden" name="acao" value="continuar">

        <?php foreach ($errosCont as $erro): ?>
            <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
        <?php endforeach; ?>

        <fieldset class="campo">
            <legend>Pessoas incluídas na marcação (até <?= MAX_PACIENTES ?>)</legend>
            <div class="checks">
                <?php foreach ($autorizados as $a): ?>
                    <label class="check">
                        <input type="checkbox" name="pacientes[]" value="<?= (int)$a['id'] ?>"
                               <?= in_array((int)$a['id'], $escolhidos, true) ? 'checked' : '' ?>>
                        <?= e($a['nome_completo']) ?> <span class="ajuda">(<?= e($etiquetas[$a['relacao']] ?? '') ?>)</span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <fieldset class="campo">
            <legend>Contacto para esta marcação</legend>
            <div class="campo">
                <label for="contacto_nome">Nome <span class="obrigatorio" aria-hidden="true">*</span></label>
                <input type="text" id="contacto_nome" name="contacto_nome" required maxlength="150"
                       value="<?= e($contacto['nome']) ?>">
            </div>
            <div class="campo">
                <label for="contacto_telemovel">Telemóvel <span class="obrigatorio" aria-hidden="true">*</span></label>
                <input type="tel" id="contacto_telemovel" name="contacto_telemovel" required maxlength="20"
                       value="<?= e($contacto['telemovel']) ?>">
            </div>
        </fieldset>

        <button type="submit" class="botao">Continuar para o horário →</button>
    </form>

    <section class="cartao seccao">
        <h2>Adicionar um familiar</h2>
        <p class="ajuda">Só pedimos o que é preciso para a marcação. Podes juntar filhos, pais, cônjuge ou outra pessoa.</p>

        <?php foreach ($errosFam as $erro): ?>
            <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
        <?php endforeach; ?>

        <form method="post" action="marcar-pacientes.php" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="adicionar_familiar">

            <div class="campo">
                <label for="nome">Nome completo <span class="obrigatorio" aria-hidden="true">*</span></label>
                <input type="text" id="nome" name="nome" required maxlength="150" value="<?= e($formFam['nome']) ?>">
            </div>
            <div class="campo">
                <label for="nascimento">Data de nascimento <span class="obrigatorio" aria-hidden="true">*</span></label>
                <input type="date" id="nascimento" name="nascimento" required max="<?= e(date('Y-m-d')) ?>" value="<?= e($formFam['nascimento']) ?>">
            </div>
            <div class="campo">
                <label for="relacao">Relação contigo <span class="obrigatorio" aria-hidden="true">*</span></label>
                <select id="relacao" name="relacao">
                    <?php foreach (['filho' => 'Filho/a', 'pai_mae' => 'Pai/Mãe', 'conjuge' => 'Cônjuge', 'outro' => 'Outro'] as $v => $r): ?>
                        <option value="<?= e($v) ?>" <?= $formFam['relacao'] === $v ? 'selected' : '' ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label for="telemovel">Telemóvel (opcional)</label>
                <input type="tel" id="telemovel" name="telemovel" maxlength="20" value="<?= e($formFam['telemovel']) ?>">
            </div>
            <button type="submit" class="botao botao-secundario">Adicionar familiar</button>
        </form>
    </section>

</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>