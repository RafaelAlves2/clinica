<?php
declare(strict_types=1);
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/gestao.php';

$u   = exigir_login(['admin']);
$pdo = db();

function processar_foto_testemunho(array $f): ?string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Não foi possível enviar a fotografia.');
    }
    if ($f['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('A fotografia não pode ter mais de 2 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($ext[$mime]) || @getimagesize($f['tmp_name']) === false) {
        throw new RuntimeException('A fotografia deve ser JPG, PNG ou WEBP.');
    }
    $pasta = __DIR__ . '/../uploads/testemunhos';
    if (!is_dir($pasta) && !mkdir($pasta, 0755, true)) {
        throw new RuntimeException('Não foi possível criar a pasta.');
    }
    $nome = bin2hex(random_bytes(16)) . '.' . $ext[$mime];
    if (!move_uploaded_file($f['tmp_name'], $pasta . '/' . $nome)) {
        throw new RuntimeException('Não foi possível guardar a fotografia.');
    }
    return 'uploads/testemunhos/' . $nome;
}

function apagar_foto_testemunho(?string $caminho): void
{
    if ($caminho && str_starts_with($caminho, 'uploads/testemunhos/')) {
        $f = __DIR__ . '/../' . $caminho;
        if (is_file($f)) { @unlink($f); }
    }
}

$erros = [];
$form  = ['id' => 0, 'nome' => '', 'texto' => '', 'local' => '', 'classificacao' => 5, 'ordem' => 0, 'visivel' => 1, 'consentimento_confirmado' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'apagar') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT foto FROM testemunhos WHERE id = ?');
        $st->execute([$id]);
        $foto = $st->fetchColumn() ?: null;
        $pdo->prepare('DELETE FROM testemunhos WHERE id = ?')->execute([$id]);
        apagar_foto_testemunho($foto);
        registar_admin('apagar_testemunho', 'testemunhos', $id);
        flash_definir('sucesso', 'Testemunho apagado.');
        redirecionar('admin/testemunhos.php');
    }

    if ($acao === 'alternar_visivel') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE testemunhos SET visivel = 1 - visivel WHERE id = ?')->execute([$id]);
        registar_admin('alternar_testemunho', 'testemunhos', $id);
        flash_definir('sucesso', 'Visibilidade atualizada.');
        redirecionar('admin/testemunhos.php');
    }

    if ($acao === 'guardar') {
        $form['id']                     = (int)($_POST['id'] ?? 0);
        $form['nome']                   = trim((string)($_POST['nome'] ?? ''));
        $form['texto']                  = trim((string)($_POST['texto'] ?? ''));
        $form['local']                  = trim((string)($_POST['local'] ?? ''));
        $form['classificacao']          = (int)($_POST['classificacao'] ?? 5);
        $form['ordem']                  = (int)($_POST['ordem'] ?? 0);
        $form['visivel']                = isset($_POST['visivel']) ? 1 : 0;
        $form['consentimento_confirmado'] = isset($_POST['consentimento_confirmado']) ? 1 : 0;

        if (mb_strlen($form['nome']) < 2 || mb_strlen($form['nome']) > 120) {
            $erros[] = 'Indica o nome do paciente (2 a 120 caracteres).';
        }
        if (mb_strlen($form['texto']) < 20) {
            $erros[] = 'O testemunho é demasiado curto.';
        }
        if ($form['classificacao'] < 1 || $form['classificacao'] > 5) {
            $form['classificacao'] = 5;
        }
        if (!$form['consentimento_confirmado']) {
            $erros[] = 'Tens de confirmar que o paciente deu consentimento escrito para publicar o testemunho.';
        }

        if ($erros === []) {
            $novaFoto = null;
            try {
                $novaFoto = processar_foto_testemunho($_FILES['foto'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
                $antiga   = null;

                if ($form['id'] > 0) {
                    $st = $pdo->prepare('SELECT foto FROM testemunhos WHERE id = ?');
                    $st->execute([$form['id']]);
                    $antiga = $st->fetchColumn() ?: null;

                    $pdo->prepare(
                        'UPDATE testemunhos SET nome = ?, texto = ?, local = ?, classificacao = ?, ordem = ?,
                                visivel = ?, consentimento_confirmado = ?,
                                foto = COALESCE(?, foto)
                          WHERE id = ?'
                    )->execute([
                        $form['nome'], $form['texto'], $form['local'] ?: null,
                        $form['classificacao'], $form['ordem'], $form['visivel'],
                        $form['consentimento_confirmado'], $novaFoto, $form['id'],
                    ]);
                    if ($novaFoto !== null) { apagar_foto_testemunho($antiga); }
                    registar_admin('editar_testemunho', 'testemunhos', $form['id']);
                    flash_definir('sucesso', 'Testemunho atualizado.');
                } else {
                    $pdo->prepare(
                        'INSERT INTO testemunhos (nome, texto, local, foto, classificacao, ordem, visivel, consentimento_confirmado)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $form['nome'], $form['texto'], $form['local'] ?: null, $novaFoto,
                        $form['classificacao'], $form['ordem'], $form['visivel'], $form['consentimento_confirmado'],
                    ]);
                    registar_admin('criar_testemunho', 'testemunhos', (int)$pdo->lastInsertId());
                    flash_definir('sucesso', 'Testemunho criado.');
                }
                redirecionar('admin/testemunhos.php');
            } catch (RuntimeException $e) {
                apagar_foto_testemunho($novaFoto);
                $erros[] = $e->getMessage();
            } catch (PDOException $e) {
                apagar_foto_testemunho($novaFoto);
                error_log('Erro testemunho: ' . $e->getMessage());
                $erros[] = 'Ocorreu um erro. Tenta novamente.';
            }
        }
    }
}

