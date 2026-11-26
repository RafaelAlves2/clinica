<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/marcacao.php';

if (utilizador_atual() !== null) {
    redirecionar('area-pessoal.php');
}

$email = mb_strtolower(trim((string)($_GET['email'] ?? $_POST['email'] ?? ($_SESSION['confirmar_email_pendente'] ?? ''))));
$erros = [];
$sucesso = '';
$codigoMostrado = '';

if (!empty($_SESSION['confirmar_email_codigo_dev']) && $email !== '' && $email === mb_strtolower((string)($_SESSION['confirmar_email_pendente'] ?? ''))) {
    $codigoMostrado = (string)$_SESSION['confirmar_email_codigo_dev'];
    unset($_SESSION['confirmar_email_codigo_dev']);
}

function _ce_rate_key(string $prefixo, string $valor): string
{
    return $prefixo . mb_substr($valor, 0, 180);
}
function _ce_contar(PDO $pdo, string $emailKey, string $ip, string $prefixo, int $janelaMin): array
{
    $st = $pdo->prepare(
        'SELECT
            SUM(email = ?) AS por_email,
            SUM(ip = ?)    AS por_ip
         FROM tentativas_login
         WHERE email LIKE ? AND sucesso = 0
           AND criado_em > (NOW() - INTERVAL ' . $janelaMin . ' MINUTE)'
    );
    $st->execute([$emailKey, $ip, $prefixo . '%']);
    $r = $st->fetch() ?: ['por_email' => 0, 'por_ip' => 0];
    return [(int)$r['por_email'], (int)$r['por_ip']];
}
function _ce_registar(PDO $pdo, string $emailKey, string $ip): void
{
    $pdo->prepare('INSERT INTO tentativas_login (email, ip, sucesso) VALUES (?, ?, 0)')
        ->execute([$emailKey, $ip]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $acao = (string)($_POST['acao'] ?? 'confirmar');
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'Indica um email válido.';
    }

    $pdo = db();
    $ip  = ip_cliente();

    if ($erros === [] && $acao === 'reenviar') {
        $emailKey = _ce_rate_key('#reenv#', $email);
        [$porEmail, $porIp] = _ce_contar($pdo, $emailKey, $ip, '#reenv#', 15);
        if ($porEmail >= 3 || $porIp >= 10) {
            $erros[] = 'Demasiados pedidos de reenvio. Tenta novamente dentro de alguns minutos.';
        } else {
            _ce_registar($pdo, $emailKey, $ip);

            $st = $pdo->prepare('SELECT id, email_verificado_em, ativo FROM utilizadores WHERE email = ?');
            $st->execute([$email]);
            $u = $st->fetch();
            if (!$u) {
                $sucesso = 'Se o email estiver registado e por confirmar, foi gerado um novo código de confirmação.';
            } elseif ((int)$u['ativo'] !== 1) {
                $sucesso = 'Se o email estiver registado e por confirmar, foi gerado um novo código de confirmação.';
            } elseif ($u['email_verificado_em'] !== null) {
                $sucesso = 'Se o email estiver registado e por confirmar, foi gerado um novo código de confirmação.';
            } else {
                $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $hash = hash('sha256', $codigo);
                $pdo->prepare("UPDATE tokens_conta SET usado_em = NOW() WHERE utilizador_id = ? AND tipo = 'verificar_email' AND usado_em IS NULL")
                    ->execute([(int)$u['id']]);
                $pdo->prepare("INSERT INTO tokens_conta (utilizador_id, tipo, token_hash, expira_em) VALUES (?, 'verificar_email', ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))")
                    ->execute([(int)$u['id'], $hash]);
                $_SESSION['confirmar_email_pendente'] = $email;
                if (defined('MODO_DEV') && MODO_DEV) {
                    $codigoMostrado = $codigo;
                    $sucesso = 'Novo código gerado (modo demonstração, sem envio real de email).';
                } else {
                    $sucesso = 'Pedido de confirmação registado. O envio automático de email não está configurado neste ambiente; solicita à administração o código de confirmação ou configura o envio de emails.';
                }
            }
        }
    }

    if ($erros === [] && $acao === 'confirmar') {
        $emailKey = _ce_rate_key('#conf#', $email);
        [$porEmail, $porIp] = _ce_contar($pdo, $emailKey, $ip, '#conf#', 15);
        if ($porEmail >= 10 || $porIp >= 30) {
            $erros[] = 'Demasiadas tentativas. Pede um novo código de confirmação ou tenta dentro de alguns minutos.';
        } else {
            $codigo = preg_replace('/\s+/', '', (string)($_POST['codigo'] ?? ''));
            if (!preg_match('/^\d{6}$/', $codigo)) {
                $erros[] = 'Indica o código de 6 dígitos recebido por email.';
            } else {
                _ce_registar($pdo, $emailKey, $ip);

                $hash = hash('sha256', $codigo);
                $st = $pdo->prepare(
                    "SELECT t.utilizador_id, t.expira_em, t.usado_em, u.email, u.email_verificado_em
                       FROM tokens_conta t
                       JOIN utilizadores u ON u.id = t.utilizador_id
                      WHERE t.token_hash = ? AND t.tipo = 'verificar_email' AND u.email = ?
                      LIMIT 1"
                );
                $st->execute([$hash, $email]);
                $tok = $st->fetch();
                if (!$tok) {
                    $erros[] = 'Código incorreto ou inválido. Verifica o código introduzido.';
                } elseif ($tok['usado_em'] !== null) {
                    $erros[] = 'Este código já foi utilizado. Pede um novo código.';
                } elseif ($tok['email_verificado_em'] !== null) {
                    $pdo->prepare('UPDATE tokens_conta SET usado_em = NOW() WHERE token_hash = ?')->execute([$hash]);
                    $sucesso = 'Este email já se encontra confirmado. Podes iniciar sessão.';
                } elseif (utc((string)$tok['expira_em']) < agora_bd($pdo)) {
                    $erros[] = 'O código expirou. Pede um novo código de confirmação.';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('UPDATE utilizadores SET email_verificado_em = NOW() WHERE id = ?')->execute([(int)$tok['utilizador_id']]);
                        $pdo->prepare("UPDATE tokens_conta SET usado_em = NOW() WHERE utilizador_id = ? AND tipo = 'verificar_email' AND usado_em IS NULL")
                            ->execute([(int)$tok['utilizador_id']]);
                        $pdo->commit();
                        unset($_SESSION['confirmar_email_pendente']);
                        flash_definir('sucesso', 'Email confirmado com sucesso! Já podes iniciar sessão.');
                        redirecionar('login.php');
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) { $pdo->rollBack(); }
                        error_log('Erro confirmação email: ' . $e->getMessage());
                        $erros[] = 'Ocorreu um erro ao confirmar o email. Tenta novamente.';
                    }
                }
            }
        }
    }
}

