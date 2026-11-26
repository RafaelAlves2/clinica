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
$tipoCheckout = (string)($_GET['tipo'] ?? 'total') === 'sinal' ? 'sinal' : 'total';
$erroPagina = '';
$avisoCartoes = '';
$stripeCustomerId = '';
$marcacao = null;
$resumo = null;
$prep = null;

try {
    $marcacao = checkout_carregar_marcacao($pdo, $ref, (int)$u['id']);
    $resumo = checkout_resumo($pdo, $marcacao);

    if ($tipoCheckout === 'sinal') {
        $pagamentoSinal = checkout_pagamento_em_curso($pdo, (int)$marcacao['id'], 'sinal');
        if (!$pagamentoSinal) {
            $st = $pdo->prepare(
                "SELECT id, estado FROM pagamentos
                  WHERE marcacao_id = ? AND metodo = 'sinal' AND gateway = 'stripe'
                  ORDER BY id DESC LIMIT 1"
            );
            $st->execute([(int)$marcacao['id']]);
            $sinalAnterior = $st->fetch();
            if ($sinalAnterior && $sinalAnterior['estado'] === 'pago') {
                redirecionar('checkout-confirmacao.php?ref=' . urlencode((string)$marcacao['referencia']));
            }
            throw new RuntimeException('Não existe um sinal pendente para esta marcação. Volta à tua área pessoal.');
        }
        $valorSinal = round((float)$pagamentoSinal['valor'], 2);
        if ($valorSinal <= 0.0001 || $valorSinal > (float)$resumo['valor'] + 0.0001) {
            throw new RuntimeException('O sinal já não corresponde ao valor em falta. Contacta a clínica.');
        }
        $resumo['valor'] = $valorSinal;
        $resumo['tipo_pagamento'] = 'sinal';
        $resumo['saldo_apos_pagamento'] = max(0, round((float)$resumo['total'] - (float)$resumo['pago'] - $valorSinal, 2));
    }

    if ($resumo['cancelada']) {
        $erroPagina = 'Esta marcação foi cancelada. Se precisares de ajuda, contacta a clínica.';
    } elseif (($resumo['valor'] ?? 0) <= 0.0001 && ($resumo['pago'] ?? 0) > 0.0001) {
        redirecionar('checkout-confirmacao.php?ref=' . urlencode((string)$marcacao['referencia']));
    } elseif (!$resumo['pode_pagar']) {
        if ($resumo['estimado']) {
            $erroPagina = 'Esta marcação inclui tratamentos com preços estimados. O pagamento online fica disponível depois da avaliação clínica.';
        } elseif (($resumo['valor'] ?? 0) <= 0.0001) {
            $erroPagina = 'Não existe valor em falta para pagar nesta marcação.';
        } else {
            $erroPagina = 'O pagamento online não está disponível para esta marcação.';
        }
    } elseif (!gateway_pagamento_disponivel($pdo) || (int)config_valor($pdo, 'pagamento_online_ativo', 0) !== 1) {
        $erroPagina = 'O pagamento online não está disponível neste momento. Tenta mais tarde ou paga na clínica.';
    } else {
        $gateway = gateway_obter($pdo);
        if (!$gateway instanceof GatewayStripe) {
            $erroPagina = 'O gateway de pagamento configurado não suporta este checkout. Contacta a clínica.';
        } else {
            $email = trim((string)$resumo['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = trim((string)($u['email'] ?? ''));
            }

            $clienteStripe = gateway_cliente_obter_ou_criar($pdo, $gateway, (int)$u['id']);
            if (empty($clienteStripe['ok'])) {
                $erroPagina = (string)($clienteStripe['erro'] ?? 'Não foi possível preparar a conta de pagamentos.');
            } else {
                $stripeCustomerId = (string)$clienteStripe['customer_id'];
                $prep = checkout_preparar_intencao(
                    $pdo,
                    $gateway,
                    $marcacao,
                    $resumo,
                    $email,
                    $tipoCheckout,
                    $stripeCustomerId
                );

                if (empty($prep['ok'])) {
                    $erroPagina = (string)($prep['erro'] ?? 'Não foi possível iniciar o pagamento.');
                } elseif (in_array(($prep['estado'] ?? ''), ['pago', 'processing'], true)) {
                    redirecionar('checkout-confirmacao.php?ref=' . urlencode((string)$marcacao['referencia'])
                        . '&payment_intent=' . urlencode((string)($prep['payment_intent_id'] ?? '')));
                } elseif (empty($prep['client_secret'])) {
                    $erroPagina = 'O Stripe não devolveu os dados necessários para concluir o pagamento. Tenta novamente.';
                } else {
                    $sessaoStripe = $gateway->criarCustomerSession($stripeCustomerId);
                    if (!empty($sessaoStripe['ok'])) {
                        $prep['customer_session_client_secret'] = (string)$sessaoStripe['client_secret'];
                    } else {
                        $avisoCartoes = 'Os cartões guardados não puderam ser carregados nesta tentativa. Podes continuar com um novo cartão.';
                        error_log('Checkout CustomerSession: ' . (string)($sessaoStripe['erro'] ?? 'erro desconhecido'));
                    }
                }
            }
        }
    }
} catch (RuntimeException $e) {
    http_response_code(404);
    $erroPagina = $e->getMessage();
} catch (Throwable $e) {
    error_log('Checkout: ' . $e->getMessage());
    http_response_code(500);
    $erroPagina = 'Ocorreu um erro ao preparar o pagamento. Tenta novamente.';
}

$publishable = gateway_stripe_publishable_key($pdo);
$modoTeste = str_starts_with($publishable, 'pk_test_');
$emailCheckout = trim((string)($resumo['email'] ?? ''));
if (!filter_var($emailCheckout, FILTER_VALIDATE_EMAIL)) {
    $emailCheckout = trim((string)($u['email'] ?? ''));
}
$returnUrl = $marcacao ? checkout_url_confirmacao($pdo, (string)$marcacao['referencia']) : '';
$customerSessionSecret = (string)($prep['customer_session_client_secret'] ?? '');
$tituloPagina = $erroPagina !== '' ? 'Checkout indisponível' : 'Finalizar pagamento';

$css_extra = $css_extra ?? [];
$css_extra[] = 'css/checkout.css';
require __DIR__ . '/includes/cabecalho.php';
?>

<section class="checkout-page" aria-labelledby="checkout-titulo">
    <header class="checkout-intro">
        <p class="checkout-kicker">Área pessoal · Pagamento seguro</p>
        <h1 id="checkout-titulo">Finalizar pagamento</h1>
        <p>Confirma os dados da marcação e escolhe como queres pagar.</p>
    </header>

    <?php if ($erroPagina !== ''): ?>
        <section class="cartao checkout-erro-pagina" role="alert">
            <div class="checkout-status-icon checkout-status-erro" aria-hidden="true">!</div>
            <h2>Não foi possível abrir o pagamento</h2>
            <p><?= e($erroPagina) ?></p>
            <div class="checkout-acoes">
                <?php if ($marcacao): ?>
                    <a class="botao" href="marcar-confirmacao.php?ref=<?= e(urlencode((string)$marcacao['referencia'])) ?>">Ver marcação</a>
                <?php endif; ?>
                <a class="botao botao-secundario" href="area-pessoal.php">Voltar à minha área</a>
            </div>
        </section>
    <?php else: ?>
        <div class="checkout-layout">
            <section class="cartao checkout-resumo" aria-labelledby="checkout-resumo-titulo">
                <div class="checkout-card-heading">
                    <div>
                        <p class="checkout-kicker">Resumo</p>
                        <h2 id="checkout-resumo-titulo">A tua marcação</h2>
                    </div>
                    <span class="checkout-secure-pill"><span aria-hidden="true">●</span> Seguro</span>
                </div>

                <div class="checkout-total">
                    <span><?= $tipoCheckout === 'sinal' ? 'Sinal a pagar agora' : 'Total a pagar' ?></span>
                    <strong><?= e(formatar_preco($resumo['valor'])) ?></strong>
                </div>

                <dl class="checkout-dados">
                    <div><dt>Referência</dt><dd><?= e((string)$marcacao['referencia']) ?></dd></div>
                    <div><dt>Tratamento</dt><dd><?= e((string)$resumo['produto']) ?></dd></div>
                    <?php if (!empty($resumo['pacientes'])): ?>
                        <div><dt>Paciente<?= count($resumo['pacientes']) > 1 ? 's' : '' ?></dt><dd><?= e(implode(', ', $resumo['pacientes'])) ?></dd></div>
                    <?php endif; ?>
                    <?php $primeira = $resumo['consultas'][0] ?? null; if ($primeira && !empty($primeira['inicio'])): $dt = new DateTime((string)$primeira['inicio']); ?>
                        <div><dt>Consulta</dt><dd><?= e($dt->format('d/m/Y · H:i')) ?></dd></div>
                    <?php endif; ?>
                    <?php if ($resumo['pago'] > 0.0001): ?>
                        <div><dt>Já pago</dt><dd><?= e(formatar_preco($resumo['pago'])) ?></dd></div>
                    <?php endif; ?>
                    <?php if ($tipoCheckout === 'sinal'): ?>
                        <div><dt>Saldo depois do sinal</dt><dd><?= e(formatar_preco($resumo['saldo_apos_pagamento'] ?? 0)) ?></dd></div>
                    <?php endif; ?>
                </dl>

                <?php if ($tipoCheckout === 'sinal'): ?>
                    <div class="alerta alerta-info checkout-nota-sinal">
                        Este pagamento corresponde apenas ao sinal. O valor restante será liquidado mais tarde na clínica.
                    </div>
                <?php endif; ?>

                <?php if ($modoTeste): ?>
                    <div class="checkout-test-note"><span aria-hidden="true">●</span> Ambiente de testes. Não uses um cartão real.</div>
                <?php endif; ?>
            </section>

            <section class="cartao checkout-pagamento" aria-labelledby="checkout-pagamento-titulo">
                <div class="checkout-card-heading">
                    <div>
                        <p class="checkout-kicker">Stripe</p>
                        <h2 id="checkout-pagamento-titulo"><?= $tipoCheckout === 'sinal' ? 'Pagar o sinal' : 'Pagamento por cartão' ?></h2>
                    </div>
                    <span class="checkout-card-brand" aria-hidden="true">VISA · MC</span>
                </div>

                <?php if ($avisoCartoes !== ''): ?>
                    <div class="alerta alerta-info" role="status"><?= e($avisoCartoes) ?></div>
                <?php endif; ?>

                <p class="checkout-cartoes-hint">
                    Os cartões que guardaste aparecem automaticamente aqui quando estás com a tua sessão iniciada.
                    <a href="cartoes.php">Gerir cartões guardados</a>.
                </p>

                <form id="payment-form"
                      data-publishable-key="<?= e($publishable) ?>"
                      data-client-secret="<?= e((string)$prep['client_secret']) ?>"
                      data-customer-session-secret="<?= e($customerSessionSecret) ?>"
                      data-return-url="<?= e($returnUrl) ?>"
                      novalidate>
                    <div class="checkout-contactos">
                        <div class="campo">
                            <label for="checkout-email">E-mail</label>
                            <input id="checkout-email" name="email" type="email" autocomplete="email" maxlength="190"
                                   value="<?= e($emailCheckout) ?>" required>
                        </div>
                        <div class="campo">
                            <label for="checkout-telefone">Telemóvel <span class="checkout-opcional">Opcional</span></label>
                            <input id="checkout-telefone" name="telefone" type="tel" autocomplete="tel" maxlength="30"
                                   value="<?= e((string)$resumo['telefone']) ?>">
                        </div>
                    </div>

                    <div class="campo checkout-titular">
                        <label for="holder">Nome do titular do cartão</label>
                        <input id="holder" name="holder" type="text" autocomplete="cc-name" maxlength="120"
                               value="<?= e((string)$resumo['paciente']) ?>" required>
                    </div>

                    <div class="campo checkout-pais">
                        <label for="country">País ou região</label>
                        <select id="country" name="pais">
                            <option value="PT" selected>Portugal</option>
                            <option value="ES">Espanha</option>
                            <option value="FR">França</option>
                            <option value="DE">Alemanha</option>
                            <option value="GB">Reino Unido</option>
                            <option value="CH">Suíça</option>
                            <option value="LU">Luxemburgo</option>
                            <option value="BR">Brasil</option>
                            <option value="AO">Angola</option>
                            <option value="MZ">Moçambique</option>
                            <option value="CV">Cabo Verde</option>
                            <option value="US">Estados Unidos</option>
                            <option value="CA">Canadá</option>
                        </select>
                    </div>

                    <div class="checkout-methods-heading">
                        <h3>Cartão ou cartão guardado</h3>
                        <p>Os dados do cartão são introduzidos diretamente na Stripe.</p>
                    </div>
                    <div id="payment-element" class="checkout-payment-element" aria-live="polite"></div>

                    <p class="checkout-consent-note">
                        Ao confirmar, autorizas a cobrança do valor indicado. A clínica não recebe nem guarda o número completo do cartão ou o CVC.
                    </p>

                    <div id="checkout-error" class="checkout-error" role="alert" aria-live="assertive"></div>

                    <button id="pay-button" class="botao checkout-pay-button" type="submit" disabled>
                        Pagar <?= e(formatar_preco($resumo['valor'])) ?>
                    </button>

                    <div class="checkout-footer">
                        <span>Processamento seguro por <strong>Stripe</strong></span>
                        <span>·</span>
                        <a href="aviso-legal.php">Aviso legal</a>
                        <a href="privacidade.php">Privacidade</a>
                    </div>
                </form>
            </section>
        </div>
    <?php endif; ?>

    <p class="checkout-voltar"><a href="area-pessoal.php">← Voltar à minha área pessoal</a></p>
</section>

<?php if ($erroPagina === ''): ?>
    <script src="https://js.stripe.com/v3/"></script>
    <script src="js/checkout.js?v=<?= (int)@filemtime(__DIR__ . '/js/checkout.js') ?>" defer></script>
<?php endif; ?>
<?php require __DIR__ . '/includes/rodape.php'; ?>
