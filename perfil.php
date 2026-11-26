<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/marcacao.php';
require __DIR__ . '/includes/conta.php';

$u   = exigir_login();
$pdo = db();

$st = $pdo->prepare('SELECT nome_completo, email, telemovel, password_hash FROM utilizadores WHERE id = ?');
$st->execute([$u['id']]);
$conta = $st->fetch();
if (!$conta) {
    redirecionar('login.php');
}

$erros = ['dados' => [], 'password' => [], 'familiar' => []];
$formDados = ['nome' => $conta['nome_completo'], 'telemovel' => $conta['telemovel']];
$familiarComErro = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $acao = (string)($_POST['acao'] ?? '');

    // ---------- Dados de contacto ----------
    if ($acao === 'dados') {
        $formDados['nome']      = trim((string)($_POST['nome'] ?? ''));
        $formDados['telemovel'] = preg_replace('/\s+/', '', trim((string)($_POST['telemovel'] ?? '')));

        if (mb_strlen($formDados['nome']) < 3 || mb_strlen($formDados['nome']) > 150) {
            $erros['dados'][] = 'Indica o teu nome completo (3 a 150 caracteres).';
        }
        if (!preg_match('/^\+?\d{9,15}$/', $formDados['telemovel'])) {
            $erros['dados'][] = 'Indica um telemóvel válido (só números).';
        }
        if ($erros['dados'] === []) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE utilizadores SET nome_completo = ?, telemovel = ? WHERE id = ?')
                    ->execute([$formDados['nome'], $formDados['telemovel'], $u['id']]);
                $pdo->prepare('UPDATE pacientes SET nome_completo = ?, telemovel = ? WHERE utilizador_id = ?')
                    ->execute([$formDados['nome'], $formDados['telemovel'], $u['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('perfil dados: ' . $e->getMessage());
                $erros['dados'][] = 'Ocorreu um erro ao guardar. Tenta novamente.';
            }
            if ($erros['dados'] === []) {
                $_SESSION['utilizador']['nome'] = $formDados['nome'];
                $_SESSION['utilizador']['nome_completo'] = $formDados['nome'];
                flash_definir('sucesso', 'Dados atualizados.');
                redirecionar('perfil.php');
            }
        }
    }

    // ---------- Palavra-passe ----------
    if ($acao === 'password') {
        $atual = (string)($_POST['atual'] ?? '');
        $nova  = (string)($_POST['nova'] ?? '');
        $nova2 = (string)($_POST['nova2'] ?? '');

        $emailKey = '#perfilpwd#' . mb_substr((string)$conta['email'], 0, 180);
        $ip       = ip_cliente();
        $stR = $pdo->prepare(
            'SELECT SUM(email = ?) AS por_email, SUM(ip = ?) AS por_ip
               FROM tentativas_login
              WHERE email = ? AND sucesso = 0
                AND criado_em > (NOW() - INTERVAL 15 MINUTE)'
        );
        $stR->execute([$emailKey, $ip, $emailKey]);
        $rR = $stR->fetch() ?: ['por_email' => 0, 'por_ip' => 0];
        if ((int)$rR['por_email'] >= 5 || (int)$rR['por_ip'] >= 15) {
            $erros['password'][] = 'Demasiadas tentativas. Tenta novamente dentro de 15 minutos.';
        }

        if ($erros['password'] === []) {
            if (!password_verify($atual, $conta['password_hash'])) {
                $erros['password'][] = 'A palavra-passe atual não está correta.';
                $pdo->prepare('INSERT INTO tentativas_login (email, ip, sucesso) VALUES (?, ?, 0)')
                    ->execute([$emailKey, $ip]);
            }
            if (strlen($nova) < 8 || strlen($nova) > 72 || !preg_match('/[A-Za-z]/', $nova) || !preg_match('/\d/', $nova)) {
                $erros['password'][] = 'A nova palavra-passe deve ter entre 8 e 72 caracteres, com pelo menos uma letra e um número.';
            }
            if ($nova !== $nova2) {
                $erros['password'][] = 'As novas palavras-passe não coincidem.';
            }
        }

        if ($erros['password'] === []) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE utilizadores SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($nova, PASSWORD_DEFAULT), $u['id']]);
                $pdo->prepare("UPDATE tokens_conta SET usado_em = NOW() WHERE utilizador_id = ? AND tipo = 'recuperar_password' AND usado_em IS NULL")
                    ->execute([$u['id']]);
                $pdo->prepare('DELETE FROM tentativas_login WHERE email = ? AND sucesso = 0')->execute([$emailKey]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('perfil password: ' . $e->getMessage());
                $erros['password'][] = 'Ocorreu um erro ao alterar a palavra-passe. Tenta novamente.';
            }
            if ($erros['password'] === []) {
                session_regenerate_id(true);
                unset($_SESSION['csrf']);
                flash_definir('sucesso', 'Palavra-passe alterada.');
                redirecionar('perfil.php');
            }
        }
    }

    // ---------- Familiares ----------
    if (in_array($acao, ['familiar_guardar', 'familiar_remover'], true)) {
        $pid = (int)($_POST['paciente'] ?? 0);

        $st = $pdo->prepare(
            "SELECT p.id FROM pacientes p JOIN autorizacoes_paciente a ON a.paciente_id = p.id
              WHERE p.id = ? AND a.utilizador_id = ? AND a.relacao <> 'proprio'"
        );
        $st->execute([$pid, $u['id']]);
        if (!$st->fetchColumn()) {
            flash_definir('erro', 'Familiar não encontrado.');
            redirecionar('perfil.php');
        }

        if ($acao === 'familiar_remover') {
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM consultas WHERE paciente_id = ? AND estado IN ('pendente','confirmada') AND inicio >= NOW()"
            );
            $st->execute([$pid]);
            if ((int)$st->fetchColumn() > 0) {
                flash_definir('erro', 'Este familiar tem consultas marcadas. Cancela-as antes de o remover.');
            } else {
                $pdo->prepare('DELETE FROM autorizacoes_paciente WHERE utilizador_id = ? AND paciente_id = ?')->execute([$u['id'], $pid]);
                flash_definir('sucesso', 'Familiar removido da tua lista.');
            }
            redirecionar('perfil.php');
        }

        $nome = trim((string)($_POST['nome'] ?? ''));
        $nasc = trim((string)($_POST['nascimento'] ?? ''));
        $tel  = preg_replace('/\s+/', '', trim((string)($_POST['telemovel'] ?? '')));
        $d    = DateTime::createFromFormat('Y-m-d', $nasc);

        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 150) {
            $erros['familiar'][$pid][] = 'Indica o nome completo.';
        }
        if ($d === false || $d->format('Y-m-d') !== $nasc || $nasc > date('Y-m-d') || $nasc < '1900-01-01') {
            $erros['familiar'][$pid][] = 'Data de nascimento inválida.';
        }
        if ($tel !== '' && !preg_match('/^\+?\d{9,15}$/', $tel)) {
            $erros['familiar'][$pid][] = 'Telemóvel inválido (só números).';
        }
        if (empty($erros['familiar'][$pid])) {
            $pdo->prepare('UPDATE pacientes SET nome_completo = ?, data_nascimento = ?, telemovel = ? WHERE id = ?')
                ->execute([$nome, $nasc, $tel ?: null, $pid]);
            flash_definir('sucesso', 'Dados do familiar atualizados.');
            redirecionar('perfil.php');
        }
        $familiarComErro = $pid;
    }
}

