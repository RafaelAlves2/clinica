<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';

if (utilizador_atual() !== null) {
    redirecionar('area-pessoal.php');
}

$erro  = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();

    $email    = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $ip       = ip_cliente();
    $pdo      = db();

    $emailChave = mb_substr($email, 0, 190);

    $maxEmail = max(1, (int)config_valor($pdo, 'max_tentativas_login', (int)MAX_TENTATIVAS_EMAIL));
    $maxIp    = max(1, (int)config_valor($pdo, 'max_tentativas_ip', (int)MAX_TENTATIVAS_IP));
    $bloqMin  = max(1, (int)config_valor($pdo, 'bloqueio_login_min', (int)BLOQUEIO_MINUTOS));

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM tentativas_login
         WHERE email = ? AND sucesso = 0
           AND criado_em > (NOW() - INTERVAL ' . $bloqMin . ' MINUTE)'
    );
    $stmt->execute([$emailChave]);
    $falhasEmail = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM tentativas_login
         WHERE ip = ? AND sucesso = 0
           AND criado_em > (NOW() - INTERVAL ' . $bloqMin . ' MINUTE)'
    );
    $stmt->execute([$ip]);
    $falhasIp = (int)$stmt->fetchColumn();

    if ($falhasEmail >= $maxEmail || $falhasIp >= $maxIp) {
        $erro = 'Demasiadas tentativas falhadas. Tenta novamente mais tarde.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, nome_completo, papel, tema, password_hash, ativo
             FROM utilizadores WHERE email = ?'
        );
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        if ($u) {
            $passwordCorreta = password_verify($password, $u['password_hash']);
        } else {
            password_hash($password, PASSWORD_DEFAULT);
            $passwordCorreta = false;
        }

        if ($u && $passwordCorreta && (int)$u['ativo'] === 1) {
            $stmtEv = $pdo->prepare('SELECT email_verificado_em FROM utilizadores WHERE id = ?');
            $stmtEv->execute([(int)$u['id']]);
            $emailVerificadoEm = $stmtEv->fetchColumn();
            if ($emailVerificadoEm === false || $emailVerificadoEm === null) {
                $stTok = $pdo->prepare("SELECT 1 FROM tokens_conta WHERE utilizador_id = ? AND tipo = 'verificar_email' LIMIT 1");
                $stTok->execute([(int)$u['id']]);
                if ($stTok->fetchColumn()) {
                    $_SESSION['confirmar_email_pendente'] = $email;
                    $pdo->prepare('INSERT INTO tentativas_login (email, ip, sucesso) VALUES (?, ?, 0)')
                        ->execute([$emailChave, $ip]);
                    flash_definir('info', 'Confirma o teu email antes de iniciar sessão. Introduz o código de confirmação.');
                    redirecionar('confirmar-email.php?email=' . urlencode($email));
                }
                $pdo->prepare('UPDATE utilizadores SET email_verificado_em = NOW() WHERE id = ? AND email_verificado_em IS NULL')
                    ->execute([(int)$u['id']]);
            }

            session_regenerate_id(true);
            unset($_SESSION['csrf']);

            $_SESSION['utilizador'] = [
                'id'            => (int)$u['id'],
                'nome'          => $u['nome_completo'],
                'nome_completo' => $u['nome_completo'],
                'papel'         => $u['papel'],
                'tema'          => $u['tema'] ?? null,
            ];

            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                $novoHash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE utilizadores SET password_hash = ? WHERE id = ?')
                    ->execute([$novoHash, $u['id']]);
            }

            $pdo->prepare('UPDATE utilizadores SET ultimo_login = NOW() WHERE id = ?')->execute([$u['id']]);

            $pdo->prepare('DELETE FROM tentativas_login WHERE email = ? AND sucesso = 0')->execute([$emailChave]);
            $pdo->prepare('INSERT INTO tentativas_login (email, ip, sucesso) VALUES (?, ?, 1)')
                ->execute([$emailChave, $ip]);

            $destino = consumir_destino_apos_login();
            if ($destino !== null) {
                redirecionar($destino);
            }

            if (in_array($u['papel'], ['admin', 'rececao'], true)) {
                redirecionar('admin/index.php');
            }
            if (($u['papel'] ?? '') === 'dentista') {
                redirecionar('admin/marcacoes.php');
            }
            redirecionar('area-pessoal.php');
        }

        $pdo->prepare('INSERT INTO tentativas_login (email, ip, sucesso) VALUES (?, ?, 0)')
            ->execute([$emailChave, $ip]);
        $erro = 'Email ou palavra-passe incorretos.';
    }
}

$tituloPagina = 'Iniciar sessão';
require __DIR__ . '/includes/cabecalho.php';
?>

<div class="auth-page">
    <div class="auth-side-form">
        <h1>Iniciar sessão</h1>
        <p class="auth-subtitle">Entra na tua conta para gerir marcações, pagamentos e dados clínicos.</p>

        <?php if ($erro !== ''): ?>
            <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
            <?= csrf_campo() ?>

            <div class="campo">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required maxlength="190"
                       autocomplete="email" value="<?= e($email) ?>">
            </div>

            <div class="campo">
                <label for="password">Palavra-passe</label>
                <input type="password" id="password" name="password" required maxlength="72"
                       autocomplete="current-password">
            </div>

            <button type="submit" class="botao botao-grande">Entrar</button>
        </form>

        <div class="auth-links">
            <a href="registo.php">Criar conta</a>
            <a href="recuperar-password.php">Esqueceste a palavra-passe?</a>
        </div>
    </div>

    <aside class="auth-side-art" aria-hidden="true">
        <span class="auth-art-kicker">Área do paciente</span>
        <h2 class="auth-art-title">O teu sorriso,<br><em>sempre contigo.</em></h2>
        <ul class="auth-art-list">
            <li>Marcações e histórico clínico num só lugar</li>
            <li>Pagamentos online seguros</li>
            <li>Gestão de familiares e seguros</li>
        </ul>
        <span class="auth-art-footer"><?= e(defined('CLINICA_NOME') ? CLINICA_NOME : '') ?></span>
    </aside>
</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>