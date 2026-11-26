<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';

if (utilizador_atual() !== null) {
    redirecionar('area-pessoal.php');
}

$erros = [];
$dados = ['nome' => '', 'email' => '', 'telemovel' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();

    $dados['nome']      = trim((string)($_POST['nome'] ?? ''));
    $dados['email']     = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $dados['telemovel'] = preg_replace('/\s+/', '', trim((string)($_POST['telemovel'] ?? '')));
    $password           = (string)($_POST['password'] ?? '');
    $password2          = (string)($_POST['password2'] ?? '');
    $aceitaPrivacidade  = isset($_POST['aceita_privacidade']);

    if (mb_strlen($dados['nome']) < 3 || mb_strlen($dados['nome']) > 150) {
        $erros['nome'] = 'Indica o teu nome completo (entre 3 e 150 caracteres).';
    }
    if (!filter_var($dados['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($dados['email']) > 190) {
        $erros['email'] = 'Indica um endereço de email válido.';
    }
    if (!preg_match('/^\+?\d{9,15}$/', $dados['telemovel'])) {
        $erros['telemovel'] = 'Indica um número de telemóvel válido (só números, ex.: 912345678).';
    }
    if (strlen($password) < 8) {
        $erros['password'] = 'A palavra-passe deve ter pelo menos 8 caracteres.';
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $erros['password'] = 'A palavra-passe deve ter pelo menos uma letra e um número.';
    } elseif (strlen($password) > 72) {
        $erros['password'] = 'A palavra-passe não pode ter mais de 72 caracteres.';
    }
    if ($password !== $password2) {
        $erros['password2'] = 'As palavras-passe não coincidem.';
    }
    if (!$aceitaPrivacidade) {
        $erros['aceita_privacidade'] = 'Tens de aceitar o tratamento dos dados para criar a conta.';
    }

    if ($erros === []) {
        $stmt = db()->prepare('SELECT id FROM utilizadores WHERE email = ?');
        $stmt->execute([$dados['email']]);
        if ($stmt->fetch()) {
            $erros['email'] = 'Já existe uma conta com este email.';
        }
    }

    if ($erros === []) {
        $pdo = db();
        try {
            $pdo->beginTransaction();

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                'INSERT INTO utilizadores (nome_completo, email, telemovel, password_hash, papel)
                 VALUES (?, ?, ?, ?, \'paciente\')'
            );
            $stmt->execute([$dados['nome'], $dados['email'], $dados['telemovel'], $hash]);
            $utilizadorId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO pacientes (utilizador_id, nome_completo, telemovel, email)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$utilizadorId, $dados['nome'], $dados['telemovel'], $dados['email']]);
            $pacienteId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO autorizacoes_paciente (utilizador_id, paciente_id, relacao)
                 VALUES (?, ?, \'proprio\')'
            );
            $stmt->execute([$utilizadorId, $pacienteId]);

            $codigoConfirmacao = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $tokenHash = hash('sha256', $codigoConfirmacao);
            $pdo->prepare("UPDATE tokens_conta SET usado_em = NOW() WHERE utilizador_id = ? AND tipo = 'verificar_email' AND usado_em IS NULL")
                ->execute([$utilizadorId]);
            $pdo->prepare("INSERT INTO tokens_conta (utilizador_id, tipo, token_hash, expira_em) VALUES (?, 'verificar_email', ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))")
                ->execute([$utilizadorId, $tokenHash]);

            $pdo->commit();

            $_SESSION['confirmar_email_pendente'] = $dados['email'];
            if (defined('MODO_DEV') && MODO_DEV) {
                $_SESSION['confirmar_email_codigo_dev'] = $codigoConfirmacao;
                flash_definir('sucesso', 'Conta criada. Confirma o teu email com o código apresentado (modo demonstração, sem envio real).');
            } else {
                flash_definir('info', 'Conta criada. Foi gerado um código de confirmação de email. O envio automático de email não está configurado; pede o código à administração ou configura o envio para receberes o código no teu email.');
            }
            redirecionar('confirmar-email.php?email=' . urlencode($dados['email']));
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string)$e->getCode() === '23000') {
                $erros['email'] = 'Já existe uma conta com este email.';
            } else {
                error_log('Erro no registo: ' . $e->getMessage());
                $erros['geral'] = 'Ocorreu um erro. Tenta novamente.';
            }
        }
    }
}

