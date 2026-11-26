<?php
declare(strict_types=1);
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/gestao.php';

$u   = exigir_login(['admin']);
$pdo = db();

$erros = [];
$form  = ['id' => 0, 'pergunta' => '', 'resposta' => '', 'ordem' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'alternar_visivel') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE perguntas_frequentes SET visivel = 1 - visivel WHERE id = ?')->execute([$id]);
        registar_admin('alternar_visivel_faq', 'perguntas_frequentes', $id);
        flash_definir('sucesso', 'Visibilidade atualizada.');
        redirecionar('admin/perguntas-frequentes.php');
    }

    if ($acao === 'apagar') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM perguntas_frequentes WHERE id = ?')->execute([$id]);
        registar_admin('apagar_faq', 'perguntas_frequentes', $id);
        flash_definir('sucesso', 'Pergunta apagada.');
        redirecionar('admin/perguntas-frequentes.php');
    }

    if ($acao === 'guardar') {
        $form['id']       = (int)($_POST['id'] ?? 0);
        $form['pergunta'] = trim((string)($_POST['pergunta'] ?? ''));
        $form['resposta'] = trim((string)($_POST['resposta'] ?? ''));
        $form['ordem']    = (int)($_POST['ordem'] ?? 0);

        if (mb_strlen($form['pergunta']) < 5 || mb_strlen($form['pergunta']) > 255) {
            $erros[] = 'A pergunta deve ter entre 5 e 255 caracteres.';
        }
        if (mb_strlen($form['resposta']) < 5) {
            $erros[] = 'A resposta é demasiado curta.';
        }

        if ($erros === []) {
            if ($form['id'] > 0) {
                $pdo->prepare('UPDATE perguntas_frequentes SET pergunta = ?, resposta = ?, ordem = ? WHERE id = ?')
                    ->execute([$form['pergunta'], $form['resposta'], $form['ordem'], $form['id']]);
                registar_admin('editar_faq', 'perguntas_frequentes', $form['id'], $form['pergunta']);
                flash_definir('sucesso', 'Pergunta atualizada.');
            } else {
                $pdo->prepare('INSERT INTO perguntas_frequentes (pergunta, resposta, ordem) VALUES (?, ?, ?)')
                    ->execute([$form['pergunta'], $form['resposta'], $form['ordem']]);
                registar_admin('criar_faq', 'perguntas_frequentes', (int)$pdo->lastInsertId(), $form['pergunta']);
                flash_definir('sucesso', 'Pergunta criada.');
            }
            redirecionar('admin/perguntas-frequentes.php');
        }
    }
}

$editarId = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT) ?: 0;
if ($editarId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $pdo->prepare('SELECT id, pergunta, resposta, ordem FROM perguntas_frequentes WHERE id = ?');
    $st->execute([$editarId]);
    if ($f = $st->fetch()) {
        $form = [
            'id' => (int)$f['id'],
            'pergunta' => $f['pergunta'],
            'resposta' => $f['resposta'],
            'ordem' => (int)$f['ordem'],
        ];
    }
}

$perguntas = $pdo->query('SELECT id, pergunta, resposta, ordem, visivel FROM perguntas_frequentes ORDER BY ordem, id')->fetchAll();

$tituloPagina = 'Perguntas frequentes';
require __DIR__ . '/../includes/cabecalho.php';
echo estilos_gestao();
?>

<p><a href="admin/index.php">← Painel</a></p>
<h1>Perguntas frequentes</h1>

<section class="seccao">
    <?php if (!$perguntas): ?>
        <div class="alerta alerta-info">Ainda não há perguntas.</div>
    <?php else: ?>
        <div class="tabela-scroll">
            <table class="tabela">
                <thead><tr><th>Ordem</th><th>Pergunta</th><th>Estado</th><th>Ações</th></tr></thead>
                <tbody>
                <?php foreach ($perguntas as $p): ?>
                    <tr>
                        <td><?= (int)$p['ordem'] ?></td>
                        <td><?= e($p['pergunta']) ?></td>
                        <td><?= (int)$p['visivel'] ? 'Visível' : '<span class="estado-inativo">Oculta</span>' ?></td>
                        <td>
                            <a class="botao botao-secundario" href="admin/perguntas-frequentes.php?editar=<?= (int)$p['id'] ?>">Editar</a>
                            <form method="post" action="admin/perguntas-frequentes.php" class="form-inline">
                                <?= csrf_campo() ?>
                                <input type="hidden" name="acao" value="alternar_visivel">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" class="botao botao-secundario">
                                    <?= (int)$p['visivel'] ? 'Ocultar' : 'Mostrar' ?>
                                </button>
                            </form>
                            <form method="post" action="admin/perguntas-frequentes.php" class="form-inline" onsubmit="return confirm('Apagar esta pergunta?');">
                                <?= csrf_campo() ?>
                                <input type="hidden" name="acao" value="apagar">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" class="botao botao-secundario">Apagar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="cartao">
    <h2><?= $form['id'] > 0 ? 'Editar pergunta' : 'Nova pergunta' ?></h2>

    <?php foreach ($erros as $erro): ?>
        <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
    <?php endforeach; ?>

    <form method="post" action="admin/perguntas-frequentes.php" novalidate>
        <?= csrf_campo() ?>
        <input type="hidden" name="acao" value="guardar">
        <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">

        <div class="campo">
            <label for="pergunta">Pergunta <span class="obrigatorio" aria-hidden="true">*</span></label>
            <input type="text" id="pergunta" name="pergunta" required maxlength="255" value="<?= e($form['pergunta']) ?>">
        </div>

        <div class="campo">
            <label for="resposta">Resposta <span class="obrigatorio" aria-hidden="true">*</span></label>
            <textarea id="resposta" name="resposta" required rows="4"><?= e($form['resposta']) ?></textarea>
        </div>

        <div class="campo">
            <label for="ordem">Ordem (0 = primeiro)</label>
            <input type="number" id="ordem" name="ordem" min="0" max="999" value="<?= (int)$form['ordem'] ?>">
        </div>

        <button type="submit" class="botao"><?= $form['id'] > 0 ? 'Guardar alterações' : 'Criar pergunta' ?></button>
        <?php if ($form['id'] > 0): ?>
            <a class="botao botao-secundario" href="admin/perguntas-frequentes.php">Cancelar</a>
        <?php endif; ?>
    </form>
</section>

<?php require __DIR__ . '/../includes/rodape.php'; ?>