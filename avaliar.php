<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/marcacao.php';
require __DIR__ . '/includes/conta.php';

$u   = exigir_login();
$pdo = db();

$consultaId = filter_input(INPUT_GET, 'consulta', FILTER_VALIDATE_INT) ?: 0;
if ($consultaId <= 0) {
    flash_definir('erro', 'Consulta inválida.');
    redirecionar('minhas-marcacoes.php');
}

$st = $pdo->prepare(
    'SELECT c.id, c.inicio, c.fim, c.estado, c.paciente_id, c.dentista_id,
            p.nome_completo AS paciente, d.nome_completo AS dentista,
            m.utilizador_id AS dono, m.referencia,
            a.id AS avaliacao_id, a.classificacao, a.comentario, a.estado AS av_estado
       FROM consultas c
       JOIN marcacoes m ON m.id = c.marcacao_id
       JOIN pacientes p ON p.id = c.paciente_id
       JOIN dentistas d ON d.id = c.dentista_id
       LEFT JOIN avaliacoes a ON a.consulta_id = c.id
      WHERE c.id = ? AND m.utilizador_id = ?'
);
$st->execute([$consultaId, $u['id']]);
$consulta = $st->fetch();

if (!$consulta) {
    flash_definir('erro', 'Consulta não encontrada.');
    redirecionar('minhas-marcacoes.php');
}

if ($consulta['estado'] !== 'concluida') {
    flash_definir('erro', 'Só podes avaliar consultas concluídas.');
    redirecionar('minhas-marcacoes.php');
}

if ($consulta['avaliacao_id'] !== null) {
    flash_definir('info', 'Já avaliaste esta consulta. Obrigado!');
    redirecionar('minhas-marcacoes.php');
}

$erros = [];
$form  = ['classificacao' => 5, 'comentario' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $form['classificacao'] = (int)($_POST['classificacao'] ?? 5);
    $form['comentario']    = mb_substr(trim((string)($_POST['comentario'] ?? '')), 0, 1000);

    if ($form['classificacao'] < 1 || $form['classificacao'] > 5) {
        $erros[] = 'Escolhe uma classificação de 1 a 5 estrelas.';
    }

    if ($erros === []) {
        try {
            $pdo->prepare(
                "INSERT INTO avaliacoes (consulta_id, paciente_id, dentista_id, classificacao, comentario, estado)
                 VALUES (?, ?, ?, ?, ?, 'pendente')"
            )->execute([
                $consultaId, (int)$consulta['paciente_id'], (int)$consulta['dentista_id'],
                $form['classificacao'], $form['comentario'] ?: null,
            ]);
            registar_admin('criar_avaliacao', 'avaliacoes', (int)$pdo->lastInsertId());
            flash_definir('sucesso', 'Avaliação enviada. Obrigado pelo teu feedback! Será publicada (de forma anónima) após moderação.');
            redirecionar('minhas-marcacoes.php');
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                $erros[] = 'Já avaliaste esta consulta.';
            } else {
                error_log('Erro avaliar: ' . $e->getMessage());
                $erros[] = 'Ocorreu um erro. Tenta novamente.';
            }
        }
    }
}

$tituloPagina = 'Avaliar consulta';
require __DIR__ . '/includes/cabecalho.php';
echo estilos_conta();
?>

<div class="dash-root">

    <div class="dash-page-top">
        <div>
            <span class="dash-kicker">Avaliação</span>
            <h1>Avaliar consulta</h1>
            <p>A tua opinião ajuda-nos a melhorar. A avaliação é publicada de forma anónima.</p>
        </div>
    </div>

    <?= submenu_conta('marcacoes', nao_lidas($pdo, $u['id'])) ?>

    <section class="cartao seccao">
        <h2>Consulta</h2>
        <dl class="dados">
            <dt>Paciente</dt><dd><?= e($consulta['paciente']) ?></dd>
            <dt>Médico dentista</dt><dd><?= e($consulta['dentista']) ?></dd>
            <dt>Data</dt><dd><?= e((new DateTime($consulta['inicio']))->format('d/m/Y H:i')) ?></dd>
        </dl>

        <div class="alerta alerta-info" role="note">
            <strong>Como funciona a publicação da tua avaliação:</strong>
            <ul>
                <li>A tua avaliação é <strong>publicada de forma anónima</strong>, sem nome, iniciais ou qualquer elemento que te identifique.</li>
                <li>O comentário é <strong>moderado pela clínica</strong> antes de ser publicado. Comentários que revelem dados pessoais ou de saúde, ou que identifiquem terceiros, não são publicados.</li>
                <li>As avaliações refletem experiências individuais. Os resultados clínicos variam de pessoa para pessoa e dependem de fatores individuais.</li>
                <li>Não são publicadas fotografias de resultados clínicos (antes/depois), em cumprimento das normas da OMD.</li>
            </ul>
        </div>

        <?php foreach ($erros as $erro): ?>
            <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
        <?php endforeach; ?>

        <form method="post" action="avaliar.php?consulta=<?= $consultaId ?>" novalidate>
            <?= csrf_campo() ?>

            <fieldset class="campo">
                <legend>Classificação <span class="obrigatorio" aria-hidden="true">*</span></legend>
                <div class="checks">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <label class="check">
                            <input type="radio" name="classificacao" value="<?= $i ?>"
                                   <?= $form['classificacao'] === $i ? 'checked' : '' ?> required>
                            <span><?= str_repeat('★', $i) ?> <span class="ajuda">(<?= $i ?>)</span></span>
                        </label>
                    <?php endfor; ?>
                </div>
            </fieldset>

            <div class="campo">
                <label for="comentario">Comentário (opcional, máx. 1000 caracteres)</label>
                <textarea id="comentario" name="comentario" rows="5" maxlength="1000"><?= e($form['comentario']) ?></textarea>
                <p class="ajuda">
                    Por favor, não incluas dados pessoais (nome, contacto, número de utente)
                    nem informação clínica detalhada. O comentário será moderado antes de ser publicado.
                </p>
            </div>

            <button type="submit" class="botao">Enviar avaliação</button>
            <a class="botao botao-secundario" href="minhas-marcacoes.php">Cancelar</a>
        </form>
    </section>

</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>