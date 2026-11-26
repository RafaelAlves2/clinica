<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/gateway.php';
require __DIR__ . '/includes/pagamentos.php';
require __DIR__ . '/includes/checkout.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');

$u = exigir_login();
$pdo = db();

$ref = trim((string)($_GET['ref'] ?? ''));
$piId = trim((string)($_GET['payment_intent'] ?? ''));
$erroPagina = '';
$marcacao = null;
$resumo = null;
$pagamento = null;
$cartao = ['marca' => '', 'ultimos4' => ''];
$estado = '';
$vista = 'pendente';
$pagamentoParcial = false;

try {
    $marcacao = checkout_carregar_marcacao($pdo, $ref, (int)$u['id']);
    $resumo = checkout_resumo($pdo, $marcacao);
    $pagamento = checkout_pagamento_confirmacao($pdo, (int)$marcacao['id'], $piId);

    if ($pagamento) {
        $estado = (string)$pagamento['estado'];
        $gateway = gateway_obter($pdo);
        if ($gateway instanceof GatewayStripe && $estado === 'pago') {
            try {
                $cartao = checkout_dados_cartao($pdo, $gateway, $pagamento);
            } catch (Throwable $e) {
                error_log('Checkout confirmação cartão: ' . $e->getMessage());
            }
        }
    }

    $pagamentoParcial = $estado === 'pago'
        && $pagamento !== null
        && (string)($pagamento['metodo'] ?? '') === 'sinal'
        && (float)$resumo['valor'] > 0.0001;

    if ($estado === 'pago' || ($resumo['pago'] > 0.0001 && $resumo['valor'] <= 0.0001)) {
        $vista = 'pago';
    } elseif (in_array($estado, ['falhado', 'recusado', 'cancelado'], true)) {
        $vista = 'falhado';
    } elseif (in_array($estado, ['reembolsado', 'parcialmente_reembolsado'], true)) {
        $vista = 'reembolsado';
    } elseif (in_array($estado, ['pendente', 'em_processamento', 'parcialmente_pago'], true)) {
        $vista = 'pendente';
    } elseif ($pagamento === null) {
        $vista = 'pendente';
    }
} catch (RuntimeException $e) {
    http_response_code(404);
    $erroPagina = $e->getMessage();
} catch (Throwable $e) {
    error_log('Checkout confirmação: ' . $e->getMessage());
    http_response_code(500);
    $erroPagina = 'Ocorreu um erro ao consultar o pagamento. Tenta novamente.';
}

$modoTeste = str_starts_with(gateway_stripe_publishable_key($pdo), 'pk_test_');
$tituloPagina = match ($vista) {
    'pago' => $pagamentoParcial ? 'Sinal confirmado' : 'Pagamento confirmado',
    'falhado' => 'Pagamento não concluído',
    'reembolsado' => 'Pagamento reembolsado',
    default => 'A confirmar pagamento',
};
if ($erroPagina !== '') { $tituloPagina = 'Confirmação indisponível'; }