$tituloPagina = 'Criar conta';
require __DIR__ . '/includes/cabecalho.php';
?>

<div class="auth-page">
    <div class="auth-side-form">
        <h1>Criar conta</h1>
        <p class="auth-subtitle">Leva menos de um minuto. Depois podes marcar consultas e acompanhar tudo online.</p>

        <?php if (isset($erros['geral'])): ?>
            <div class="alerta alerta-erro" role="alert"><?= e($erros['geral']) ?></div>
        <?php endif; ?>

        <form method="post" action="registo.php" novalidate>
            <?= csrf_campo() ?>

            <div class="campo">
                <label for="nome">Nome completo <span class="obrigatorio" aria-hidden="true">*</span></label>
                <input type="text" id="nome" name="nome" required maxlength="150"
                       autocomplete="name" value="<?= e($dados['nome']) ?>">
                <?php if (isset($erros['nome'])): ?><p class="erro-campo"><?= e($erros['nome']) ?></p><?php endif; ?>
            </div>

            <div class="campo">
                <label for="email">Email <span class="obrigatorio" aria-hidden="true">*</span></label>
                <input type="email" id="email" name="email" required maxlength="190"
                       autocomplete="email" value="<?= e($dados['email']) ?>">
                <?php if (isset($erros['email'])): ?><p class="erro-campo"><?= e($erros['email']) ?></p><?php endif; ?>
            </div>

            <div class="campo">
                <label for="telemovel">Telemóvel <span class="obrigatorio" aria-hidden="true">*</span></label>
                <input type="tel" id="telemovel" name="telemovel" required maxlength="20"
                       autocomplete="tel" value="<?= e($dados['telemovel']) ?>">
                <?php if (isset($erros['telemovel'])): ?><p class="erro-campo"><?= e($erros['telemovel']) ?></p><?php endif; ?>
            </div>

            <div class="campo">
                <label for="password">Palavra-passe <span class="obrigatorio" aria-hidden="true">*</span></label>
                <input type="password" id="password" name="password" required minlength="8" maxlength="72"
                       autocomplete="new-password" aria-describedby="ajuda-password">
                <p id="ajuda-password" class="ajuda">Mínimo 8 caracteres, com pelo menos uma letra e um número.</p>
                <?php if (isset($erros['password'])): ?><p class="erro-campo"><?= e($erros['password']) ?></p><?php endif; ?>
            </div>

            <div class="campo">
                <label for="password2">Repetir palavra-passe <span class="obrigatorio" aria-hidden="true">*</span></label>
                <input type="password" id="password2" name="password2" required maxlength="72"
                       autocomplete="new-password">
                <?php if (isset($erros['password2'])): ?><p class="erro-campo"><?= e($erros['password2']) ?></p><?php endif; ?>
            </div>

            <div class="campo campo-checkbox">
                <input type="checkbox" id="aceita_privacidade" name="aceita_privacidade" value="1" required
                       <?= isset($_POST['aceita_privacidade']) ? 'checked' : '' ?>>
                <label for="aceita_privacidade">
                    Li e aceito que a clínica trate os meus dados pessoais para gerir a minha conta e as minhas marcações.
                    <span class="obrigatorio" aria-hidden="true">*</span>
                </label>
                <?php if (isset($erros['aceita_privacidade'])): ?><p class="erro-campo"><?= e($erros['aceita_privacidade']) ?></p><?php endif; ?>
            </div>

            <button type="submit" class="botao botao-grande">Criar conta</button>
        </form>

        <div class="auth-links">
            <a href="login.php" class="auth-link-completo">Já tens conta? Iniciar sessão</a>
        </div>
    </div>

    <aside class="auth-side-art" aria-hidden="true">
        <span class="auth-art-kicker">Nova conta</span>
        <h2 class="auth-art-title">Começar é<br><em>simples.</em></h2>
        <ul class="auth-art-list">
            <li>Marcação de consultas em poucos minutos</li>
            <li>Histórico clínico sempre à mão</li>
            <li>Familiares e dependentes associados</li>
        </ul>
        <span class="auth-art-footer"><?= e(defined('CLINICA_NOME') ? CLINICA_NOME : '') ?></span>
    </aside>
</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>