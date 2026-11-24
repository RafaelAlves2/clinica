<?php
require_once __DIR__ . '/../core/conteudo.php';
$__pdoCab = function_exists('db') ? db() : null;
$__nomeClinica = $__pdoCab && function_exists('clinica_dado') ? clinica_dado($__pdoCab, 'clinica_nome', CLINICA_NOME) : CLINICA_NOME;
$__logoClinica = $__pdoCab && function_exists('config_valor_texto') ? trim(config_valor_texto($__pdoCab, 'clinica_logotipo_url', '')) : '';
$__faviconClinica = $__pdoCab && function_exists('config_valor_texto') ? trim(config_valor_texto($__pdoCab, 'clinica_favicon_url', '')) : '';
$__urlSegura = static function (string $u): string {
    $u = trim($u);
    if ($u === '') return '';
    if (preg_match('#^https?://#i', $u)) return $u;
    if (str_starts_with($u, '/') || preg_match('#^(uploads|img|assets|css|js)/#i', $u)) return $u;
    if (preg_match('#^[a-zA-Z0-9_./\-]+$#', $u) && !str_contains($u, '..')) return $u;
    return '';
};
$__logoClinica = $__urlSegura($__logoClinica);
$__faviconClinica = $__urlSegura($__faviconClinica);

$tituloPagina = $tituloPagina ?? $__nomeClinica;
$utilizador   = utilizador_atual();
$flash        = flash_obter();
$temaConta    = $utilizador['tema'] ?? '';
$paginaAtual  = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
?>
<!DOCTYPE html>
<html lang="pt-PT" data-tema-conta="<?= e($temaConta) ?>" data-logado="<?= $utilizador ? '1' : '0' ?>">
<head>
    <meta charset="UTF-8">
    <base href="<?= e(BASE_URL) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="description" content="<?= e(conteudo('seo_descricao', $__nomeClinica . ' - Clínica dentária. Marcação online, tratamentos e equipa médica.')) ?>">
    <meta name="author" content="<?= e($__nomeClinica) ?>">
    <title><?= e($tituloPagina) ?> | <?= e($__nomeClinica) ?></title>
    <?php if ($__faviconClinica !== ''): ?>
    <link rel="icon" href="<?= e($__faviconClinica) ?>">
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <script>document.documentElement.classList.add('js');</script>
    <script>
    (function () {
        var html = document.documentElement;
        var match = document.cookie.match(/(?:^|; )tema=([^;]*)/);
        if (match) {
            var c = decodeURIComponent(match[1]);
            if (c === 'claro' || c === 'escuro') {
                html.dataset.tema = c;
                return;
            }
        }
        try {
            var l = localStorage.getItem('tema');
            if (l === 'claro' || l === 'escuro') {
                html.dataset.tema = l;
                document.cookie = 'tema=' + l + '; path=/; max-age=31536000; SameSite=Lax';
                return;
            }
        } catch (e) {}
        var temaConta = html.dataset.temaConta;
        if (temaConta === 'claro' || temaConta === 'escuro') {
            html.dataset.tema = temaConta;
            try { localStorage.setItem('tema', temaConta); } catch (e) {}
            document.cookie = 'tema=' + temaConta + '; path=/; max-age=31536000; SameSite=Lax';
            return;
        }
        html.dataset.tema = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'escuro' : 'claro';
    })();
    </script>

    <link rel="stylesheet" href="css/estilo.css">
    <script>
    (function () {
        var m = document.cookie.match(/(?:^|; )idioma=([^;]*)/);
        if (m && decodeURIComponent(m[1]) === "en") { document.documentElement.lang = "en"; document.documentElement.dataset.idioma = "en"; }
        else { document.documentElement.lang = "pt-PT"; document.documentElement.dataset.idioma = "pt"; }
    })();
    </script>
    <script src="js/idioma.js" defer></script>
    <script src="js/tema.js" defer></script>
