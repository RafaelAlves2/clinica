<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/conteudo.php';

$pdo = db();
$erros = [];
$form  = ['nome' => '', 'email' => '', 'telemovel' => '', 'area' => '', 'mensagem' => ''];
$enviado = false;

$areas = [
    'medico_dentista'   => 'Médico dentista',
    'assistente'        => 'Assistente dentário/a',
    'rececao'           => 'Receção / atendimento',
    'higienista'        => 'Higienista oral',
    'gestao'            => 'Gestão / administração',
    'outro'             => 'Outro',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();

    $form['nome']      = trim((string)($_POST['nome'] ?? ''));
    $form['email']     = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $form['telemovel'] = preg_replace('/\s+/', '', trim((string)($_POST['telemovel'] ?? '')));
    $form['area']      = (string)($_POST['area'] ?? '');
    $form['mensagem']  = trim((string)($_POST['mensagem'] ?? ''));

    if (mb_strlen($form['nome']) < 3 || mb_strlen($form['nome']) > 150) {
        $erros[] = 'Indica o teu nome (3 a 150 caracteres).';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 190) {
        $erros[] = 'Indica um email válido.';
    }
    if ($form['telemovel'] !== '' && !preg_match('/^\+?\d{9,15}$/', $form['telemovel'])) {
        $erros[] = 'O telemóvel não é válido (só números).';
    }
    if (!isset($areas[$form['area']])) {
        $erros[] = 'Escolhe a área a que te candidatas.';
    }
    if (mb_strlen($form['mensagem']) > 2000) {
        $erros[] = 'A mensagem não pode ter mais de 2000 caracteres.';
    }

    if ($erros === []) {
        $ip = ip_cliente();
        $st = $pdo->prepare('SELECT COUNT(*) FROM candidaturas WHERE ip = ? AND criado_em > (NOW() - INTERVAL 1 HOUR)');
        $st->execute([$ip]);
        if ((int)$st->fetchColumn() >= 3) {
            $erros[] = 'Já enviaste várias candidaturas. Tenta novamente daqui a uma hora.';
        }
    }

    $cvPath = null;
    if ($erros === [] && isset($_FILES['cv']) && ($_FILES['cv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['cv'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $erros[] = 'Não foi possível enviar o CV.';
        } elseif ($f['size'] > 5 * 1024 * 1024) {
            $erros[] = 'O CV não pode ter mais de 5 MB.';
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            $ext  = [
                'application/pdf' => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            ];
            if (!isset($ext[$mime])) {
                $erros[] = 'O CV deve ser PDF, DOC ou DOCX.';
            } else {
                $pasta = __DIR__ . '/uploads/cv';
                if (!is_dir($pasta) && !mkdir($pasta, 0755, true)) {
                    $erros[] = 'Não foi possível guardar o CV.';
                } else {
                    $nome = bin2hex(random_bytes(16)) . '.' . $ext[$mime];
                    if (move_uploaded_file($f['tmp_name'], $pasta . '/' . $nome)) {
                        $cvPath = 'uploads/cv/' . $nome;
                    } else {
                        $erros[] = 'Não foi possível guardar o CV.';
                    }
                }
            }
        }
    }

    if ($erros === []) {
        try {
            $pdo->prepare(
                'INSERT INTO candidaturas (nome, email, telemovel, area, mensagem, cv, ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $form['nome'], $form['email'], $form['telemovel'] ?: null,
                $form['area'], $form['mensagem'] ?: null, $cvPath, ip_cliente(),
            ]);
            $enviado = true;
        } catch (PDOException $e) {
            error_log('Erro candidatura: ' . $e->getMessage());
            $erros[] = 'Ocorreu um erro. Tenta novamente.';
            if ($cvPath !== null && is_file(__DIR__ . '/' . $cvPath)) {
                @unlink(__DIR__ . '/' . $cvPath);
            }
        }
    }
}

$tituloPagina = 'Trabalha connosco';
require __DIR__ . '/includes/cabecalho.php';
?>

<section class="interior-hero">
    <div class="interior-hero-top">
        <div class="interior-hero-kicker">
            <span class="kicker-num">07 / 2026</span>
            <span class="kicker-line" aria-hidden="true"></span>
            <span>Carreiras</span>
        </div>
        <span class="interior-hero-aside">Candidatura espontânea</span>
    </div>
    <h1><?= e(conteudo('trabalha_titulo', 'Trabalha connosco')) ?><span class="ponto">.</span></h1>
    <div class="interior-hero-bottom">
        <p>
            Procuramos pessoas que partilhem os nossos valores: rigor clínico,
            proximidade e transparência. Respondemos a todas as candidaturas.
        </p>
        <span>Envia a tua ↓</span>
    </div>
</section>

<?php if ($enviado): ?>
    <div class="alerta alerta-sucesso" role="alert">
        Candidatura recebida. Entraremos em contacto se houver uma oportunidade adequada ao teu perfil.
    </div>
    <p><a class="botao" href="index.php">← Voltar ao início</a></p>
<?php else: ?>
    <?php foreach ($erros as $erro): ?>
        <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
    <?php endforeach; ?>

    <section class="cartao seccao">
        <h2>Enviar candidatura</h2>
        <form method="post" action="trabalha-connosco.php" enctype="multipart/form-data" novalidate>
            <?= csrf_campo() ?>

            <div class="campo">
                <label for="nome">Nome completo <span class="obrigatorio" aria-hidden="true">*</span></label>
                <input type="text" id="nome" name="nome" required maxlength="150" value="<?= e($form['nome']) ?>">
            </div>
            <div class="campo">
                <label for="email">Email <span class="obrigatorio" aria-hidden="true">*</span></label>
                <input type="email" id="email" name="email" required maxlength="190" value="<?= e($form['email']) ?>">
            </div>
            <div class="campo">
                <label for="telemovel">Telemóvel (opcional)</label>
                <input type="tel" id="telemovel" name="telemovel" maxlength="20" value="<?= e($form['telemovel']) ?>">
            </div>
            <div class="campo">
                <label for="area">Área a que te candidatas <span class="obrigatorio" aria-hidden="true">*</span></label>
                <select id="area" name="area" required>
                    <option value="">— escolher —</option>
                    <?php foreach ($areas as $v => $t): ?>
                        <option value="<?= e($v) ?>" <?= $form['area'] === $v ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label for="mensagem">Mensagem (opcional, máx. 2000 caracteres)</label>
                <textarea id="mensagem" name="mensagem" rows="5" maxlength="2000"><?= e($form['mensagem']) ?></textarea>
                <p class="ajuda">Fala-nos da tua experiência e do que te motiva.</p>
            </div>
            <div class="campo">
                <label for="cv">CV (PDF, DOC ou DOCX, máx. 5 MB)</label>
                <input type="file" id="cv" name="cv" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
            </div>

            <button type="submit" class="botao">Enviar candidatura</button>
        </form>
    </section>

    <section class="cartao seccao">
        <h2>O que oferecemos</h2>
        <ul>
            <li>Equipa estável e multidisciplinar;</li>
            <li>Formação contínua e acesso a tecnologia atual;</li>
            <li>Ambiente de trabalho respeitoso e colaborativo;</li>
            <li>Condições negociadas caso a caso, conforme a experiência.</li>
        </ul>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/includes/rodape.php'; ?>