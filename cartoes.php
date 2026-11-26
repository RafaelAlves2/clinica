<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/gateway.php';
require __DIR__ . '/includes/pagamentos.php';

$u = exigir_login(['paciente']);
$pdo = db();

const CARTOES_TERMOS_VERSAO = '2026-11-23-on-session-v1';
const CARTOES_CONSENTIMENTO = 'Autorizo guardar este cartão na Stripe para o poder escolher nos próximos pagamentos quando tiver sessão iniciada. Não será feita qualquer cobrança ao adicionar o cartão e cada pagamento exige a minha confirmação.';

$erro = '';
$aviso = '';
$setup = null;
$cartoes = [];
$customerId = '';
$gateway = gateway_obter($pdo);
$gatewayDisponivel = $gateway instanceof GatewayStripe
    && gateway_stripe_configurado($pdo)
    && gateway_stripe_publishable_key($pdo) !== '';

if ($gatewayDisponivel && $gateway instanceof GatewayStripe) {
    try {
        $cliente = gateway_cliente_obter_ou_criar($pdo, $gateway, (int)$u['id']);
        if (!empty($cliente['ok'])) {
            $customerId = (string)$cliente['customer_id'];
        } else {
            $erro = (string)($cliente['erro'] ?? 'Não foi possível preparar a conta de pagamentos.');
        }
    } catch (Throwable $e) {
        error_log('Cartões: preparar Customer Stripe: ' . $e->getMessage());
        $erro = 'Não foi possível ligar à Stripe neste momento. Tenta novamente mais tarde.';
    }
} else {
    $erro = 'A gestão de cartões ainda não está disponível. A clínica tem de configurar a Stripe.';
}

