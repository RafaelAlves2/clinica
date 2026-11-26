<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';

if (utilizador_atual() !== null) {
    redirecionar('area-pessoal.php');
}

$erros  = [];
$token  = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$valido = false;
$userId = 0;
$nomeUser = '';

if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $tokenHash = hash('sha256', $token);
    $st = db()->prepare(
        "SELECT t.utilizador_id, u.nome_completo
           FROM tokens_conta t
           JOIN utilizadores u ON u.id = t.utilizador_id
          WHERE t.token_hash = ? AND t.tipo = 'recuperar_password'
            AND t.usado_em IS NULL AND t.expira_em > NOW()
            AND u.ativo = 1"
    );
    $st->execute([$tokenHash]);
    if ($row = $st->fetch()) {
        $valido   = true;
        $userId   = (int)$row['utilizador_id'];
        $nomeUser = (string)$row['nome_completo'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();

    if (!$valido) {
        $erros[] = 'Este link de recuperação é inválido ou já expirou.';
    }

    $password  = (string)($_POST['password']  ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if (strlen($password) < 8 || strlen($password) > 72
        || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $erros[] = 'A palavra-passe deve ter entre 8 e 72 caracteres, com pelo menos uma letra e um número.';
    }
    if ($password !== $password2) {
        $erros[] = 'As palavras-passe não coincidem.';
    }

    if ($erros === []) {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            $tokenHash = hash('sha256', $token);
            $stLock = $pdo->prepare(
                "SELECT id FROM tokens_conta
                  WHERE token_hash = ? AND tipo = 'recuperar_password'
                    AND usado_em IS NULL AND expira_em > NOW()
                  FOR UPDATE"
            );
            $stLock->execute([$tokenHash]);
            if (!$stLock->fetchColumn()) {
                throw new RuntimeException('TOKEN_INVALIDO');
            }

            $pdo->prepare('UPDATE utilizadores SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);

            $pdo->prepare('UPDATE tokens_conta SET usado_em = NOW() WHERE token_hash = ?')
                ->execute([$tokenHash]);

            registar_admin('repor_password', 'utilizadores', $userId);

            $pdo->prepare(
                "INSERT INTO notificacoes (utilizador_id, tipo, canal, assunto, mensagem, estado, enviada_em)
                 VALUES (?, 'password_alterada', 'interna', 'Palavra-passe alterada', ?, 'enviada', NOW())"
            )->execute([
                $userId,
                'A tua palavra-passe foi alterada com sucesso. Se não foste tu, contacta a clínica imediatamente.',
            ]);

            $pdo->commit();

            flash_definir('sucesso', 'Palavra-passe alterada com sucesso. Já podes iniciar sessão.');
            redirecionar('login.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($e->getMessage() === 'TOKEN_INVALIDO') {
                $erros[] = 'Este link já foi usado ou expirou. Pede um novo link de recuperação.';
            } else {
                error_log('Erro repor-password: ' . $e->getMessage());
                $erros[] = 'Ocorreu um erro. Tenta novamente.';
            }
        }
    }
}

$tituloPagina = 'Repor palavra-passe';
require __DIR__ . '/includes/cabecalho.php';
?>

<div class="auth-page">
    <div class="auth-side-form">
        <h1>Repor palavra-passe</h1>

        <?php if (!$valido): ?>
            <p class="auth-subtitle">Este link é inválido ou já expirou.</p>
            <div class="alerta alerta-erro" role="alert">
                Pede um novo link de recuperação para continuares.
            </div>
            <p>
                <a class="botao" href="recuperar-password.php">Pedir novo link</a>
            </p>
            <div class="auth-links">
                <a href="login.php">← Ir para o login</a>
            </div>

        <?php else: ?>
            <p class="auth-subtitle">
                Olá <strong><?= e($nomeUser) ?></strong>. Define a nova palavra-passe. O link expira em 1 hora e só serve uma vez.
            </p>

            <?php foreach ($erros as $erro): ?>
                <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
            <?php endforeach; ?>

            <form method="post" action="repor-password.php" novalidate>
                <?= csrf_campo() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">

                <div class="campo">
                    <label for="password">Nova palavra-passe</label>
                    <input type="password" id="password" name="password" required minlength="8" maxlength="72"
                           autocomplete="new-password" aria-describedby="aj-pwd">
                    <p id="aj-pwd" class="ajuda">Mínimo 8 caracteres, com pelo menos uma letra e um número.</p>
                </div>

                <div class="campo">
                    <label for="password2">Repetir nova palavra-passe</label>
                    <input type="password" id="password2" name="password2" required maxlength="72" autocomplete="new-password">
                </div>

                <button type="submit" class="botao botao-grande">Guardar nova palavra-passe</button>
            </form>

            <div class="auth-links">
                <a href="login.php">← Voltar ao login</a>
            </div>
        <?php endif; ?>
    </div>

    <aside class="auth-side-art" aria-hidden="true">
        <span class="auth-art-kicker">Nova palavra-passe</span>
        <h2 class="auth-art-title">Um novo<br><em>começo.</em></h2>
        <ul class="auth-art-list">
            <li>Mínimo 8 caracteres</li>
            <li>Pelo menos 1 letra e 1 número</li>
            <li>Válida imediatamente após confirmação</li>
        </ul>
        <span class="auth-art-footer"><?= e(defined('CLINICA_NOME') ? CLINICA_NOME : '') ?></span>
    </aside>
</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>