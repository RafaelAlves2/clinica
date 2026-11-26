<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/conteudo.php';

$pdo = db();
$erros = [];
$form  = ['nome' => '', 'email' => '', 'telemovel' => '', 'assunto' => '', 'mensagem' => ''];
$enviado = false;

$porDia = [];
$linhas = $pdo->query('SELECT dia_semana, hora_inicio, hora_fim FROM horarios_funcionamento ORDER BY dia_semana, hora_inicio')->fetchAll();
foreach ($linhas as $h) {
    $porDia[(int)$h['dia_semana']][] = substr($h['hora_inicio'], 0, 5) . ' – ' . substr($h['hora_fim'], 0, 5);
}

$moradaClinica = clinica_dado($pdo, 'clinica_morada', CLINICA_MORADA);
$mapaEmbed = trim(config_valor_texto($pdo, 'mapa_embed_url', ''));
$mapaHost = strtolower((string)parse_url($mapaEmbed, PHP_URL_HOST));

if (
    $mapaEmbed === ''
    || !in_array(parse_url($mapaEmbed, PHP_URL_SCHEME), ['https'], true)
    || !preg_match('/(^|\\.)google\\.(com|pt)$/i', $mapaHost)
) {
    $mapaEmbed = 'https://www.google.com/maps?q=' . rawurlencode($moradaClinica) . '&output=embed';
}

$mapaLink = trim(config_valor_texto($pdo, 'mapa_link', ''));
$mapaLinkHost = strtolower((string)parse_url($mapaLink, PHP_URL_HOST));
if (
    $mapaLink === ''
    || parse_url($mapaLink, PHP_URL_SCHEME) !== 'https'
    || !preg_match('/(^|\\.)(google\\.(com|pt)|goo\\.gl)$/i', $mapaLinkHost)
) {
    $mapaLink = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($moradaClinica);
}

$contactoEmail    = config_valor_texto($pdo, 'contacto_email', CLINICA_EMAIL);
$contactoTelefone = config_valor_texto($pdo, 'contacto_telefone', CLINICA_TELEFONE);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();

    $form['nome']      = trim((string)($_POST['nome'] ?? ''));
    $form['email']     = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $form['telemovel'] = preg_replace('/\s+/', '', trim((string)($_POST['telemovel'] ?? '')));
    $form['assunto']   = trim((string)($_POST['assunto'] ?? ''));
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
    if (mb_strlen($form['assunto']) < 3 || mb_strlen($form['assunto']) > 200) {
        $erros[] = 'Indica o assunto (3 a 200 caracteres).';
    }
    if (mb_strlen($form['mensagem']) < 10 || mb_strlen($form['mensagem']) > 5000) {
        $erros[] = 'A mensagem deve ter entre 10 e 5000 caracteres.';
    }

    if ($erros === []) {
        $ip = ip_cliente();
        $st = $pdo->prepare('SELECT COUNT(*) FROM mensagens_contacto WHERE ip = ? AND criado_em > (NOW() - INTERVAL 1 HOUR)');
        $st->execute([$ip]);
        if ((int)$st->fetchColumn() >= 3) {
            $erros[] = 'Já enviaste várias mensagens. Tenta novamente daqui a uma hora.';
        }
    }

    if ($erros === []) {
        try {
            $pdo->prepare(
                'INSERT INTO mensagens_contacto (nome, email, telemovel, assunto, mensagem, ip)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $form['nome'], $form['email'], $form['telemovel'] ?: null,
                $form['assunto'], $form['mensagem'], ip_cliente(),
            ]);
            $enviado = true;
        } catch (PDOException $e) {
            error_log('Erro contacto: ' . $e->getMessage());
            $erros[] = 'Ocorreu um erro. Tenta novamente.';
        }
    }
}

$tituloPagina = 'Contactos';
require __DIR__ . '/includes/cabecalho.php';
?>

<section class="interior-hero">
    <div class="interior-hero-top">
        <div class="interior-hero-kicker">
            <span class="kicker-num">05 / 2026</span>
            <span class="kicker-line" aria-hidden="true"></span>
            <span>Contactos</span>
        </div>
        <span class="interior-hero-aside">Estamos por aqui</span>
    </div>
    <h1><?= e(conteudo('contactos_titulo', 'Fala connosco')) ?><span class="ponto">.</span></h1>
    <div class="interior-hero-bottom">
        <p>
            Para marcações, usa a área de marcação online. Para outras questões,
            envia-nos uma mensagem ou liga diretamente.
        </p>
        <span>Escreve-nos ↓</span>
    </div>
</section>

<section class="grelha-info" data-stagger="100">
    <div class="cartao" data-animar="up">
        <h2>Onde estamos</h2>
        <p><?= e($moradaClinica) ?></p>
        <p>Telefone: <a href="tel:<?= e(preg_replace('/\s+/', '', $contactoTelefone)) ?>"><?= e($contactoTelefone) ?></a></p>
        <p>Email: <a href="mailto:<?= e($contactoEmail) ?>"><?= e($contactoEmail) ?></a></p>
        <?php if ($mapaLink !== ''): ?>
            <p><a class="botao botao-secundario" href="<?= e($mapaLink) ?>" target="_blank" rel="noopener">Abrir no Google Maps</a></p>
        <?php endif; ?>
    </div>

    <div class="cartao" data-animar="up">
        <h2>Horário</h2>
        <?php if ($porDia): ?>
            <dl class="dados">
                <?php foreach ($porDia as $dia => $blocos): ?>
                    <dt><?= e(nome_dia($dia)) ?></dt><dd><?= e(implode(' · ', $blocos)) ?></dd>
                <?php endforeach; ?>
            </dl>
        <?php else: ?>
            <p class="ajuda">Horário por definir.</p>
        <?php endif; ?>
    </div>

    <div class="cartao" data-animar="up">
        <h2>Localização no mapa</h2>
        <div class="mapa-embed">
            <iframe
                src="<?= e($mapaEmbed) ?>"
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"
                title="Localização da clínica"></iframe>
        </div>
        <?php if ($mapaLink !== ''): ?>
            <p class="ajuda" style="margin-top:.6rem;"><a href="<?= e($mapaLink) ?>" target="_blank" rel="noopener">Abrir no Google Maps</a></p>
        <?php endif; ?>
    </div>
</section>

<section class="cartao seccao" data-animar="up">
    <h2>Envia-nos uma mensagem</h2>

    <?php if ($enviado): ?>
        <div class="alerta alerta-sucesso" role="alert">
            Mensagem enviada. Responderemos com a maior brevidade possível.
        </div>
    <?php else: ?>
        <?php foreach ($erros as $erro): ?>
            <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
        <?php endforeach; ?>

        <form method="post" action="contactos.php" novalidate>
            <?= csrf_campo() ?>
            <div class="campo">
                <label for="nome">Nome <span class="obrigatorio" aria-hidden="true">*</span></label>
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
                <label for="assunto">Assunto <span class="obrigatorio" aria-hidden="true">*</span></label>
                <input type="text" id="assunto" name="assunto" required maxlength="200" value="<?= e($form['assunto']) ?>">
            </div>
            <div class="campo">
                <label for="mensagem">Mensagem <span class="obrigatorio" aria-hidden="true">*</span></label>
                <textarea id="mensagem" name="mensagem" required rows="6" maxlength="5000"><?= e($form['mensagem']) ?></textarea>
                <p class="ajuda">Não incluas dados clínicos detalhados. Para assuntos de saúde, marca uma consulta.</p>
            </div>
            <button type="submit" class="botao">Enviar mensagem</button>
        </form>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/rodape.php'; ?>