$editarId = (int)($_GET['editar'] ?? 0);
if ($editarId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $pdo->prepare('SELECT * FROM testemunhos WHERE id = ?');
    $st->execute([$editarId]);
    if ($t = $st->fetch()) {
        $form = [
            'id' => (int)$t['id'], 'nome' => $t['nome'], 'texto' => $t['texto'],
            'local' => (string)$t['local'], 'classificacao' => (int)$t['classificacao'],
            'ordem' => (int)$t['ordem'], 'visivel' => (int)$t['visivel'],
            'consentimento_confirmado' => (int)$t['consentimento_confirmado'],
        ];
    }
}

$testemunhos = $pdo->query('SELECT * FROM testemunhos ORDER BY ordem, id DESC')->fetchAll();

$tituloPagina = 'Testemunhos';
require __DIR__ . '/../includes/cabecalho.php';
echo estilos_gestao();
?>

<p><a href="admin/index.php">← Painel</a></p>
<h1>Testemunhos de pacientes</h1>

<div class="alerta alerta-info" role="note">
    Só são publicados testemunhos com <strong>consentimento escrito</strong> do paciente.
    As experiências individuais variam e não constituem promessa de resultados.
</div>

<?php foreach ($erros as $erro): ?>
    <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
<?php endforeach; ?>

<section class="seccao">
    <?php if (!$testemunhos): ?>
        <div class="alerta alerta-info">Ainda não há testemunhos.</div>
    <?php else: ?>
        <div class="tabela-scroll">
            <table class="tabela">
                <thead><tr><th>Paciente</th><th>Texto</th><th>Estado</th><th>Consentimento</th><th>Ações</th></tr></thead>
                <tbody>
                <?php foreach ($testemunhos as $t): ?>
                    <tr>
                        <td><?= e($t['nome']) ?><?= $t['local'] ? '<br><span class="ajuda">' . e($t['local']) . '</span>' : '' ?></td>
                        <td><?= e(mb_substr($t['texto'], 0, 120)) ?><?= mb_strlen($t['texto']) > 120 ? '…' : '' ?></td>
                        <td><?= (int)$t['visivel'] ? 'Visível' : '<span class="estado-inativo">Oculto</span>' ?></td>
                        <td><?= (int)$t['consentimento_confirmado'] ? 'Sim' : '<span class="estado-inativo">Não</span>' ?></td>
                        <td>
                            <a class="botao botao-secundario" href="admin/testemunhos.php?editar=<?= (int)$t['id'] ?>">Editar</a>
                            <form method="post" action="admin/testemunhos.php" class="form-inline">
                                <?= csrf_campo() ?>
                                <input type="hidden" name="acao" value="alternar_visivel">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="botao botao-secundario"><?= (int)$t['visivel'] ? 'Ocultar' : 'Mostrar' ?></button>
                            </form>
                            <form method="post" action="admin/testemunhos.php" class="form-inline" onsubmit="return confirm('Apagar este testemunho?');">
                                <?= csrf_campo() ?>
                                <input type="hidden" name="acao" value="apagar">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
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
    <h2><?= $form['id'] > 0 ? 'Editar testemunho' : 'Novo testemunho' ?></h2>
    <form method="post" action="admin/testemunhos.php" enctype="multipart/form-data" novalidate>
        <?= csrf_campo() ?>
        <input type="hidden" name="acao" value="guardar">
        <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">

        <div class="campo">
            <label for="nome">Nome do paciente <span class="obrigatorio">*</span></label>
            <input type="text" id="nome" name="nome" required maxlength="120" value="<?= e($form['nome']) ?>">
        </div>
        <div class="campo">
            <label for="local">Local (opcional)</label>
            <input type="text" id="local" name="local" maxlength="80" value="<?= e($form['local']) ?>" placeholder="ex.: Portimão">
        </div>
        <div class="campo">
            <label for="texto">Testemunho <span class="obrigatorio">*</span></label>
            <textarea id="texto" name="texto" required rows="4"><?= e($form['texto']) ?></textarea>
        </div>
        <div class="campo">
            <label for="classificacao">Classificação (1-5)</label>
            <input type="number" id="classificacao" name="classificacao" min="1" max="5" value="<?= (int)$form['classificacao'] ?>">
        </div>
        <div class="campo">
            <label for="foto">Fotografia (opcional, máx. 2 MB)</label>
            <input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp">
        </div>
        <div class="campo">
            <label for="ordem">Ordem (0 = primeiro)</label>
            <input type="number" id="ordem" name="ordem" min="0" max="999" value="<?= (int)$form['ordem'] ?>">
        </div>
        <div class="campo campo-checkbox">
            <input type="checkbox" id="visivel" name="visivel" value="1" <?= $form['visivel'] ? 'checked' : '' ?>>
            <label for="visivel">Mostrar no site</label>
        </div>
        <div class="campo campo-checkbox">
            <input type="checkbox" id="consentimento_confirmado" name="consentimento_confirmado" value="1" <?= $form['consentimento_confirmado'] ? 'checked' : '' ?>>
            <label for="consentimento_confirmado">Confirmo que tenho consentimento escrito do paciente para publicar este testemunho (RGPD).</label>
        </div>

        <button type="submit" class="botao"><?= $form['id'] > 0 ? 'Guardar alterações' : 'Criar testemunho' ?></button>
        <?php if ($form['id'] > 0): ?><a class="botao botao-secundario" href="admin/testemunhos.php">Cancelar</a><?php endif; ?>
    </form>
</section>

<?php require __DIR__ . '/../includes/rodape.php'; ?>