// Familiares desta conta
$st = $pdo->prepare(
    "SELECT p.id, p.nome_completo, p.data_nascimento, p.telemovel, a.relacao
       FROM autorizacoes_paciente a JOIN pacientes p ON p.id = a.paciente_id
      WHERE a.utilizador_id = ? AND a.relacao <> 'proprio' AND p.ativo = 1
      ORDER BY p.nome_completo"
);
$st->execute([$u['id']]);
$familiares = $st->fetchAll();
$relacoes = ['filho' => 'Filho/a', 'pai_mae' => 'Pai/Mãe', 'conjuge' => 'Cônjuge', 'outro' => 'Outro'];

$stB = $pdo->prepare(
    "SELECT bp.id, bp.estado_validacao, bp.numero_apolice, bp.numero_beneficiario, bp.valido_de, bp.valido_ate,
            p.nome_completo AS paciente, pl.nome AS plano, s.nome AS seguradora
       FROM beneficios_paciente bp
       JOIN pacientes p ON p.id = bp.paciente_id
       JOIN autorizacoes_paciente a ON a.paciente_id = bp.paciente_id AND a.utilizador_id = ?
       JOIN planos_beneficio pl ON pl.id = bp.plano_id
       JOIN seguradoras s ON s.id = pl.seguradora_id
      ORDER BY bp.criado_em DESC"
);
$stB->execute([$u['id']]);
$beneficiosPerfil = $stB->fetchAll();
$rotulosBeneficio = [
    'registado' => 'À espera de validação', 'em_validacao' => 'Em validação',
    'validado' => 'Validado', 'rejeitado' => 'Rejeitado', 'expirado' => 'Expirado',
];

