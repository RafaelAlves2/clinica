<?php
/*
 * includes/cabecalho.php — wrapper de cabeçalho
 *
 * Responsabilidades:
 *   1. Construir $css_extra (CSS condicional por página).
 *   2. Delegar TODO o HTML para includes/ui/cabecalho.php.
 *
 * Páginas podem adicionar o seu próprio CSS ANTES do require:
 *
 *   $css_extra = $css_extra ?? [];
 *   $css_extra[] = 'css/alguma-coisa.css';
 *   $tituloPagina = 'Título';
 *   require __DIR__ . '/includes/cabecalho.php';
 *
 * O ui/cabecalho.php imprime todos os $css_extra dentro do <head>.
 */

$css_extra = $css_extra ?? [];
if (!is_array($css_extra)) {
    $css_extra = [];
}

// CSS condicional por página (mantém a lista que já tinhas).
$paginas_dashboard = [
    'area-pessoal.php',
    'minhas-marcacoes.php',
    'meus-pagamentos.php',
    'notificacoes.php',
    'perfil.php',
    'marcar-resumo.php',
    'seguros.php',
    'alterar-consulta.php',
    'avaliar.php',
];
$pagina_atual = basename($_SERVER['PHP_SELF'] ?? '');
if (in_array($pagina_atual, $paginas_dashboard, true)) {
    $css_extra[] = 'css/dashboard.css';
}

// A consola de administração tem uma folha própria, carregada em todas as
// páginas dentro de /admin/ (a subnavegação de contexto também é injetada
// pelo cabeçalho partilhado).
$script_atual = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
if (str_contains($script_atual, '/admin/') && !in_array('css/admin.css', $css_extra, true)) {
    $css_extra[] = 'css/admin.css';
}

require_once __DIR__ . '/ui/cabecalho.php';