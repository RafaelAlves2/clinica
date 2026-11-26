<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/marcacao.php';
require __DIR__ . '/includes/conta.php';

$u   = exigir_login();
$pdo = db();

$st = $pdo->prepare(
    "SELECT p.id, p.nome_completo, a.relacao
       FROM autorizacoes_paciente a
       JOIN pacientes p ON p.id = a.paciente_id
      WHERE a.utilizador_id = ? AND p.ativo = 1
      ORDER BY (a.relacao = 'proprio') DESC, p.nome_completo"
);
$st->execute([$u['id']]);
$pacientes = $st->fetchAll();
$idsPacientes = array_map('intval', array_column($pacientes, 'id'));

$seguradoras = $pdo->query(
    'SELECT s.id, s.nome, s.tipo,
            (SELECT GROUP_CONCAT(pl.id, \':\', pl.nome ORDER BY pl.nome SEPARATOR "|")
               FROM planos_beneficio pl
              WHERE pl.seguradora_id = s.id AND pl.ativo = 1) AS planos
       FROM seguradoras s WHERE s.ativo = 1 ORDER BY s.nome'
)->fetchAll();

$planosLista = [];
foreach ($seguradoras as $s) {
    if (!$s['planos']) { continue; }
    foreach (explode('|', $s['planos']) as $par) {
        [$pid, $pnome] = explode(':', $par, 2);
        $planosLista[] = ['id' => (int)$pid, 'nome' => $pnome, 'seguradora' => $s['nome']];
    }
}

$erros = [];
$form  = [
    'paciente' => 0, 'plano' => 0, 'apolice' => '', 'beneficiario' => '',
    'valido_de' => '', 'valido_ate' => '',
];

$fmtData = static function (?string $d): string {
    if ($d === null || $d === '' || str_starts_with($d, '0000-00-00')) { return '—'; }
    try { return (new DateTime($d))->format('d/m/Y'); } catch (Throwable $e) { return '—'; }
};
$fmtDataHora = static function (?string $d): string {
    if ($d === null || $d === '' || str_starts_with($d, '0000-00-00')) { return '—'; }
    try { return (new DateTime($d))->format('d/m/Y H:i'); } catch (Throwable $e) { return '—'; }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'registar') {
        $form['paciente']     = (int)($_POST['paciente'] ?? 0);
        $form['plano']        = (int)($_POST['plano'] ?? 0);
        $form['apolice']      = trim((string)($_POST['apolice'] ?? ''));
        $form['beneficiario'] = trim((string)($_POST['beneficiario'] ?? ''));
        $form['valido_de']    = (string)($_POST['valido_de'] ?? '');
        $form['valido_ate']   = (string)($_POST['valido_ate'] ?? '');

        if (!in_array($form['paciente'], $idsPacientes, true)) {
            $erros[] = 'Escolhe um paciente da tua lista.';
        }
        $planoOk = false;
        foreach ($planosLista as $p) {
            if ($p['id'] === $form['plano']) { $planoOk = true; break; }
        }
        if (!$planoOk) {
            $erros[] = 'Escolhe um plano válido.';
        }
        if (mb_strlen($form['apolice']) > 60) {
            $erros[] = 'O número de apólice é demasiado longo.';
        }
        if (mb_strlen($form['beneficiario']) > 60) {
            $erros[] = 'O número de beneficiário é demasiado longo.';
        }
        $dataOk = function (string $d): string|false|null {
            if ($d === '') { return null; }
            $dt = DateTime::createFromFormat('Y-m-d', $d);
            return ($dt && $dt->format('Y-m-d') === $d) ? $d : false;
        };
        $vd = $dataOk($form['valido_de']);
        $va = $dataOk($form['valido_ate']);
        if ($vd === false) { $erros[] = 'Data de início inválida.'; }
        if ($va === false) { $erros[] = 'Data de fim inválida.'; }
        if (is_string($vd) && is_string($va) && $va < $vd) {
            $erros[] = 'A data de fim tem de ser posterior à de início.';
        }

        if ($erros === []) {
            try {
                $pdo->prepare(
                    "INSERT INTO beneficios_paciente
                        (paciente_id, plano_id, numero_apolice, numero_beneficiario, valido_de, valido_ate, estado_validacao)
                     VALUES (?, ?, ?, ?, ?, ?, 'registado')"
                )->execute([
                    $form['paciente'], $form['plano'],
                    $form['apolice'] ?: null,
                    $form['beneficiario'] ?: null,
                    is_string($vd) ? $vd : null,
                    is_string($va) ? $va : null,
                ]);
                registar_admin('registar_beneficio', 'beneficios_paciente', (int)$pdo->lastInsertId());
                flash_definir('sucesso', 'Benefício registado. A clínica vai validar e o desconto será aplicado nas próximas marcações.');
                redirecionar('seguros.php');
            } catch (PDOException $e) {
                error_log('Erro registar benefício: ' . $e->getMessage());
                $erros[] = 'Ocorreu um erro. Tenta novamente.';
            }
        }
    }

    if ($acao === 'apagar') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare(
            "DELETE bp FROM beneficios_paciente bp
               JOIN autorizacoes_paciente a ON a.paciente_id = bp.paciente_id AND a.utilizador_id = ?
              WHERE bp.id = ? AND bp.estado_validacao IN ('registado','rejeitado')"
        );
        $st->execute([$u['id'], $id]);
        if ($st->rowCount() > 0) {
            registar_admin('apagar_beneficio', 'beneficios_paciente', $id);
            flash_definir('sucesso', 'Benefício removido.');
        } else {
            flash_definir('erro', 'Não podes apagar este benefício (já foi validado ou não te pertence).');
        }
        redirecionar('seguros.php');
    }
}