</head>
<body data-cursor>
<a class="saltar" href="#conteudo-principal">Saltar para o conteúdo principal</a>
<header class="topo">
    <div class="topo-conteudo">
        <a class="marca" href="index.php">
            <?php if ($__logoClinica !== ''): ?>
                <img class="marca-logo" src="<?= e($__logoClinica) ?>" alt="" loading="eager" decoding="async">
            <?php else: ?>
                <span class="marca-icone" aria-hidden="true">🦷</span>
            <?php endif; ?>
            <span class="marca-texto"><?= e($__nomeClinica) ?></span>
        </a>
        <nav aria-label="Menu principal">
            <a href="index.php"<?= $paginaAtual==='index.php'?' aria-current="page"':'' ?>><?= e(conteudo('nav_inicio', 'Início')) ?></a>
            <a href="dentistas.php"<?= $paginaAtual==='dentistas.php'?' aria-current="page"':'' ?>><?= e(conteudo('nav_equipa', 'Equipa')) ?></a>
            <a href="tratamentos.php"<?= $paginaAtual==='tratamentos.php'?' aria-current="page"':'' ?>><?= e(conteudo('nav_tratamentos', 'Tratamentos')) ?></a>

            <div class="menu-dropdown">
                <button type="button" id="botao-menu-clinica" class="menu-dropdown-botao" aria-haspopup="true" aria-expanded="false" aria-controls="menu-clinica-lista">
                    <?= e(conteudo('nav_clinica', 'Clínica')) ?> <span aria-hidden="true">▾</span>
                </button>
                <div id="menu-clinica-lista" class="menu-dropdown-lista">
                    <a href="sobre.php"<?= $paginaAtual==='sobre.php'?' aria-current="page"':'' ?>><?= e(conteudo('nav_clinica_sobre', 'A Nossa Clínica')) ?></a>
                    <a href="blog.php"<?= $paginaAtual==='blog.php'?' aria-current="page"':'' ?>><?= e(conteudo('nav_clinica_blog', 'Informação ao paciente')) ?></a>
                    <a href="seguros-publico.php"<?= $paginaAtual==='seguros-publico.php'?' aria-current="page"':'' ?>><?= e(conteudo('nav_clinica_seguros', 'Seguros e acordos')) ?></a>
                    <a href="contactos.php"<?= $paginaAtual==='contactos.php'?' aria-current="page"':'' ?>><?= e(conteudo('nav_clinica_contactos', 'Contactos')) ?></a>
                    <a href="trabalha-connosco.php"<?= $paginaAtual==='trabalha-connosco.php'?' aria-current="page"':'' ?>><?= e(conteudo('nav_clinica_candidatura', 'Trabalha connosco')) ?></a>
                </div>
            </div>

            <?php if ($utilizador && in_array($utilizador['papel'], ['admin', 'rececao'], true)): ?>
                <a href="admin/index.php">
                    <?= e(conteudo('nav_admin', 'Administração')) ?>
                    <?php
                    if (function_exists('badge_admin')) {
                        $nBadge = badge_admin(db(), $utilizador);
                        if ($nBadge > 0) {
                            echo '<span class="badge-menu">' . $nBadge . '</span>';
                        }
                    }
                    ?>
                </a>
            <?php endif; ?>
            
            <?php if ($utilizador && ($utilizador['papel'] ?? '') === 'dentista'): ?>
                <a href="admin/marcacoes.php"><?= e(conteudo('nav_agenda_dentista', 'A minha agenda')) ?></a>
            <?php endif; ?>
            <?php if ($utilizador): ?>
                <a href="area-pessoal.php"<?= in_array($paginaAtual,['area-pessoal.php','perfil.php','minhas-marcacoes.php','meus-pagamentos.php','notificacoes.php','lista-espera.php','seguros.php'],true)?' aria-current="page"':'' ?>><?= e(conteudo('nav_area_pessoal', 'A minha área')) ?></a>
            <?php endif; ?>
        </nav>
        <div class="topo-acoes">
            <button type="button" id="botao-idioma" class="botao-idioma" aria-label="Mudar idioma para inglês" title="Mudar para inglês">PT</button>
            <button type="button" id="botao-tema" class="botao-tema"
                    aria-label="Alternar entre modo claro e modo escuro" aria-pressed="false" title="Alternar tema">
                <span id="icone-tema" aria-hidden="true">🌙</span>
                <span class="so-leitor">Alternar tema</span>
            </button>
            <?php if ($utilizador): ?>
                <form action="logout.php" method="post" class="form-inline">
                    <?= csrf_campo() ?>
                    <button type="submit" class="botao-sair">Sair</button>
                </form>
            <?php else: ?>
                <a href="login.php" class="botao-sair">Entrar</a>
            <?php endif; ?>
        </div>
    </div>
</header>
<main id="conteudo-principal" class="conteudo" tabindex="-1">
<?php if ($flash): $flashRole = ($flash['tipo'] ?? '') === 'erro' ? 'alert' : 'status'; ?>
    <div class="alerta alerta-<?= e($flash['tipo']) ?>" role="<?= e($flashRole) ?>"<?= $flashRole === 'status' ? ' aria-live="polite"' : '' ?>><?= e($flash['mensagem']) ?></div>
<?php endif; ?>