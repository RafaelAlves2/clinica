<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/marcacao.php';
require __DIR__ . '/includes/conta.php';

$u   = exigir_login();
$pdo = db();

limpar_reservas_expiradas($pdo);
$agora = agora_bd($pdo);

$consultaId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($consultaId <= 0) {
    flash_definir('erro', 'Consulta inválida.');
    redirecionar('minhas-marcacoes.php');
}

$st = $pdo->prepare(
    'SELECT c.id, c.marcacao_id, c.paciente_id, c.dentista_id, c.inicio, c.fim, c.duracao_min, c.estado,
            p.nome_completo AS paciente, d.nome_completo AS dentista,
            m.referencia, m.utilizador_id AS dono
       FROM consultas c
       JOIN marcacoes m ON m.id = c.marcacao_id
       JOIN pacientes p ON p.id = c.paciente_id
       JOIN dentistas d ON d.id = c.dentista_id
      WHERE c.id = ? AND m.utilizador_id = ?'
);
$st->execute([$consultaId, $u['id']]);
$consulta = $st->fetch();

if (!$consulta) {
    flash_definir('erro', 'Consulta não encontrada.');
    redirecionar('minhas-marcacoes.php');
}

if (!in_array($consulta['estado'], ['pendente', 'confirmada'], true)) {
    flash_definir('erro', 'Esta consulta já não pode ser alterada.');
    redirecionar('minhas-marcacoes.php');
}

$horasAntecedencia = config_valor($pdo, 'cancelamento_antecedencia_h', 24);
$limiteMinimo = $agora->modify('+' . $horasAntecedencia . ' hours');
if (utc($consulta['inicio']) < $limiteMinimo) {
    flash_definir('erro', 'Já não é possível pedir alteração online (menos de ' . $horasAntecedencia . ' horas). Contacta a clínica.');
    redirecionar('minhas-marcacoes.php');
}

$st = $pdo->prepare("SELECT 1 FROM pedidos_alteracao WHERE consulta_id = ? AND estado = 'pendente'");
$st->execute([$consultaId]);
if ($st->fetchColumn()) {
    flash_definir('info', 'Já existe um pedido de alteração pendente para esta consulta. Aguarda a resposta da clínica.');
    redirecionar('minhas-marcacoes.php');
}

$dentistaId = (int)$consulta['dentista_id'];
$duracaoMin = (int)$consulta['duracao_min'];
$blocoAgendaMin = config_valor($pdo, 'bloco_agenda_min', 30);
$inicioAtual = utc($consulta['inicio']);

$hoje   = utc($agora->format('Y-m-d') . ' 00:00:00');
$maxDia = $hoje->modify('+' . DIAS_ANTECEDENCIA_MAX . ' days');

function ler_dia_alt(string $texto, DateTimeImmutable $hoje, DateTimeImmutable $maxDia): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $texto, new DateTimeZone('UTC'));
    return ($d !== false && $d->format('Y-m-d') === $texto && $d >= $hoje && $d <= $maxDia) ? $d : null;
}

$dia = ler_dia_alt((string)($_POST['data'] ?? $_GET['data'] ?? ''), $hoje, $maxDia);

if ($dia === null) {
    $dia = utc($consulta['inicio'])->setTime(0, 0, 0);
    if ($dia < $hoje) { $dia = $hoje; }
}

$erros = [];
$motivoForm = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $motivoForm = mb_substr(trim((string)($_POST['motivo'] ?? '')), 0, 255);
    $novoInicio = trim((string)($_POST['novo_inicio'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $novoInicio)) {
        $erros[] = 'Escolhe uma nova hora da lista.';
    } else {
        $diaNovo = ler_dia_alt(substr($novoInicio, 0, 10), $hoje, $maxDia);
        if ($diaNovo === null) {
            $erros[] = 'Data inválida.';
        } else {
            $prefixo = prefixo_reserva();
            $slots = slots_do_dia($pdo, $dentistaId, $diaNovo, $prefixo);
            $chaves = blocos_da_consulta($pdo, $novoInicio, $duracaoMin);
            if (!inicio_valido($slots, $chaves, $prefixo)) {
                $erros[] = 'Esse horário já não está disponível. Escolhe outro.';
            }
        }
    }

    if ($erros === []) {
        try {
            $pdo->beginTransaction();

            $stLock = $pdo->prepare('SELECT id FROM consultas WHERE id = ? FOR UPDATE');
            $stLock->execute([$consultaId]);
            if (!$stLock->fetchColumn()) {
                $pdo->rollBack();
                flash_definir('erro', 'Consulta não encontrada.');
                redirecionar('minhas-marcacoes.php');
            }

            $stCheck = $pdo->prepare("SELECT 1 FROM pedidos_alteracao WHERE consulta_id = ? AND estado = 'pendente'");
            $stCheck->execute([$consultaId]);
            if ($stCheck->fetchColumn()) {
                $pdo->rollBack();
                flash_definir('info', 'Já existe um pedido de alteração pendente para esta consulta. Aguarda a resposta da clínica.');
                redirecionar('minhas-marcacoes.php');
            }

            $pdo->prepare(
                "INSERT INTO pedidos_alteracao (consulta_id, utilizador_id, novo_inicio, motivo)
                 VALUES (?, ?, ?, ?)"
            )->execute([$consultaId, $u['id'], $novoInicio, $motivoForm ?: null]);

            registar_admin('pedir_alteracao', 'consultas', $consultaId, 'de ' . $consulta['inicio'] . ' para ' . $novoInicio);
            $pdo->commit();

            flash_definir('sucesso', 'Pedido de alteração enviado. A clínica vai responder em breve.');
            redirecionar('minhas-marcacoes.php');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Erro pedir alteração: ' . $e->getMessage());
            $erros[] = 'Ocorreu um erro. Tenta novamente.';
        }
    }
}

