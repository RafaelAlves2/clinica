<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/conteudo.php';
require __DIR__ . '/includes/cookies.php';

$tituloPagina = conteudo('cookies_titulo', 'Política de Cookies');
require __DIR__ . '/includes/cabecalho.php';
?>

<section class="interior-hero">
    <div class="interior-hero-top">
        <div class="interior-hero-kicker">
            <span class="kicker-num">Legal</span>
            <span class="kicker-line" aria-hidden="true"></span>
            <span>RGPD · CNPD</span>
        </div>
        <span class="interior-hero-aside">Cookies essenciais</span>
    </div>
    <h1><?= e(conteudo('cookies_titulo', 'Política de Cookies')) ?><span class="ponto">.</span></h1>
    <div class="interior-hero-bottom">
        <p>
            Em conformidade com o RGPD e com as orientações da Comissão Nacional de Proteção de Dados (CNPD).
        </p>
        <span>O que guardamos ↓</span>
    </div>
</section>

<?php $__ci = trim(conteudo('cookies_intro', '')); if ($__ci !== ''): ?>
    <p><?= nl2br(e($__ci)) ?></p>
<?php endif; ?>

<section class="cartao seccao">
    <h2>1. O que são cookies?</h2>
    <p>
        Cookies são pequenos ficheiros de texto guardados no teu dispositivo quando visitas
        um site. Permitem que o site se lembre das tuas preferências e funcionem corretamente
        entre visitas.
    </p>
</section>

<section class="cartao seccao">
    <h2>2. Cookies que usamos</h2>
    <div class="tabela-scroll">
        <table class="tabela">
            <thead>
                <tr><th>Cookie</th><th>Tipo</th><th>Finalidade</th><th>Duração</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>CLINICA_SESS</code></td>
                    <td>Essencial</td>
                    <td>Sessão do utilizador (login, marcações, carrinho de marcação)</td>
                    <td>Até fechar o browser</td>
                </tr>
                <tr>
                    <td><code>consentimento_cookies</code></td>
                    <td>Essencial</td>
                    <td>Guarda a tua escolha sobre cookies</td>
                    <td>180 dias</td>
                </tr>
                <tr>
                    <td><code>tema</code> (localStorage)</td>
                    <td>Essencial</td>
                    <td>Guarda a preferência de modo claro/escuro</td>
                    <td>Permanente (local)</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p class="ajuda">
        Não usamos cookies de publicidade nem de redes sociais. As fontes Google são
        carregadas diretamente e podem registar o teu IP nos servidores da Google.
    </p>
</section>

<section class="cartao seccao">
    <h2>3. Como gerir os cookies</h2>
    <p>
        Podes apagar ou bloquear cookies nas definições do teu browser. Se bloqueares os
        cookies essenciais, o login e a marcação deixam de funcionar.
    </p>
    <p>
        Para mais informação, consulta
        <a href="https://www.cnpd.pt" target="_blank" rel="noopener">www.cnpd.pt</a>.
    </p>
</section>

<p><a class="botao" href="index.php">← Voltar ao início</a></p>

<?php require __DIR__ . '/includes/rodape.php'; ?>