$st = $pdo->prepare(
    "SELECT bp.id, bp.numero_apolice, bp.numero_beneficiario, bp.valido_de, bp.valido_ate,
            bp.estado_validacao, bp.validado_em, bp.notas, bp.criado_em,
            p.nome_completo AS paciente,
            pl.nome AS plano, s.nome AS seguradora, s.tipo AS tipo_seguradora
       FROM beneficios_paciente bp
       JOIN pacientes p ON p.id = bp.paciente_id
       JOIN autorizacoes_paciente a ON a.paciente_id = bp.paciente_id AND a.utilizador_id = ?
       JOIN planos_beneficio pl ON pl.id = bp.plano_id
       JOIN seguradoras s ON s.id = pl.seguradora_id
      ORDER BY bp.criado_em DESC"
);
$st->execute([$u['id']]);
$meusBeneficios = $st->fetchAll();

$rotulosEstado = [
    'registado'    => 'À espera de validação',
    'em_validacao' => 'Em validação',
    'validado'     => 'Validado',
    'rejeitado'    => 'Rejeitado',
    'expirado'     => 'Expirado',
];

// Mapear estado para classe dash-pill
$pillClassePorEstado = [
    'registado'    => 'dash-pill-planning',
    'em_validacao' => 'dash-pill-working',
    'validado'     => 'dash-pill-completed',
    'rejeitado'    => 'dash-pill-cancelled',
    'expirado'     => 'dash-pill-cancelled',
];

$tituloPagina = 'Seguros e benefícios';
require __DIR__ . '/includes/cabecalho.php';
echo estilos_conta();
?>

