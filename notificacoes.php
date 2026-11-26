<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/marcacao.php';
require __DIR__ . '/includes/conta.php';

$u   = exigir_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $acao = (string)($_POST['acao'] ?? '');
    if ($acao === 'marcar_lidas') {
        $pdo->prepare('UPDATE notificacoes SET lida = 1 WHERE utilizador_id = ? AND lida = 0')->execute([$u['id']]);
        flash_definir('sucesso', 'Notificações marcadas como lidas.');
    } elseif ($acao === 'marcar_lida') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE notificacoes SET lida = 1 WHERE id = ? AND utilizador_id = ?')->execute([$id, $u['id']]);
        }
    }
    redirecionar('notificacoes.php');
}

// Ao abrir a área de notificações, marcar como lidas as notificações não lidas (estado persistido em BD)
$pdo->prepare('UPDATE notificacoes SET lida = 1 WHERE utilizador_id = ? AND lida = 0')->execute([$u['id']]);

$st = $pdo->prepare(
    'SELECT n.id, n.assunto, n.mensagem, n.lida, n.criado_em, m.referencia
       FROM notificacoes n LEFT JOIN marcacoes m ON m.id = n.marcacao_id
      WHERE n.utilizador_id = ?
      ORDER BY n.id DESC LIMIT 50'
);
$st->execute([$u['id']]);
$notificacoes = $st->fetchAll();

$tituloPagina = 'Notificações';
require __DIR__ . '/includes/cabecalho.php';
echo estilos_conta();
?>

<div class="dash-root">

    <div class="dash-page-top">
        <div>
            <span class="dash-kicker">Notificações</span>
            <h1>Notificações</h1>
            <p>Últimas atualizações da clínica sobre as tuas marcações.</p>
        </div>
    </div>

    <?= submenu_conta('notificacoes', 0) ?>

    <?php if (!$notificacoes): ?>
        <div class="dash-empty">
            <span class="dash-empty-symbol" aria-hidden="true">◆</span>
            <h3>Sem notificações</h3>
            <p>As notificações da clínica aparecem aqui.</p>
        </div>
    <?php else: ?>
        <p class="ajuda">As notificações apresentadas foram marcadas como lidas.</p>

        <div class="dash-list">
            <?php foreach ($notificacoes as $n): ?>
                <div class="dash-list-row">
                    <span class="dash-list-mark" aria-hidden="true">◆</span>
                    <div class="dash-list-copy">
                        <strong><?= e($n['assunto']) ?></strong>
                        <span><?= e($n['mensagem']) ?></span>
                        <small>
                            <?= e((new DateTime($n['criado_em']))->format('d/m/Y H:i')) ?>
                            <?php if ($n['referencia']): ?>
                                · <a href="marcar-confirmacao.php?ref=<?= e(urlencode($n['referencia'])) ?>"><?= e($n['referencia']) ?></a>
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>