$prefixo = prefixo_reserva();
$slots   = slots_do_dia($pdo, $dentistaId, $dia, $prefixo);

$opcoes = [];
foreach ($slots as $s) {
    $chaves = blocos_da_consulta($pdo, $s['inicio'], $duracaoMin);
    if (inicio_valido($slots, $chaves, $prefixo)) {
        $fimDT = utc($s['inicio'])->modify('+' . $duracaoMin . ' minutes');
        $opcoes[] = [
            'inicio' => $s['inicio'],
            'texto'  => $s['hora'] . ' – ' . $fimDT->format('H:i'),
            'hora'   => $s['hora'],
            'fim'    => $fimDT->format('H:i'),
        ];
    }
}

$anterior = $dia->modify('-1 day');
$seguinte = $dia->modify('+1 day');

$rotulos = [
    'livre'           => ['✔', 'Livre'],
    'ocupado'         => ['✖', 'Ocupado'],
    'indisponivel'    => ['—', 'Indisponível'],
    'reservado_outro' => ['⏳', 'Reservado'],
    'reservado_meu'   => ['★', 'Reservado para ti'],
];

$tituloPagina = 'Pedir alteração';
require __DIR__ . '/includes/cabecalho.php';
echo estilos_conta();
echo estilos_marcacao();
?>