if ($erro === '' && $gateway instanceof GatewayStripe && $customerId !== '' && isset($_GET['setup_intent'])) {
    $setupIntentId = trim((string)$_GET['setup_intent']);
    try {
        $resultadoSetup = $gateway->obterSetupIntent($setupIntentId);
        $intent = $resultadoSetup['setup_intent'] ?? [];
        $customerIntent = (string)($intent['customer'] ?? '');
        $usageIntent = (string)($intent['usage'] ?? '');
        $metaIntent = is_array($intent['metadata'] ?? null) ? $intent['metadata'] : [];
        $ownerIntent = (int)($metaIntent['utilizador_id'] ?? 0);
        $paymentMethodId = is_string($intent['payment_method'] ?? null)
            ? (string)$intent['payment_method']
            : (string)($intent['payment_method']['id'] ?? '');

        if (empty($resultadoSetup['ok'])
            || (string)($intent['status'] ?? '') !== 'succeeded'
            || $customerIntent !== $customerId
            || $usageIntent !== 'on_session'
            || $ownerIntent !== (int)$u['id']
            || !preg_match('/^pm_[A-Za-z0-9_]+$/', $paymentMethodId)) {
            throw new RuntimeException('A Stripe não confirmou a configuração deste cartão para esta conta.');
        }

        $permitir = $gateway->permitirReexibirCartao($paymentMethodId);
        if (empty($permitir['ok'])) {
            throw new RuntimeException((string)($permitir['erro'] ?? 'Não foi possível disponibilizar o cartão para próximos pagamentos.'));
        }

        $st = $pdo->prepare(
            "INSERT INTO stripe_metodos_consentimentos
                (utilizador_id, stripe_customer_id, stripe_payment_method_id, uso,
                 termos_versao, consentimento_texto, consentido_em, revogado_em)
             VALUES (?, ?, ?, 'on_session', ?, ?, NOW(), NULL)
             ON DUPLICATE KEY UPDATE
                utilizador_id = VALUES(utilizador_id),
                stripe_customer_id = VALUES(stripe_customer_id),
                uso = 'on_session',
                termos_versao = VALUES(termos_versao),
                consentimento_texto = VALUES(consentimento_texto),
                consentido_em = NOW(),
                revogado_em = NULL"
        );
        $st->execute([
            (int)$u['id'],
            $customerId,
            $paymentMethodId,
            CARTOES_TERMOS_VERSAO,
            CARTOES_CONSENTIMENTO,
        ]);

        flash_definir('sucesso', 'Cartão guardado com segurança na Stripe. Poderás escolhê-lo nos próximos pagamentos.');
        redirecionar('cartoes.php');
    } catch (Throwable $e) {
        error_log('Cartões: confirmar SetupIntent: ' . $e->getMessage());
        flash_definir('erro', $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Não foi possível confirmar o cartão. Tenta novamente.');
        redirecionar('cartoes.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $erro === '' && $gateway instanceof GatewayStripe && $customerId !== '') {
    csrf_validar();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'iniciar_setup') {
        if ((string)($_POST['consentimento_cartao'] ?? '') !== '1') {
            $erro = 'Tens de confirmar que autorizas guardar este cartão antes de continuar.';
        } else {
            try {
                $resultadoSetup = $gateway->criarSetupIntent($customerId, (int)$u['id']);
                if (empty($resultadoSetup['ok'])) {
                    $erro = (string)($resultadoSetup['erro'] ?? 'Não foi possível iniciar a validação do cartão.');
                } else {
                    $setup = [
                        'id' => (string)$resultadoSetup['setup_intent_id'],
                        'client_secret' => (string)$resultadoSetup['client_secret'],
                    ];
                }
            } catch (Throwable $e) {
                error_log('Cartões: criar SetupIntent: ' . $e->getMessage());
                $erro = 'Não foi possível preparar o formulário da Stripe. Tenta novamente.';
            }
        }
    } elseif ($acao === 'cancelar_setup') {
        $setupIntentId = trim((string)($_POST['setup_intent_id'] ?? ''));
        try {
            $resultadoSetup = $gateway->obterSetupIntent($setupIntentId);
            $intent = $resultadoSetup['setup_intent'] ?? [];
            $customerIntent = (string)($intent['customer'] ?? '');
            $metaIntent = is_array($intent['metadata'] ?? null) ? $intent['metadata'] : [];
            $ownerIntent = (int)($metaIntent['utilizador_id'] ?? 0);
            $statusIntent = (string)($intent['status'] ?? '');

            if (empty($resultadoSetup['ok']) || $customerIntent !== $customerId || $ownerIntent !== (int)$u['id']) {
                throw new RuntimeException('A configuração de cartão não pertence a esta conta.');
            }
            if ($statusIntent === 'succeeded') {
                throw new RuntimeException('Este cartão já foi validado. Atualiza a página para ver a lista.');
            }
            if ($statusIntent === 'canceled') {
                flash_definir('sucesso', 'A configuração do cartão já estava cancelada.');
                redirecionar('cartoes.php');
            }
            if ($statusIntent === 'processing') {
                throw new RuntimeException('A Stripe ainda está a processar a validação deste cartão. Atualiza a página dentro de instantes.');
            }

            $cancelado = $gateway->cancelarSetupIntent($setupIntentId);
            if (empty($cancelado['ok'])) {
                throw new RuntimeException((string)($cancelado['erro'] ?? 'Não foi possível cancelar a configuração do cartão.'));
            }
            flash_definir('sucesso', 'A configuração do cartão foi cancelada. Não foi feita qualquer cobrança.');
            redirecionar('cartoes.php');
        } catch (Throwable $e) {
            error_log('Cartões: cancelar SetupIntent: ' . $e->getMessage());
            $erro = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Não foi possível cancelar a configuração do cartão.';
        }
    } elseif ($acao === 'remover') {
        $paymentMethodId = trim((string)($_POST['payment_method_id'] ?? ''));
        try {
            $st = $pdo->prepare(
                'SELECT id FROM stripe_metodos_consentimentos
                  WHERE utilizador_id = ? AND stripe_customer_id = ?
                    AND stripe_payment_method_id = ? AND revogado_em IS NULL
                  LIMIT 1'
            );
            $st->execute([(int)$u['id'], $customerId, $paymentMethodId]);
            if (!$st->fetchColumn()) {
                throw new RuntimeException('Este cartão não está associado à tua conta.');
            }

            $removido = $gateway->removerCartao($customerId, $paymentMethodId);
            if (empty($removido['ok'])) {
                throw new RuntimeException((string)($removido['erro'] ?? 'Não foi possível remover o cartão.'));
            }
            $pdo->prepare(
                'UPDATE stripe_metodos_consentimentos
                    SET revogado_em = NOW()
                  WHERE utilizador_id = ? AND stripe_customer_id = ?
                    AND stripe_payment_method_id = ? AND revogado_em IS NULL'
            )->execute([(int)$u['id'], $customerId, $paymentMethodId]);

            flash_definir('sucesso', 'Cartão removido. Já não aparecerá nos próximos pagamentos.');
            redirecionar('cartoes.php');
        } catch (Throwable $e) {
            error_log('Cartões: remover PaymentMethod: ' . $e->getMessage());
            $erro = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Não foi possível remover o cartão. Tenta novamente.';
        }
    }
}

if ($gateway instanceof GatewayStripe && $customerId !== '') {
    try {
        $resultadoLista = $gateway->listarCartoes($customerId);
        if (empty($resultadoLista['ok'])) {
            $aviso = (string)($resultadoLista['erro'] ?? 'Não foi possível atualizar a lista de cartões.');
        } else {
            $st = $pdo->prepare(
                'SELECT stripe_payment_method_id
                   FROM stripe_metodos_consentimentos
                  WHERE utilizador_id = ? AND stripe_customer_id = ? AND revogado_em IS NULL'
            );
            $st->execute([(int)$u['id'], $customerId]);
            $consentidos = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);

            foreach ($resultadoLista['cartoes'] as $cartao) {
                $paymentMethodId = (string)($cartao['id'] ?? '');
                if ($paymentMethodId === '' || !isset($consentidos[$paymentMethodId])) {
                    continue;
                }
                $detalhes = is_array($cartao['card'] ?? null) ? $cartao['card'] : [];
                $marca = mb_strtolower((string)($detalhes['brand'] ?? 'cartão'));
                $marcas = [
                    'visa' => 'Visa',
                    'mastercard' => 'Mastercard',
                    'amex' => 'American Express',
                    'discover' => 'Discover',
                    'diners' => 'Diners Club',
                    'jcb' => 'JCB',
                    'unionpay' => 'UnionPay',
                ];
                $cartoes[] = [
                    'id' => $paymentMethodId,
                    'marca' => $marcas[$marca] ?? ucfirst($marca),
                    'ultimos4' => preg_replace('/\D+/', '', (string)($detalhes['last4'] ?? '')),
                    'validade' => sprintf('%02d/%04d', (int)($detalhes['exp_month'] ?? 0), (int)($detalhes['exp_year'] ?? 0)),
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('Cartões: listar cartões: ' . $e->getMessage());
        $aviso = 'Não foi possível atualizar a lista de cartões.';
    }
}

$publishable = gateway_stripe_publishable_key($pdo);
$returnUrl = rtrim(gateway_base_url($pdo), '/') . '/cartoes.php';
$tituloPagina = 'Os meus cartões';
$css_extra = $css_extra ?? [];
$css_extra[] = 'css/cartoes.css';
if ($setup !== null) { $css_extra[] = 'css/checkout.css'; }
require __DIR__ . '/includes/cabecalho.php';
?>

<section class="cartoes-page" aria-labelledby="cartoes-titulo">
    <header class="cartoes-intro">
        <p class="checkout-kicker">A minha área · Pagamentos</p>
        <h1 id="cartoes-titulo">Os meus cartões</h1>
        <p>Guarda um cartão opcionalmente para o poderes escolher nos próximos pagamentos, sem voltares a introduzir todos os dados.</p>
    </header>

    <?php if ($erro !== ''): ?>
        <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
    <?php endif; ?>
    <?php if ($aviso !== ''): ?>
        <div class="alerta alerta-info" role="status"><?= e($aviso) ?></div>
    <?php endif; ?>

    <section class="cartao cartoes-lista" aria-labelledby="cartoes-guardados-titulo">
        <div class="cartoes-cabecalho">
            <div>
                <p class="checkout-kicker">Conta Stripe</p>
                <h2 id="cartoes-guardados-titulo">Cartões guardados</h2>
            </div>
            <span class="cartoes-contagem"><?= count($cartoes) ?> <?= count($cartoes) === 1 ? 'cartão' : 'cartões' ?></span>
        </div>

        <?php if ($cartoes === []): ?>
            <div class="cartoes-vazio">
                <span class="cartoes-vazio-icone" aria-hidden="true">▱</span>
                <h3>Ainda não guardaste cartões</h3>
                <p>É opcional. Quando guardares um cartão, ele poderá aparecer automaticamente no checkout.</p>
            </div>
        <?php else: ?>
            <div class="cartoes-grelha">
                <?php foreach ($cartoes as $cartao): ?>
                    <article class="cartao-item">
                        <div class="cartao-item-topo">
                            <span class="cartao-item-marca"><?= e($cartao['marca']) ?></span>
                            <span class="cartao-item-simbolo" aria-hidden="true">▰</span>
                        </div>
                        <strong class="cartao-item-numero">•••• <?= e($cartao['ultimos4']) ?></strong>
                        <span class="cartao-item-validade">Validade <?= e($cartao['validade']) ?></span>
                        <form method="post" action="cartoes.php" onsubmit="return confirm('Queres mesmo remover este cartão?');">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="acao" value="remover">
                            <input type="hidden" name="payment_method_id" value="<?= e($cartao['id']) ?>">
                            <button type="submit" class="botao botao-secundario cartao-item-remover">Remover cartão</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($setup === null): ?>
        <section class="cartao cartoes-adicionar" aria-labelledby="cartoes-adicionar-titulo">
            <div class="cartoes-cabecalho">
                <div>
                    <p class="checkout-kicker">Opcional</p>
                    <h2 id="cartoes-adicionar-titulo">Adicionar um cartão</h2>
                </div>
                <span class="cartoes-escudo" aria-hidden="true">✓</span>
            </div>
            <p>O cartão é guardado pela Stripe, não pela clínica. Nunca guardamos o número completo, o CVC ou a validade do cartão na base de dados da clínica.</p>

            <form method="post" action="cartoes.php" class="cartoes-consentimento">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="iniciar_setup">
                <label class="cartoes-consentimento-label">
                    <input type="checkbox" name="consentimento_cartao" value="1" required>
                    <span><?= e(CARTOES_CONSENTIMENTO) ?></span>
                </label>
                <button type="submit" class="botao">Continuar e adicionar cartão</button>
            </form>
            <p class="ajuda cartoes-nota">Não é feita qualquer cobrança ao adicionar o cartão. Cada pagamento futuro continua a exigir a tua confirmação.</p>
        </section>
    <?php else: ?>
        <section class="cartao cartoes-adicionar" aria-labelledby="cartoes-validar-titulo">
            <p class="checkout-kicker">Validação segura</p>
            <h2 id="cartoes-validar-titulo">Introduz os dados do cartão</h2>
            <p>Confirma o cartão na Stripe. Só depois de a Stripe validar o cartão será associado à tua conta.</p>

            <form id="setup-card-form"
                  data-publishable-key="<?= e($publishable) ?>"
                  data-client-secret="<?= e((string)$setup['client_secret']) ?>"
                  data-return-url="<?= e($returnUrl) ?>"
                  novalidate>
                <div id="setup-payment-element" class="checkout-payment-element" aria-live="polite"></div>
                <div id="setup-card-error" class="checkout-error" role="alert" aria-live="assertive"></div>
                <button id="setup-card-button" class="botao cartoes-guardar-botao" type="submit" disabled>Confirmar e guardar cartão</button>
            </form>
            <form method="post" action="cartoes.php" class="cartoes-cancelar-setup">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="cancelar_setup">
                <input type="hidden" name="setup_intent_id" value="<?= e($setup['id']) ?>">
                <button type="submit" class="botao botao-secundario">Cancelar e voltar</button>
            </form>
            <p class="ajuda cartoes-nota">Uso autorizado: pagamentos com a tua presença e confirmação. Não permitiste cobranças automáticas.</p>
        </section>
    <?php endif; ?>
</section>

<?php if ($setup !== null): ?>
    <script src="https://js.stripe.com/v3/"></script>
    <script src="js/cartoes.js?v=<?= (int)@filemtime(__DIR__ . '/js/cartoes.js') ?>" defer></script>
<?php endif; ?>
<?php require __DIR__ . '/includes/rodape.php'; ?>
