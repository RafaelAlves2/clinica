<?php
declare(strict_types=1);

function hero_imagens(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, caminho, legenda FROM hero_imagens WHERE visivel = 1 ORDER BY ordem, id'
    )->fetchAll();
}

/**
 * Renderiza o hero editorial.
 *
 * Novos parâmetros opcionais $telefone e $morada — se vazios, usam as
 * constantes CLINICA_TELEFONE e CLINICA_MORADA de includes/core/config.php.
 * Assim, index.php continua a funcionar sem alterações.
 */
function hero_render(
    array $imagens,
    string $h1,
    string $p,
    string $botaoTexto,
    string $botaoUrl,
    string $telefone = '',
    string $morada = ''
): string {
    // Segurança: validar cada caminho antes de o usar em background-image
    $imagens = array_values(array_filter($imagens, static function ($img) {
        $c = (string)($img['caminho'] ?? '');
        if ($c === '' || str_contains($c, '..')) { return false; }
        return (bool)preg_match('~^[A-Za-z0-9_:/.\-?=&%]+$~', $c);
    }));

    $temImagens = $imagens !== [];

    // Telefone: fallback para a constante da clínica
    $telefone = trim($telefone);
    if ($telefone === '' && defined('CLINICA_TELEFONE')) {
        $telefone = CLINICA_TELEFONE;
    }
    $digits = preg_replace('/\D/', '', $telefone);
    $telHref = '';
    if ($digits !== '') {
        $telHref = strlen($digits) === 9 ? 'tel:+351' . $digits : 'tel:' . $digits;
    }

    // Morada: fallback para a constante da clínica
    $morada = trim($morada);
    if ($morada === '' && defined('CLINICA_MORADA')) {
        $morada = CLINICA_MORADA;
    }

    // Kicker superior
    $kicker = defined('CLINICA_NOME') ? CLINICA_NOME : '';

    // ---- CSS inline (uma única vez por pedido) ----
    static $cssEmitido = false;
    $cssHtml = '';
    if (!$cssEmitido) {
        $cssEmitido = true;
        $cssHtml = '<style>' . <<<'CSS'
/* =========================================================
   Hero editorial — auto-contido.
   Pode ser extraído para css/hero.css numa fase seguinte
   e carregado via $css_extra no cabecalho.php.
   ========================================================= */

.hero-editorial {
    box-shadow: 10px 10px 0 var(--acento);
    border: 1px solid rgba(255,255,255,.12);
    padding: 0;
    min-height: 520px;
    overflow: hidden;
    position: relative;
}

.hero-editorial .hero-content {
    padding: var(--esp-7) var(--esp-6);
    max-width: 56rem;
    position: relative;
    z-index: 1;
}

.hero-editorial .hero-kicker {
    display: inline-flex;
    align-items: center;
    gap: var(--esp-2);
    font-size: 0.78rem;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #fff;
    text-shadow: 0 1px 8px rgba(0,0,0,.4);
    margin-bottom: var(--esp-3);
}
.hero-editorial .hero-kicker::before {
    content: '';
    display: inline-block;
    width: 28px;
    height: 1px;
    background: currentColor;
    opacity: 0.6;
}

.hero-editorial h1 {
    font-size: clamp(2.4rem, 6vw, 4.2rem);
    line-height: 1.05;
    letter-spacing: -0.02em;
    font-weight: 600;
    color: #fff !important;
    text-shadow: 0 2px 16px rgba(0,0,0,.5);
    margin: 0 0 var(--esp-4);
    text-wrap: balance;
}

.hero-editorial p {
    font-size: clamp(1rem, 1.4vw, 1.15rem);
    line-height: 1.6;
    color: rgba(255,255,255,.92) !important;
    text-shadow: 0 1px 8px rgba(0,0,0,.5);
    max-width: 34rem;
    margin: 0 auto var(--esp-5);
}

.hero-acoes {
    display: flex;
    flex-wrap: wrap;
    gap: var(--esp-3);
    justify-content: center;
    align-items: center;
}

.hero-editorial .hero-acoes .botao-hero {
    background: var(--primaria) !important;
    color: var(--primaria-texto) !important;
    border-color: var(--primaria) !important;
    font-weight: 600;
    padding: 0.9rem 2rem;
    box-shadow: 0 12px 32px rgba(0,0,0,.3);
    will-change: transform;
    transition: background .15s ease, border-color .15s ease, transform .25s cubic-bezier(.22,.61,.36,1);
}
.hero-editorial .hero-acoes .botao-hero:hover,
.hero-editorial .hero-acoes .botao-hero:focus-visible {
    background: var(--primaria-hover) !important;
    border-color: var(--primaria-hover) !important;
}

.hero-editorial .hero-acoes .botao-telefone {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: rgba(255,255,255,.14) !important;
    color: #fff !important;
    border: 1px solid rgba(255,255,255,.4) !important;
    font-weight: 500;
    padding: 0.9rem 1.5rem;
    border-radius: var(--raio-sm);
    text-decoration: none;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    transition: background .15s ease, border-color .15s ease;
    text-shadow: 0 1px 4px rgba(0,0,0,.4);
}
.hero-editorial .hero-acoes .botao-telefone:hover,
.hero-editorial .hero-acoes .botao-telefone:focus-visible {
    background: rgba(255,255,255,.25) !important;
    color: #fff !important;
    border-color: #fff !important;
    text-decoration: none;
}

.hero-editorial .hero-overlay {
    background: linear-gradient(
        to bottom,
        rgba(16,28,34,.35) 0%,
        rgba(16,28,34,.55) 50%,
        rgba(16,28,34,.72) 100%
    );
}
:root[data-tema="escuro"] .hero-editorial .hero-overlay {
    background: linear-gradient(
        to bottom,
        rgba(0,0,0,.55) 0%,
        rgba(0,0,0,.72) 100%
    );
}

.hero-editorial .hero-corner {
    position: absolute;
    z-index: 2;
    font-size: 0.7rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: rgba(255,255,255,.85);
    text-shadow: 0 1px 6px rgba(0,0,0,.6);
    pointer-events: none;
}
.hero-editorial .hero-corner-tl {
    top: var(--esp-3);
    left: var(--esp-4);
    background: rgba(0,0,0,.35);
    padding: 4px 10px;
    border-radius: var(--raio-pill);
}
.hero-editorial .hero-corner-br {
    bottom: var(--esp-3);
    right: var(--esp-4);
    opacity: .65;
}

@media (max-width: 720px) {
    .hero-editorial {
        box-shadow: 6px 6px 0 var(--acento);
        min-height: 440px;
    }
    .hero-editorial .hero-content {
        padding: var(--esp-6) var(--esp-4);
    }
    .hero-acoes {
        flex-direction: column;
        align-items: stretch;
    }
    .hero-editorial .hero-acoes .botao-hero,
    .hero-editorial .hero-acoes .botao-telefone {
        width: 100%;
        justify-content: center;
    }
    .hero-editorial .hero-corner-br { display: none; }
}

@media (prefers-reduced-motion: reduce) {
    .hero-editorial .hero-acoes .botao-hero {
        will-change: auto;
        transition: background .15s ease, border-color .15s ease;
    }
}
CSS . '</style>';
    }

    // ---- HTML ----
    $html  = '<section class="hero' . ($temImagens ? ' hero-com-slides' : '') . ' hero-editorial"';
    if ($temImagens) {
        $html .= ' data-slides="' . count($imagens) . '"';
    }
    $html .= '>';

    if ($temImagens) {
        $html .= '<div class="hero-next-preview" aria-hidden="true"></div>';
        $html .= '<div class="hero-slides" aria-hidden="true">';
        foreach ($imagens as $i => $img) {
            $html .= '<div class="hero-slide' . ($i === 0 ? ' ativo' : '') . '"'
                   . ' style="background-image:url(' . e($img['caminho']) . ')"'
                   . ($img['legenda'] ? ' data-legenda="' . e($img['legenda']) . '"' : '')
                   . '></div>';
        }
        $html .= '</div>';
        $html .= '<div class="hero-overlay"></div>';
    }

    if ($kicker !== '') {
        $html .= '<span class="hero-corner hero-corner-tl">' . e($kicker) . '</span>';
    }
    $html .= '<span class="hero-corner hero-corner-br" aria-hidden="true">&darr; continua</span>';

    $html .= '<div class="hero-content">';
    if ($kicker !== '') {
        $html .= '<span class="hero-kicker">' . e($kicker) . '</span>';
    }
    $html .= '<h1>' . e($h1) . '</h1>';
    $html .= '<p>' . e($p) . '</p>';
    $html .= '<div class="hero-acoes">';
    $html .= '<a class="botao botao-hero" href="' . e($botaoUrl) . '" data-magnetic>' . e($botaoTexto) . '</a>';
    if ($telHref !== '') {
        $html .= '<a class="botao botao-telefone" href="' . e($telHref) . '">'
               . '<span aria-hidden="true">&#9742;</span> ' . e($telefone)
               . '</a>';
    }
    $html .= '</div>';
    $html .= '</div>';

    $html .= '</section>';

    if ($temImagens && count($imagens) > 1) {
        $html .= '<script>(function(){try{'
               . 'var h=document.currentScript&&document.currentScript.previousElementSibling;'
               . 'if(!h||!h.classList||!h.classList.contains("hero-com-slides"))h=document.querySelector(".hero-com-slides");'
               . 'if(!h||h.dataset.slideshowInit==="1")return;h.dataset.slideshowInit="1";'
               . 'var slides=h.querySelectorAll(".hero-slide");if(slides.length<=1)return;var i=0,t=null;'
               . 'var previewLayer=h.querySelector(".hero-next-preview");function preview(){var n=(i+1)%slides.length;var bg=slides[n].style.backgroundImage||"none";if(previewLayer)previewLayer.style.backgroundImage=bg;h.style.setProperty("--hero-next-image",bg);}'
               . 'function go(n){slides[i].classList.remove("ativo");i=(n+slides.length)%slides.length;slides[i].classList.add("ativo");preview();}'
               . 'function start(){if(t!==null)return;t=setInterval(function(){go(i+1);},5000);}function stop(){if(t!==null){clearInterval(t);t=null;}}'
               . 'preview();if(window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches)return;'
               . 'document.addEventListener("visibilitychange",function(){document.hidden?stop():start();});start();}catch(e){}})();</script>';
    }

    return $cssHtml . $html;
}
