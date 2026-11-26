<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';

if (utilizador_atual() !== null) {
    redirecionar('area-pessoal.php');
}

$erros  = [];
$email  = '';
$link   = '';
$enviado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();

    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $ip    = ip_cliente();

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'Indica um email válido.';
    }

    $agora = time();
    $janela = 900;
    $_SESSION['recup_tentativas'] = array_values(array_filter(
        $_SESSION['recup_tentativas'] ?? [],
        static fn($t) => is_int($t) && ($agora - $t) < $janela
    ));
    if (count($_SESSION['recup_tentativas']) >= 5) {
        $erros[] = 'Demasiados pedidos. Tenta novamente mais tarde.';
    } else {
        $_SESSION['recup_tentativas'][] = $agora;
    }

    if ($erros === []) {
        try {
            $pdo = db();

            $st = $pdo->prepare('SELECT id, nome_completo, ativo FROM utilizadores WHERE email = ?');
            $st->execute([$email]);
            $user = $st->fetch();

            if ($user && (int)$user['ativo'] === 1) {
                $pdo->prepare("UPDATE tokens_conta SET usado_em = NOW() WHERE utilizador_id = ? AND tipo = 'recuperar_password' AND usado_em IS NULL")
                    ->execute([$user['id']]);

                $tokenClaro = bin2hex(random_bytes(32));
                $tokenHash  = hash('sha256', $tokenClaro);

                $pdo->prepare(
                    "INSERT INTO tokens_conta (utilizador_id, tipo, token_hash, expira_em)
                     VALUES (?, 'recuperar_password', ?, NOW() + INTERVAL 1 HOUR)"
                )->execute([$user['id'], $tokenHash]);

                registar_admin('pedir_recuperacao', 'utilizadores', (int)$user['id'], 'pedido');
                if (MODO_DEV) {
                    $link = BASE_URL . 'repor-password.php?token=' . urlencode($tokenClaro);
                }
            }

            $pdo->prepare('INSERT INTO tentativas_login (email, ip, sucesso) VALUES (?, ?, 0)')
                ->execute([mb_substr($email, 0, 190), $ip]);

            $enviado = true;
        } catch (PDOException $e) {
            error_log('Erro recuperar-password: ' . $e->getMessage());
            $erros[] = 'Ocorreu um erro. Tenta novamente.';
        }
    }
}

$tituloPagina = 'Recuperar palavra-passe';
require __DIR__ . '/includes/cabecalho.php';
?>

<div class="auth-page">
    <div class="auth-side-form">
        <h1>Recuperar palavra-passe</h1>

        <?php if ($enviado): ?>
            <p class="auth-subtitle">
                Se existir uma conta com <strong><?= e($email) ?></strong>, vais receber instruções para repor a palavra-passe.
            </p>

            <?php if ($link !== ''): ?>
                <div class="alerta alerta-info" role="note">
                    <strong>Modo demonstração:</strong> num site real este link seria enviado por email.
                    Aqui podes usá-lo diretamente. Expira em <strong>1 hora</strong> e só serve uma vez.
                </div>
                <p>
                    <a class="botao botao-grande" href="<?= e($link) ?>">Repor palavra-passe agora</a>
                </p>
                <p class="ajuda" style="word-break:break-all;"><?= e($link) ?></p>
            <?php endif; ?>

            <div class="auth-links">
                <a href="login.php">← Voltar ao login</a>
            </div>

        <?php else: ?>
            <p class="auth-subtitle">
                Indica o email com que te registaste. Vais receber um link para definires uma nova palavra-passe.
            </p>

            <?php foreach ($erros as $erro): ?>
                <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
            <?php endforeach; ?>

            <form method="post" action="recuperar-password.php" novalidate>
                <?= csrf_campo() ?>

                <div class="campo">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required maxlength="190"
                           autocomplete="email" value="<?= e($email) ?>">
                </div>

                <button type="submit" class="botao botao-grande">Enviar link de recuperação</button>
            </form>

            <div class="auth-links">
                <a href="login.php">← Voltar ao login</a>
                <a href="registo.php">Criar conta</a>
            </div>
        <?php endif; ?>
    </div>

    <aside class="auth-side-art" aria-hidden="true">
        <span class="auth-art-kicker">Recuperar acesso</span>
        <h2 class="auth-art-title">Vamos ajudar-te<br><em>a entrar.</em></h2>
        <ul class="auth-art-list">
            <li>Link único enviado por email</li>
            <li>Válido durante 1 hora</li>
            <li>Uso único — não reutilizável</li>
        </ul>
        <span class="auth-art-footer"><?= e(defined('CLINICA_NOME') ? CLINICA_NOME : '') ?></span>
    </aside>
</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>