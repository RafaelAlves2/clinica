<?php
declare(strict_types=1);
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/gestao.php';
require __DIR__ . '/../includes/servicos/admin.php';

$u = exigir_login(['admin', 'rececao']);
$chave = 'gestao-clinica';
$seccao = admin_seccao($chave);
if ($seccao === null || !admin_pode_ver($seccao, (string)$u['papel'])) {
    http_response_code(403);
    exit('Sem permissão para aceder a esta área.');
}

$tituloPagina = (string)$seccao['titulo'];
$css_extra = ['css/dashboard.css'];
require __DIR__ . '/../includes/cabecalho.php';
?>

<div class="dash-root adm-root">
    <?= admin_hub_html($chave, $u) ?>
</div>

<?php require __DIR__ . '/../includes/rodape.php'; ?>