<div class="dash-root">

    <div class="dash-page-top">
        <div>
            <span class="dash-kicker">Seguros e benefícios</span>
            <h1>Seguros e benefícios</h1>
            <p>Regista os teus seguros de saúde, planos dentários ou descontos da clínica. Os descontos só são aplicados depois de a clínica validar.</p>
        </div>
    </div>

    <?= submenu_conta('perfil', nao_lidas($pdo, $u['id'])) ?>

    <div class="dash-section-title">
        <div>
            <span class="dash-kicker">Os meus benefícios</span>
            <h2>Registos <span>ativos</span></h2>
        </div>
    </div>

    <?php if (!$meusBeneficios): ?>
        <div class="dash-empty">
            <span class="dash-empty-symbol" aria-hidden="true">◇</span>
            <h3>Ainda não tens benefícios registados</h3>
            <p>Adiciona o teu seguro ou plano de benefícios para que os descontos sejam aplicados nas próximas marcações.</p>
        </div>
    <?php else: ?>
        <div class="dash-list">
        <?php foreach ($meusBeneficios as $b):
            $pillClasse = $pillClassePorEstado[$b['estado_validacao']] ?? 'dash-pill-planning';
        ?>
            <div class="dash-list-row">
                <span class="dash-list-mark" aria-hidden="true">◇</span>
                <div class="dash-list-copy">
                    <strong><?= e($b['paciente']) ?> · <?= e($b['seguradora']) ?></strong>
                    <span><?= e($b['plano']) ?></span>
                    <small>
                        Apólice: <?= e($b['numero_apolice'] ?? '—') ?>
                        · Beneficiário: <?= e($b['numero_beneficiario'] ?? '—') ?>
                        · Validade: <?= e($fmtData($b['valido_de'])) ?> a <?= e($fmtData($b['valido_ate'])) ?>
                    </small>
                    <?php if ($b['validado_em']): ?>
                        <small>Tratado em <?= e($fmtDataHora($b['validado_em'])) ?></small>
                    <?php endif; ?>
                    <?php if ($b['notas']): ?>
                        <small>Notas da clínica: <?= e($b['notas']) ?></small>
                    <?php endif; ?>
                </div>
                <div class="dash-list-end">
                    <span class="dash-pill <?= e($pillClasse) ?>">
                        <span class="dash-pill-dot" aria-hidden="true"></span>
                        <?= e($rotulosEstado[$b['estado_validacao']] ?? $b['estado_validacao']) ?>
                    </span>
                    <?php if (in_array($b['estado_validacao'], ['registado', 'rejeitado'], true)): ?>
                        <form method="post" action="seguros.php" class="form-inline" onsubmit="return confirm('Apagar este benefício?');" style="margin:0;">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="acao" value="apagar">
                            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                            <button type="submit" class="dash-list-action" style="border-color:var(--erro);color:var(--erro);">Apagar</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="dash-section-title">
        <div>
            <span class="dash-kicker">Registar</span>
            <h2>Novo <span>benefício</span></h2>
        </div>
    </div>

    <?php foreach ($erros as $erro): ?>
        <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
    <?php endforeach; ?>

    <?php if (!$pacientes): ?>
        <div class="alerta alerta-info">Não tens pacientes associados.</div>
    <?php elseif (!$planosLista): ?>
        <div class="alerta alerta-info">A clínica ainda não configurou planos de benefício.</div>
    <?php else: ?>
        <section class="cartao">
            <form method="post" action="seguros.php" novalidate>
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="registar">

                <div class="campo">
                    <label for="paciente">Para quem <span class="obrigatorio" aria-hidden="true">*</span></label>
                    <select id="paciente" name="paciente" required>
                        <option value="0">— escolher —</option>
                        <?php foreach ($pacientes as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $form['paciente'] === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= e($p['nome_completo']) ?>
                                <?= $p['relacao'] === 'proprio' ? '(tu)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo">
                    <label for="plano">Plano <span class="obrigatorio" aria-hidden="true">*</span></label>
                    <select id="plano" name="plano" required>
                        <option value="0">— escolher —</option>
                        <?php foreach ($planosLista as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $form['plano'] === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= e($p['seguradora']) ?> — <?= e($p['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo">
                    <label for="apolice">Número de apólice</label>
                    <input type="text" id="apolice" name="apolice" maxlength="60" value="<?= e($form['apolice']) ?>">
                </div>

                <div class="campo">
                    <label for="beneficiario">Número de beneficiário</label>
                    <input type="text" id="beneficiario" name="beneficiario" maxlength="60" value="<?= e($form['beneficiario']) ?>">
                </div>

                <div class="campo">
                    <label for="valido_de">Válido de</label>
                    <input type="date" id="valido_de" name="valido_de" value="<?= e($form['valido_de']) ?>">
                </div>

                <div class="campo">
                    <label for="valido_ate">Válido até</label>
                    <input type="date" id="valido_ate" name="valido_ate" value="<?= e($form['valido_ate']) ?>">
                </div>

                <button type="submit" class="botao">Registar benefício</button>
            </form>
        </section>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>