$email = trim((string)($resumo['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $email = trim((string)($u['email'] ?? ''));
}
$valorMostrado = $pagamento ? (float)$pagamento['valor'] : (float)($resumo['valor'] ?? 0);
$metodoCartao = pagamento_cartao_legivel($cartao['marca'] ?? '', $cartao['ultimos4'] ?? '');
$marcaCurta = strtoupper((string)($cartao['marca'] ?? ''));
$marcaBadge = match ($marcaCurta) {
    'VISA' => 'VISA',
    'MASTERCARD' => 'MC',
    'AMEX', 'AMERICAN EXPRESS' => 'AMEX',
    'DISCOVER' => 'DISC',
    'JCB' => 'JCB',
    'UNIONPAY' => 'UP',
    default => 'CARD',
};
$pagamentoReferencia = $pagamento ? trim((string)($pagamento['referencia_transacao'] ?? '')) : '';
$paymentIntentId = $pagamentoReferencia !== '' && str_starts_with($pagamentoReferencia, 'pi_') ? $pagamentoReferencia : $piId;
$podeRepetir = in_array($vista, ['falhado', 'reembolsado'], true) && !empty($resumo['pode_pagar']);
$tipoRetry = $vista !== 'reembolsado' && $pagamento && (string)($pagamento['metodo'] ?? '') === 'sinal' ? '&tipo=sinal' : '';
$valorReembolsado = (float)($pagamento['valor_reembolsado'] ?? 0);

$css_extra = $css_extra ?? [];
$css_extra[] = 'css/checkout.css';
require __DIR__ . '/includes/cabecalho.php';
?>

<section class="checkout-page confirmacao-checkout" aria-labelledby="confirmacao-titulo">
    <header class="checkout-intro">
        <p class="checkout-kicker">Área pessoal · Pagamentos</p>
        <h1 id="confirmacao-titulo"><?= e($tituloPagina) ?></h1>
        <p>Consulta o estado atualizado do pagamento da tua marcação.</p>
    </header>

    <?php if ($erroPagina !== ''): ?>
        <section class="cartao checkout-erro-pagina" role="alert">
            <div class="checkout-status-icon" aria-hidden="true">!</div>
            <h2>Não foi possível mostrar a confirmação</h2>
            <p><?= e($erroPagina) ?></p>
            <a class="botao" href="area-pessoal.php">Voltar à minha área</a>
        </section>
    <?php else: ?>
        <div class="checkout-layout">
            <section class="cartao confirmacao-resumo" aria-labelledby="confirmacao-resumo-titulo">
                <p class="checkout-kicker">Resumo</p>
                <h2 id="confirmacao-resumo-titulo"><?= e((string)$resumo['produto']) ?></h2>
                <div class="checkout-total">
                    <span><?= $pagamentoParcial ? 'Sinal pago' : ($vista === 'reembolsado' ? 'Valor reembolsado' : 'Valor do movimento') ?></span>
                    <strong><?= e(formatar_preco($vista === 'reembolsado' ? $valorReembolsado : $valorMostrado)) ?></strong>
                </div>
                <dl class="checkout-dados">
                    <div><dt>Referência</dt><dd><?= e((string)$marcacao['referencia']) ?></dd></div>
                    <div><dt>Contacto</dt><dd><?= e($email !== '' ? $email : '—') ?></dd></div>
                    <?php if (!empty($resumo['pacientes'])): ?>
                        <div><dt>Paciente<?= count($resumo['pacientes']) > 1 ? 's' : '' ?></dt><dd><?= e(implode(', ', $resumo['pacientes'])) ?></dd></div>
                    <?php endif; ?>
                    <?php $primeira = $resumo['consultas'][0] ?? null; if ($primeira && !empty($primeira['inicio'])): $dt = new DateTime((string)$primeira['inicio']); ?>
                        <div><dt>Consulta</dt><dd><?= e($dt->format('d/m/Y · H:i')) ?></dd></div>
                    <?php endif; ?>
                    <?php if ($pagamentoReferencia !== ''): ?>
                        <div><dt>Referência Stripe</dt><dd><?= e($pagamentoReferencia) ?></dd></div>
                    <?php endif; ?>
                </dl>
                <?php if ($modoTeste): ?>
                    <div class="checkout-test-note"><span aria-hidden="true">●</span> Ambiente de testes. Não uses um cartão real.</div>
                <?php endif; ?>
            </section>

            <section class="cartao confirmacao-estado" aria-label="Estado do pagamento">
                <?php if ($vista === 'pago'): ?>
                    <div class="confirmacao-icone" aria-hidden="true">✓</div>
                    <h2><?= $pagamentoParcial ? 'Sinal confirmado' : 'Pagamento confirmado' ?></h2>
                <?php elseif ($vista === 'falhado'): ?>
                    <div class="confirmacao-icone falhado" aria-hidden="true">×</div>
                    <h2>O pagamento não foi concluído</h2>
                <?php elseif ($vista === 'reembolsado'): ?>
                    <div class="confirmacao-icone falhado" aria-hidden="true">↶</div>
                    <h2><?= $estado === 'reembolsado' ? 'Pagamento reembolsado' : 'Reembolso parcial registado' ?></h2>
                <?php else: ?>
                    <div class="confirmacao-icone pendente" aria-hidden="true">…</div>
                    <h2>A confirmar o pagamento</h2>
                <?php endif; ?>

                <div class="confirmacao-detalhe">
                    <div><span>Método</span><strong><?= e($pagamento ? pagamento_metodo_legivel((string)$pagamento['metodo']) : 'Cartão') ?></strong></div>
                    <?php if ($vista === 'pago' && ($cartao['marca'] ?? '') !== ''): ?>
                        <div><span>Cartão</span><strong><?= e($metodoCartao) ?></strong></div>
                    <?php endif; ?>
                    <div><span>Estado</span><strong><?= e($pagamento ? pagamento_estado_legivel($estado) : 'Pendente') ?></strong></div>
                    <?php if ($vista === 'reembolsado'): ?>
                        <div><span>Reembolsado</span><strong><?= e(formatar_preco($valorReembolsado)) ?></strong></div>
                    <?php endif; ?>
                </div>

                <?php if ($vista === 'pago'): ?>
                    <p class="confirmacao-nota">
                        <?php if ($pagamentoParcial): ?>
                            O sinal de <?= e(formatar_preco($valorMostrado)) ?> foi confirmado. Falta pagar <?= e(formatar_preco($resumo['valor'])) ?>, que podes liquidar na clínica.
                        <?php else: ?>
                            O pagamento foi confirmado pela Stripe. Podes consultar os detalhes na tua marcação.
                        <?php endif; ?>
                    </p>
                    <div class="confirmacao-acoes">
                        <a class="botao" href="marcar-confirmacao.php?ref=<?= e(urlencode((string)$marcacao['referencia'])) ?>">Ver marcação</a>
                        <a class="botao botao-secundario" href="area-pessoal.php">A minha área</a>
                    </div>
                <?php elseif ($vista === 'falhado'): ?>
                    <p class="confirmacao-nota">Não foi concluída qualquer cobrança.<?= $podeRepetir ? ' Podes tentar novamente com outro cartão.' : '' ?></p>
                    <div class="confirmacao-acoes">
                        <?php if ($podeRepetir): ?>
                            <a class="botao" href="checkout.php?ref=<?= e(urlencode((string)$marcacao['referencia'])) ?><?= e($tipoRetry) ?>">Tentar novamente</a>
                        <?php endif; ?>
                        <a class="botao botao-secundario" href="marcar-confirmacao.php?ref=<?= e(urlencode((string)$marcacao['referencia'])) ?>">Ver marcação</a>
                    </div>
                <?php elseif ($vista === 'reembolsado'): ?>
                    <p class="confirmacao-nota">
                        <?php if ($estado === 'reembolsado'): ?>
                            A Stripe confirmou a devolução de <?= e(formatar_preco($valorReembolsado)) ?>. O valor voltou a ficar em dívida na marcação.
                        <?php else: ?>
                            Foi confirmado um reembolso parcial de <?= e(formatar_preco($valorReembolsado)) ?>. O saldo em falta é <?= e(formatar_preco($resumo['valor'])) ?>.
                        <?php endif; ?>
                    </p>
                    <div class="confirmacao-acoes">
                        <?php if ($podeRepetir): ?>
                            <a class="botao" href="checkout.php?ref=<?= e(urlencode((string)$marcacao['referencia'])) ?>">Pagar valor em falta</a>
                        <?php endif; ?>
                        <a class="botao botao-secundario" href="marcar-confirmacao.php?ref=<?= e(urlencode((string)$marcacao['referencia'])) ?>">Ver marcação</a>
                    </div>
                <?php else: ?>
                    <p class="confirmacao-nota" id="estado-nota">
                        Estamos a aguardar a confirmação segura da Stripe. Esta página atualiza automaticamente.
                    </p>
                    <p class="ajuda" id="estado-texto">A aguardar confirmação da Stripe…</p>
                    <noscript><p class="ajuda">Atualiza esta página dentro de alguns instantes para ver o estado final.</p></noscript>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
</section>

<?php if ($erroPagina === '' && $vista === 'pendente'): ?>
    <script
        src="<?= e(BASE_URL) ?>js/checkout-confirmacao.js?v=<?= (int)@filemtime(__DIR__ . '/js/checkout-confirmacao.js') ?>"
        data-estado-url="<?= e(BASE_URL) ?>checkout-estado.php"
        data-ref="<?= e((string)$marcacao['referencia']) ?>"
        data-payment-intent="<?= e($paymentIntentId) ?>"></script>
<?php endif; ?>
<?php require __DIR__ . '/includes/rodape.php'; ?>