$tituloPagina = 'Confirmar email';
require __DIR__ . '/includes/cabecalho.php';
?>

<div class="auth-page">
    <div class="auth-side-form">
        <h1>Confirmar email</h1>
        <p class="auth-subtitle">
            Introduz o código de confirmação de 6 dígitos associado ao teu email para ativar a conta.
        </p>

        <?php foreach ($erros as $erro): ?>
            <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
        <?php endforeach; ?>
        <?php if ($sucesso !== ''): ?>
            <div class="alerta alerta-sucesso" role="status"><?= e($sucesso) ?></div>
        <?php endif; ?>
        <?php if ($codigoMostrado !== ''): ?>
            <div class="alerta alerta-info" role="status">
                <strong>Código de confirmação (demonstração):</strong> <?= e($codigoMostrado) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="confirmar-email.php" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="confirmar">
            <div class="campo">
                <label for="email">Email <span class="obrigatorio" aria-hidden="true">*</span></label>
                <input type="email" id="email" name="email" required maxlength="190" autocomplete="email" value="<?= e($email) ?>">
            </div>
            <div class="campo">
                <label for="codigo">Código de confirmação <span class="obrigatorio" aria-hidden="true">*</span></label>
                <input type="text" id="codigo" name="codigo" required maxlength="6" inputmode="numeric" pattern="\d{6}" autocomplete="one-time-code" placeholder="000000">
                <p class="ajuda">Código de 6 dígitos. Válido por 30 minutos. Se estiver errado ou expirado, pede um novo código.</p>
            </div>
            <button type="submit" class="botao botao-grande">Confirmar email</button>
        </form>

        <form method="post" action="confirmar-email.php" style="margin-top:1rem;">
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="reenviar">
            <input type="hidden" name="email" value="<?= e($email) ?>">
            <button type="submit" class="botao botao-secundario" style="width:100%;">Reenviar código</button>
        </form>

        <div class="auth-links">
            <a href="login.php">← Voltar ao início de sessão</a>
        </div>
    </div>

    <aside class="auth-side-art" aria-hidden="true">
        <span class="auth-art-kicker">Confirmação</span>
        <h2 class="auth-art-title">Um passo<br><em>quase no fim.</em></h2>
        <ul class="auth-art-list">
            <li>Código válido por 30 minutos</li>
            <li>Uso único — não reutilizável</li>
            <li>Protege a tua conta contra acessos indevidos</li>
        </ul>
        <span class="auth-art-footer"><?= e(defined('CLINICA_NOME') ? CLINICA_NOME : '') ?></span>
    </aside>
</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>