$fmtData = static function (?string $d): string {
    if ($d === null || $d === '' || str_starts_with($d, '0000-00-00')) { return '—'; }
    try {
        return (new DateTime($d))->format('d/m/Y');
    } catch (Throwable $e) {
        return '—';
    }
};

$tituloPagina = 'Perfil e familiares';
require __DIR__ . '/includes/cabecalho.php';
echo estilos_conta();
?>

<div class="dash-root">

    <div class="dash-page-top">
        <div>
            <span class="dash-kicker">Perfil</span>
            <h1>Perfil e familiares</h1>
            <p>Os teus dados, palavra-passe, seguros e dependentes associados à conta.</p>
        </div>
    </div>

    <?= submenu_conta('perfil', nao_lidas($pdo, $u['id'])) ?>

    <section class="cartao seccao">
        <h2>Dados de contacto</h2>
        <?php foreach ($erros['dados'] as $erro): ?>
            <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
        <?php endforeach; ?>
        <form method="post" action="perfil.php" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="dados">
            <div class="campo">
                <label for="nome">Nome completo</label>
                <input type="text" id="nome" name="nome" required maxlength="150" autocomplete="name" value="<?= e($formDados['nome']) ?>">
            </div>
            <div class="campo">
                <label for="email">Email</label>
                <input type="email" id="email" value="<?= e($conta['email']) ?>" disabled>
                <p class="ajuda">O email é o teu identificador de acesso e não pode ser alterado aqui.</p>
            </div>
            <div class="campo">
                <label for="telemovel">Telemóvel</label>
                <input type="tel" id="telemovel" name="telemovel" required maxlength="20" autocomplete="tel" value="<?= e($formDados['telemovel']) ?>">
            </div>
            <button type="submit" class="botao">Guardar dados</button>
        </form>
    </section>

    <section class="cartao seccao">
        <h2>Alterar palavra-passe</h2>
        <?php foreach ($erros['password'] as $erro): ?>
            <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
        <?php endforeach; ?>
        <form method="post" action="perfil.php" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="password">
            <div class="campo">
                <label for="atual">Palavra-passe atual</label>
                <input type="password" id="atual" name="atual" required maxlength="72" autocomplete="current-password">
            </div>
            <div class="campo">
                <label for="nova">Nova palavra-passe</label>
                <input type="password" id="nova" name="nova" required minlength="8" maxlength="72" autocomplete="new-password" aria-describedby="aj-nova">
                <p id="aj-nova" class="ajuda">Mínimo 8 caracteres, com pelo menos uma letra e um número.</p>
            </div>
            <div class="campo">
                <label for="nova2">Repetir nova palavra-passe</label>
                <input type="password" id="nova2" name="nova2" required maxlength="72" autocomplete="new-password">
            </div>
            <button type="submit" class="botao">Alterar palavra-passe</button>
        </form>
    </section>

    <div class="dash-section-title">
        <div>
            <span class="dash-kicker">Seguros</span>
            <h2>Seguros e <span>benefícios</span></h2>
        </div>
        <a href="seguros.php">Gerir</a>
    </div>

    <?php if (!$beneficiosPerfil): ?>
        <div class="dash-empty">
            <span class="dash-empty-symbol" aria-hidden="true">◇</span>
            <h3>Ainda não tens benefícios registados</h3>
            <p>Regista o teu seguro ou plano de benefícios para usufruir dos descontos.</p>
            <a class="botao" href="seguros.php">Registar benefício</a>
        </div>
    <?php else: ?>
        <div class="dash-list">
            <?php foreach ($beneficiosPerfil as $b): ?>
                <div class="dash-list-row">
                    <span class="dash-list-mark" aria-hidden="true">◇</span>
                    <div class="dash-list-copy">
                        <strong><?= e($b['paciente']) ?></strong>
                        <span><?= e($b['seguradora']) ?> — <?= e($b['plano']) ?></span>
                        <small>
                            Estado: <?= e($rotulosBeneficio[$b['estado_validacao']] ?? $b['estado_validacao']) ?>
                            <?php if ($b['valido_de'] || $b['valido_ate']): ?>
                                · Validade: <?= e($fmtData($b['valido_de'])) ?> a <?= e($fmtData($b['valido_ate'])) ?>
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="dash-section-title">
        <div>
            <span class="dash-kicker">Familiares</span>
            <h2>Os meus <span>familiares</span></h2>
        </div>
    </div>

    <p class="ajuda">Para adicionar um familiar novo, usa o passo «Pacientes» quando marcares uma consulta.</p>

    <?php if (!$familiares): ?>
        <div class="dash-empty">
            <span class="dash-empty-symbol" aria-hidden="true">◉</span>
            <h3>Sem familiares associados</h3>
            <p>Podes adicionar dependentes no fluxo de marcação de consulta.</p>
            <a class="botao botao-secundario" href="marcar.php">Marcar consulta</a>
        </div>
    <?php endif; ?>

    <?php foreach ($familiares as $f): $pid = (int)$f['id']; ?>
        <article class="cartao seccao">
            <h3><?= e($f['nome_completo']) ?> <span class="ajuda">(<?= e($relacoes[$f['relacao']] ?? '') ?>)</span></h3>
            <?php foreach ($erros['familiar'][$pid] ?? [] as $erro): ?>
                <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
            <?php endforeach; ?>

            <form method="post" action="perfil.php" novalidate>
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="familiar_guardar">
                <input type="hidden" name="paciente" value="<?= $pid ?>">
                <?php $v = $familiarComErro === $pid
                    ? ['n' => (string)($_POST['nome'] ?? ''), 'd' => (string)($_POST['nascimento'] ?? ''), 't' => (string)($_POST['telemovel'] ?? '')]
                    : ['n' => $f['nome_completo'], 'd' => (string)$f['data_nascimento'], 't' => (string)$f['telemovel']]; ?>
                <div class="campo">
                    <label for="fn-<?= $pid ?>">Nome completo</label>
                    <input type="text" id="fn-<?= $pid ?>" name="nome" required maxlength="150" value="<?= e($v['n']) ?>">
                </div>
                <div class="campo">
                    <label for="fd-<?= $pid ?>">Data de nascimento</label>
                    <input type="date" id="fd-<?= $pid ?>" name="nascimento" required max="<?= e(date('Y-m-d')) ?>" value="<?= e($v['d']) ?>">
                </div>
                <div class="campo">
                    <label for="ft-<?= $pid ?>">Telemóvel (opcional)</label>
                    <input type="tel" id="ft-<?= $pid ?>" name="telemovel" maxlength="20" value="<?= e($v['t']) ?>">
                </div>
                <button type="submit" class="botao">Guardar</button>
            </form>

            <form method="post" action="perfil.php" class="form-inline"
                  onsubmit="return confirm('Remover este familiar da tua lista?');">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="familiar_remover">
                <input type="hidden" name="paciente" value="<?= $pid ?>">
                <button type="submit" class="botao botao-secundario">Remover da lista</button>
            </form>
        </article>
    <?php endforeach; ?>

</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>