<div class="dash-root">

    <div class="dash-page-top">
        <div>
            <span class="dash-kicker">Alteração de consulta</span>
            <h1>Pedir alteração</h1>
            <p>Escolhe um novo dia e hora. O pedido fica pendente até a clínica aprovar.</p>
        </div>
    </div>

    <?= submenu_conta('marcacoes', nao_lidas($pdo, $u['id'])) ?>

    <section class="cartao seccao">
        <h2>Consulta atual</h2>
        <dl class="dados">
            <dt>Paciente</dt><dd><?= e($consulta['paciente']) ?></dd>
            <dt>Dentista</dt><dd><?= e($consulta['dentista']) ?></dd>
            <dt>Data atual</dt><dd><?= e(data_extenso($inicioAtual)) ?>, <?= e($inicioAtual->format('H:i')) ?> – <?= e(utc($consulta['fim'])->format('H:i')) ?></dd>
            <dt>Duração</dt><dd><?= e(formatar_duracao($duracaoMin)) ?></dd>
            <dt>Marcação</dt><dd><?= e($consulta['referencia']) ?></dd>
        </dl>
    </section>

    <?php foreach ($erros as $erro): ?>
        <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
    <?php endforeach; ?>

    <section class="seccao">
        <h2>Escolher nova data</h2>

        <div class="navdia">
            <?php if ($anterior >= $hoje): ?>
                <a class="botao botao-secundario" href="alterar-consulta.php?id=<?= $consultaId ?>&data=<?= e($anterior->format('Y-m-d')) ?>">← Dia anterior</a>
            <?php endif; ?>
            <form method="get" action="alterar-consulta.php">
                <input type="hidden" name="id" value="<?= $consultaId ?>">
                <label for="data">Ir para</label>
                <input type="date" id="data" name="data" value="<?= e($dia->format('Y-m-d')) ?>"
                       min="<?= e($hoje->format('Y-m-d')) ?>" max="<?= e($maxDia->format('Y-m-d')) ?>">
                <button type="submit" class="botao">Ver</button>
            </form>
            <?php if ($seguinte <= $maxDia): ?>
                <a class="botao botao-secundario" href="alterar-consulta.php?id=<?= $consultaId ?>&data=<?= e($seguinte->format('Y-m-d')) ?>">Dia seguinte →</a>
            <?php endif; ?>
        </div>

        <ul class="legenda" aria-label="Legenda">
            <?php foreach ($rotulos as $estado => [$simbolo, $texto]): ?>
                <li class="bloco bloco-<?= e($estado) ?>"><?= e($simbolo . ' ' . $texto) ?></li>
            <?php endforeach; ?>
        </ul>

        <?php if (!$slots): ?>
            <div class="alerta alerta-info">A clínica está fechada neste dia.</div>
        <?php else: ?>
            <?php
            $contagemSlots = array_count_values(array_column($slots, 'estado'));
            $livres = (int)($contagemSlots['livre'] ?? 0);
            $ocupados = (int)($contagemSlots['ocupado'] ?? 0);
            $reservados = (int)($contagemSlots['reservado_outro'] ?? 0);
            ?>
            <div class="agenda-titulo">
                <div>
                    <h2>Disponibilidade do dia</h2>
                    <p class="ajuda">Cada bloco representa <?= (int)$blocoAgendaMin ?> minutos da agenda.</p>
                </div>
            </div>
            <div class="agenda-resumo" aria-label="Resumo dos blocos de agenda">
                <span><strong><?= $livres ?></strong> livres</span>
                <span><strong><?= $ocupados ?></strong> ocupados</span>
                <span><strong><?= $reservados ?></strong> reservados</span>
            </div>
            <ul class="calendario" aria-label="Disponibilidade de <?= e(data_extenso($dia)) ?>">
                <?php foreach ($slots as $s): [$simbolo, $texto] = $rotulos[$s['estado']]; ?>
                    <li class="bloco bloco-<?= e($s['estado']) ?>">
                        <strong><?= e($s['hora']) ?></strong>
                        <span><?= e($simbolo . ' ' . $texto) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if ($opcoes): ?>
        <section class="cartao seccao">
            <h2>Pedir alteração para <?= e(data_extenso($dia)) ?></h2>
            <p class="ajuda">
                A alteração fica pendente até a clínica aprovar. Se o horário pretendido já estiver ocupado no momento da aprovação, será proposto outro.
            </p>

            <form method="post" action="alterar-consulta.php?id=<?= $consultaId ?>" novalidate>
                <?= csrf_campo() ?>
                <input type="hidden" name="data" value="<?= e($dia->format('Y-m-d')) ?>">

                <fieldset class="horario-pessoa">
                    <legend>Escolhe a nova hora</legend>
                    <p class="horario-pessoa-ajuda">
                        Consulta de <?= e(formatar_duracao($duracaoMin)) ?>. Cada opção mostra a hora de início e de fim.
                    </p>
                    <?php
                    $opcoesPorPeriodo = ['Manhã' => [], 'Tarde' => [], 'Final do dia' => []];
                    foreach ($opcoes as $opcao) {
                        $horaOpcao = (int)substr($opcao['inicio'], 11, 2);
                        $periodo = $horaOpcao < 12 ? 'Manhã' : ($horaOpcao < 18 ? 'Tarde' : 'Final do dia');
                        $opcoesPorPeriodo[$periodo][] = $opcao;
                    }
                    ?>
                    <?php foreach ($opcoesPorPeriodo as $nomePeriodo => $opcoesPeriodo): ?>
                        <?php if ($opcoesPeriodo): ?>
                            <div class="horario-grupo">
                                <h3><?= e($nomePeriodo) ?></h3>
                                <div class="horario-opcoes">
                                    <?php foreach ($opcoesPeriodo as $o): ?>
                                        <label class="horario-opcao">
                                            <input type="radio" name="novo_inicio" value="<?= e($o['inicio']) ?>"
                                                <?= ($_POST['novo_inicio'] ?? '') === $o['inicio'] ? 'checked' : '' ?> required>
                                            <span class="horario-opcao-conteudo">
                                                <strong><?= e($o['hora']) ?></strong>
                                                <small>Termina às <?= e($o['fim']) ?></small>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </fieldset>

                <div class="campo">
                    <label for="motivo">Motivo (opcional)</label>
                    <input type="text" id="motivo" name="motivo" maxlength="255" value="<?= e($motivoForm) ?>">
                </div>

                <div class="horario-acoes">
                    <button type="submit" class="botao">Enviar pedido de alteração</button>
                    <a class="botao botao-secundario" href="minhas-marcacoes.php">Cancelar</a>
                </div>
            </form>
        </section>
    <?php elseif ($slots): ?>
        <div class="alerta alerta-info">
            Não há horas livres neste dia para uma consulta de <?= e(formatar_duracao($duracaoMin)) ?>.
            Escolhe outro dia na navegação acima.
        </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>