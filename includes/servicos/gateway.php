<?php
declare(strict_types=1);

interface GatewayPagamento {
    public function nome(): string;
    public function iniciarPagamento(array $ctx): array;
    public function validarWebhook(array $request, string $rawBody): array;
}

final class GatewayNaoConfigurado implements GatewayPagamento {
    public function nome(): string { return 'nao_configurado'; }
    public function iniciarPagamento(array $ctx): array {
        return ['ok' => false, 'erro' => 'Gateway de pagamento online não configurado. Configure credenciais/contrato com o fornecedor (ex.: Eupago) e defina gateway_pagamentos + segredo de webhook nas configurações de administração. Pagamento presencial permanece disponível.'];
    }
    public function validarWebhook(array $request, string $rawBody): array {
        return ['valido' => false, 'evento' => [], 'erro' => 'Gateway não configurado.'];
    }
}

final class GatewayEupagoScaffold implements GatewayPagamento {
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    public function nome(): string { return 'eupago'; }
    public function iniciarPagamento(array $ctx): array {
        $apiKey = trim((string)config_valor_texto($this->pdo, 'eupago_api_key', ''));
        $ambiente = trim((string)config_valor_texto($this->pdo, 'gateway_ambiente', 'teste'));
        if ($apiKey === '') {
            return ['ok' => false, 'erro' => 'Eupago selecionado mas sem credenciais configuradas (eupago_api_key). Em ambiente de testes, configure apenas credenciais de sandbox fornecidas pelo Eupago; nunca use credenciais de produção em código.'];
        }
        return ['ok' => false, 'erro' => 'Integração API Eupago pendente de implementação com credenciais/contrato de teste. O sistema está preparado (abstração, webhook idempotente, estados), mas não inicia cobranças reais sem implementação/validação final do fornecedor.'];
    }
    public function validarWebhook(array $request, string $rawBody): array {
        $segredo = trim((string)config_valor_texto($this->pdo, 'gateway_webhook_segredo', ''));
        if ($segredo === '') {
            return ['valido' => false, 'evento' => [], 'erro' => 'Segredo de webhook não configurado.'];
        }
        $token = (string)($request['token'] ?? $request['chave_api'] ?? $request['webhook_secret'] ?? '');
        $headerToken = '';
        foreach (['HTTP_X_WEBHOOK_SECRET','HTTP_X_EUPAGO_SECRET','HTTP_AUTHORIZATION'] as $h) {
            if (!empty($_SERVER[$h])) { $headerToken = (string)$_SERVER[$h]; break; }
        }
        $valido = hash_equals($segredo, $token) || hash_equals($segredo, $headerToken);
        if (!$valido) {
            return ['valido' => false, 'evento' => [], 'erro' => 'Autenticação do webhook falhou.'];
        }
        $id = (string)($request['identificador'] ?? $request['referencia'] ?? $request['transaction_id'] ?? $request['mp'] ?? '');
        $estadoRaw = mb_strtolower((string)($request['estado'] ?? $request['status'] ?? ''));
        $confirmado = in_array($estadoRaw, ['pago','paid','success','ok','confirmado','aceite'], true);
        $marcacaoRef = (string)($request['marcacao_ref'] ?? $request['ref'] ?? '');
        $marcacaoId = (int)($request['marcacao_id'] ?? 0);
        $pagamentoId = (int)($request['pagamento_id'] ?? 0);
        $gatewayMetodo = (string)($request['metodo'] ?? $request['method'] ?? '');
        return ['valido' => true, 'evento' => [
            'gateway' => 'eupago',
            'idempotency_key' => $id !== '' ? ('eupago:'.$id) : '',
            'marcacao_id' => $marcacaoId,
            'pagamento_id' => $pagamentoId ?: null,
            'marcacao_ref' => $marcacaoRef,
            'confirmado' => $confirmado,
            'estado' => $confirmado ? 'pago' : 'falhado',
            'referencia_transacao' => $id,
            'gateway_metodo' => $gatewayMetodo,
        ], 'erro' => ''];
    }
}

final class GatewayStripe implements GatewayPagamento {
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function nome(): string { return 'stripe'; }

    private function chaveSecreta(): string {
        $env = getenv('STRIPE_SECRET_KEY');
        if (is_string($env) && trim($env) !== '') { return trim($env); }
        return trim((string)config_valor_texto($this->pdo, 'stripe_secret_key', ''));
    }
    private function segredoWebhook(): string {
        $env = getenv('STRIPE_WEBHOOK_SECRET');
        if (is_string($env) && trim($env) !== '') { return trim($env); }
        return trim((string)config_valor_texto($this->pdo, 'stripe_webhook_secret', ''));
    }

    /**
     * Pedido mínimo à API Stripe (form-urlencoded), reutilizado pelo
     * PaymentIntent. Não regista nem devolve a chave secreta.
     */
    private function pedidoApi(string $metodo, string $caminho, array $dados = [], array $cabecalhos = []): array
    {
        $sk = $this->chaveSecreta();
        if ($sk === '') {
            return ['ok' => false, 'http' => 0, 'json' => null, 'erro' => 'Chave secreta Stripe em falta.'];
        }

        $url = 'https://api.stripe.com/v1/' . ltrim($caminho, '/');
        if ($metodo === 'GET' && $dados !== []) {
            $url .= '?' . http_build_query($dados);
        }

        $ch = curl_init($url);
        $opcoes = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => array_merge([
                'Authorization: Bearer ' . $sk,
                'Content-Type: application/x-www-form-urlencoded',
            ], $cabecalhos),
        ];
        // Em Windows/WAMP o PHP pode não ter uma CAStore configurada. O bundle
        // local (certs/cacert.pem) mantém a verificação TLS ativa sem desativar
        // a validação. Em Linux/produção, se o ficheiro não existir, usa-se a
        // configuração do sistema.
        $caLocal = dirname(__DIR__, 2) . '/certs/cacert.pem';
        if (is_file($caLocal)) {
            $opcoes[CURLOPT_CAINFO] = $caLocal;
        }
        if ($metodo === 'POST') {
            $opcoes[CURLOPT_POST] = true;
            $opcoes[CURLOPT_POSTFIELDS] = http_build_query($dados);
        } elseif ($metodo === 'DELETE') {
            $opcoes[CURLOPT_CUSTOMREQUEST] = 'DELETE';
            if ($dados !== []) { $opcoes[CURLOPT_POSTFIELDS] = http_build_query($dados); }
        }
        curl_setopt_array($ch, $opcoes);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['ok' => false, 'http' => 0, 'json' => null, 'erro' => 'Falha na ligação ao Stripe: ' . $err];
        }
        $json = json_decode((string)$resp, true);
        if ($http >= 400 || !is_array($json)) {
            $msg = is_array($json) && isset($json['error']['message'])
                ? (string)$json['error']['message']
                : 'Resposta inválida do Stripe.';
            return ['ok' => false, 'http' => $http, 'json' => is_array($json) ? $json : null, 'erro' => $msg];
        }
        return ['ok' => true, 'http' => $http, 'json' => $json, 'erro' => ''];
    }

    /**
     * Cria um PaymentIntent para o Payment Element embutido.
     * O montante chega sempre calculado pelo servidor; este método não aceita
     * valores do browser.
     */
    public function criarPaymentIntent(array $ctx): array
    {
        $valor = round((float)($ctx['valor'] ?? 0), 2);
        if ($valor <= 0) {
            return ['ok' => false, 'erro' => 'Valor de pagamento inválido.'];
        }
        $marcacaoId = (int)($ctx['marcacao_id'] ?? 0);
        $pagamentoId = (int)($ctx['pagamento_id'] ?? 0);
        $ref = trim((string)($ctx['referencia'] ?? ''));
        if ($marcacaoId <= 0 || $pagamentoId <= 0 || $ref === '') {
            return ['ok' => false, 'erro' => 'Contexto de pagamento incompleto.'];
        }

        $moeda = mb_strtolower(trim((string)config_valor_texto($this->pdo, 'moeda', 'EUR')));
        if ($moeda === '') { $moeda = 'eur'; }
        $amount = (int)round($valor * 100);

        $params = [
            'amount'                            => (string)$amount,
            'currency'                          => $moeda,
            'automatic_payment_methods[enabled]' => 'true',
            'description'                       => mb_substr('Marcação ' . $ref, 0, 200),
            'metadata[marcacao_id]'             => (string)$marcacaoId,
            'metadata[pagamento_id]'            => (string)$pagamentoId,
            'metadata[referencia]'              => $ref,
            'metadata[tipo]'                    => (string)($ctx['tipo'] ?? 'total'),
        ];
        $customerId = trim((string)($ctx['customer_id'] ?? ''));
        if ($customerId !== '') {
            if (!preg_match('/^cus_[A-Za-z0-9_]+$/', $customerId)) {
                return ['ok' => false, 'erro' => 'Identificador de cliente Stripe inválido.'];
            }
            $params['customer'] = $customerId;
        }
        $email = trim((string)($ctx['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $params['receipt_email'] = $email;
        }

        $idem = 'stripe:pi:' . $pagamentoId . ':' . $amount . ':'
              . substr(hash('sha256', http_build_query($params)), 0, 12);
        $r = $this->pedidoApi('POST', 'payment_intents', $params, [
            'Idempotency-Key: ' . $idem,
        ]);
        if (!$r['ok'] || empty($r['json']['id']) || empty($r['json']['client_secret'])) {
            return ['ok' => false, 'erro' => 'Stripe: ' . ($r['erro'] ?: 'não foi possível criar a intenção de pagamento.')];
        }
        $pi = $r['json'];
        return [
            'ok'                => true,
            'payment_intent_id' => (string)$pi['id'],
            'client_secret'     => (string)$pi['client_secret'],
            'status'            => (string)($pi['status'] ?? ''),
            'amount'            => (int)($pi['amount'] ?? $amount),
            'currency'          => (string)($pi['currency'] ?? $moeda),
        ];
    }

    /**
     * Obtém um PaymentIntent. Por omissão expande latest_charge para permitir
     * ler marca/últimos 4 dígitos do cartão sem guardar dados sensíveis.
     */
    public function obterPaymentIntent(string $id, bool $expandirCobranca = true): array
    {
        $id = trim($id);
        if (!preg_match('/^pi_[A-Za-z0-9_]+$/', $id)) {
            return ['ok' => false, 'erro' => 'Identificador de PaymentIntent inválido.'];
        }
        $dados = $expandirCobranca ? ['expand[0]' => 'latest_charge'] : [];
        $r = $this->pedidoApi('GET', 'payment_intents/' . rawurlencode($id), $dados);
        if (!$r['ok'] || !is_array($r['json'])) {
            return ['ok' => false, 'erro' => 'Stripe: ' . ($r['erro'] ?: 'PaymentIntent indisponível.')];
        }
        return ['ok' => true, 'payment_intent' => $r['json']];
    }

    public function cancelarPaymentIntent(string $id): array
    {
        $id = trim($id);
        if (!preg_match('/^pi_[A-Za-z0-9_]+$/', $id)) {
            return ['ok' => false, 'erro' => 'Identificador de PaymentIntent inválido.'];
        }
        $r = $this->pedidoApi('POST', 'payment_intents/' . rawurlencode($id) . '/cancel');
        if (!$r['ok'] || !is_array($r['json'])) {
            return ['ok' => false, 'erro' => 'Stripe: ' . ($r['erro'] ?: 'não foi possível cancelar.')];
        }
        return ['ok' => true, 'payment_intent' => $r['json']];
    }

    /**
     * Extrai apenas marca e últimos 4 dígitos. Nunca devolve número completo,
     * validade ou CVC.
     */
    public function dadosCartao(array $pi): array
    {
        $charge = $pi['latest_charge'] ?? null;
        if (is_string($charge) && $charge !== '') {
            return [];
        }
        if (!is_array($charge)) {
            $charge = $pi['charges']['data'][0] ?? null;
        }
        if (!is_array($charge)) {
            return [];
        }
        $card = $charge['payment_method_details']['card'] ?? null;
        if (!is_array($card)) {
            return [];
        }
        $marca = strtolower(trim((string)($card['brand'] ?? '')));
        $ultimos4 = preg_replace('/\D+/', '', (string)($card['last4'] ?? ''));
        if ($marca === '' || strlen((string)$ultimos4) !== 4) {
            return [];
        }
        return ['marca' => mb_substr($marca, 0, 20), 'ultimos4' => (string)$ultimos4];
    }

    /** Cria um Customer Stripe associado a uma conta local. */
    public function criarCliente(array $ctx): array
    {
        $utilizadorId = (int)($ctx['utilizador_id'] ?? 0);
        $email = trim((string)($ctx['email'] ?? ''));
        $nome = trim((string)($ctx['nome'] ?? ''));
        if ($utilizadorId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'erro' => 'Dados da conta incompletos para criar o cliente Stripe.'];
        }

        $dados = [
            'email' => $email,
            'name' => mb_substr($nome !== '' ? $nome : $email, 0, 150),
            'metadata[utilizador_id]' => (string)$utilizadorId,
        ];
        $r = $this->pedidoApi('POST', 'customers', $dados, [
            'Idempotency-Key: clinica:customer:utilizador:' . $utilizadorId,
        ]);
        if (!$r['ok'] || empty($r['json']['id'])) {
            return ['ok' => false, 'erro' => 'Stripe: ' . ($r['erro'] ?: 'não foi possível criar o perfil de pagamentos.')];
        }
        return ['ok' => true, 'customer_id' => (string)$r['json']['id']];
    }

    public function obterCliente(string $customerId): array
    {
        $customerId = trim($customerId);
        if (!preg_match('/^cus_[A-Za-z0-9_]+$/', $customerId)) {
            return ['ok' => false, 'erro' => 'Identificador de cliente Stripe inválido.'];
        }
        $r = $this->pedidoApi('GET', 'customers/' . rawurlencode($customerId));
        if (!$r['ok'] || !is_array($r['json'])) {
            return ['ok' => false, 'erro' => (string)($r['erro'] ?? 'Cliente Stripe indisponível.')];
        }
        return ['ok' => true, 'customer' => $r['json']];
    }

    /** Customer Session para o Payment Element apresentar cartões já guardados. */
    public function criarCustomerSession(string $customerId): array
    {
        $customerId = trim($customerId);
        if (!preg_match('/^cus_[A-Za-z0-9_]+$/', $customerId)) {
            return ['ok' => false, 'erro' => 'Identificador de cliente Stripe inválido.'];
        }
        $dados = [
            'customer' => $customerId,
            'components[payment_element][enabled]' => 'true',
            'components[payment_element][features][payment_method_redisplay]' => 'enabled',
            'components[payment_element][features][payment_method_redisplay_limit]' => '10',
            'components[payment_element][features][payment_method_save]' => 'disabled',
            'components[payment_element][features][payment_method_remove]' => 'disabled',
        ];
        $r = $this->pedidoApi('POST', 'customer_sessions', $dados);
        if (!$r['ok'] || empty($r['json']['client_secret'])) {
            return ['ok' => false, 'erro' => 'Stripe: ' . ($r['erro'] ?: 'não foi possível preparar os cartões guardados.')];
        }
        return ['ok' => true, 'client_secret' => (string)$r['json']['client_secret']];
    }

    /** Cria uma intenção de guardar um cartão sem cobrar dinheiro. */
    public function criarSetupIntent(string $customerId, int $utilizadorId): array
    {
        $customerId = trim($customerId);
        if (!preg_match('/^cus_[A-Za-z0-9_]+$/', $customerId) || $utilizadorId <= 0) {
            return ['ok' => false, 'erro' => 'Dados inválidos para guardar o cartão.'];
        }
        $dados = [
            'customer' => $customerId,
            'usage' => 'on_session',
            'payment_method_types[0]' => 'card',
            'description' => 'Cartão guardado pelo utilizador ' . $utilizadorId . ' da Clínica Dentária',
            'metadata[utilizador_id]' => (string)$utilizadorId,
            'metadata[usage]' => 'on_session',
        ];
        $idem = 'clinica:setup:utilizador:' . $utilizadorId . ':' . bin2hex(random_bytes(12));
        $r = $this->pedidoApi('POST', 'setup_intents', $dados, ['Idempotency-Key: ' . $idem]);
        if (!$r['ok'] || empty($r['json']['id']) || empty($r['json']['client_secret'])) {
            return ['ok' => false, 'erro' => 'Stripe: ' . ($r['erro'] ?: 'não foi possível iniciar a validação do cartão.')];
        }
        return [
            'ok' => true,
            'setup_intent_id' => (string)$r['json']['id'],
            'client_secret' => (string)$r['json']['client_secret'],
        ];
    }

    public function obterSetupIntent(string $setupIntentId): array
    {
        $setupIntentId = trim($setupIntentId);
        if (!preg_match('/^seti_[A-Za-z0-9_]+$/', $setupIntentId)) {
            return ['ok' => false, 'erro' => 'Identificador de configuração de cartão inválido.'];
        }
        $r = $this->pedidoApi('GET', 'setup_intents/' . rawurlencode($setupIntentId));
        if (!$r['ok'] || !is_array($r['json'])) {
            return ['ok' => false, 'erro' => (string)($r['erro'] ?? 'Não foi possível validar o cartão.')];
        }
        return ['ok' => true, 'setup_intent' => $r['json']];
    }

    public function cancelarSetupIntent(string $setupIntentId): array
    {
        $setupIntentId = trim($setupIntentId);
        if (!preg_match('/^seti_[A-Za-z0-9_]+$/', $setupIntentId)) {
            return ['ok' => false, 'erro' => 'Identificador de configuração de cartão inválido.'];
        }
        $r = $this->pedidoApi('POST', 'setup_intents/' . rawurlencode($setupIntentId) . '/cancel');
        if (!$r['ok'] || !is_array($r['json'])) {
            return ['ok' => false, 'erro' => (string)($r['erro'] ?? 'Não foi possível cancelar a configuração do cartão.')];
        }
        return ['ok' => true, 'status' => (string)($r['json']['status'] ?? '')];
    }

    public function listarCartoes(string $customerId): array
    {
        $customerId = trim($customerId);
        if (!preg_match('/^cus_[A-Za-z0-9_]+$/', $customerId)) {
            return ['ok' => false, 'cartoes' => [], 'erro' => 'Identificador de cliente Stripe inválido.'];
        }
        $r = $this->pedidoApi('GET', 'payment_methods', [
            'customer' => $customerId,
            'type' => 'card',
            'limit' => 100,
        ]);
        if (!$r['ok'] || !is_array($r['json'])) {
            return ['ok' => false, 'cartoes' => [], 'erro' => (string)($r['erro'] ?? 'Não foi possível consultar os cartões.')];
        }
        return ['ok' => true, 'cartoes' => is_array($r['json']['data'] ?? null) ? $r['json']['data'] : []];
    }

    public function permitirReexibirCartao(string $paymentMethodId): array
    {
        $paymentMethodId = trim($paymentMethodId);
        if (!preg_match('/^pm_[A-Za-z0-9_]+$/', $paymentMethodId)) {
            return ['ok' => false, 'erro' => 'Identificador de cartão inválido.'];
        }
        $r = $this->pedidoApi('POST', 'payment_methods/' . rawurlencode($paymentMethodId), [
            'allow_redisplay' => 'always',
        ]);
        if (!$r['ok']) {
            return ['ok' => false, 'erro' => (string)($r['erro'] ?? 'Não foi possível disponibilizar o cartão para próximos pagamentos.')];
        }
        return ['ok' => true];
    }

    public function removerCartao(string $customerId, string $paymentMethodId): array
    {
        $customerId = trim($customerId);
        $paymentMethodId = trim($paymentMethodId);
        if (!preg_match('/^cus_[A-Za-z0-9_]+$/', $customerId)
            || !preg_match('/^pm_[A-Za-z0-9_]+$/', $paymentMethodId)) {
            return ['ok' => false, 'erro' => 'Identificador de cartão inválido.'];
        }

        // Confirmar a propriedade do cartão antes de o desassociar.
        $lista = $this->listarCartoes($customerId);
        if (empty($lista['ok'])) {
            return ['ok' => false, 'erro' => (string)($lista['erro'] ?? 'Não foi possível validar a propriedade do cartão.')];
        }
        $pertence = false;
        foreach ($lista['cartoes'] as $cartao) {
            if (($cartao['id'] ?? '') === $paymentMethodId) {
                $pertence = true;
                break;
            }
        }
        if (!$pertence) {
            return ['ok' => false, 'erro' => 'Esse cartão não pertence a esta conta.'];
        }

        $r = $this->pedidoApi('POST', 'payment_methods/' . rawurlencode($paymentMethodId) . '/detach');
        if (!$r['ok']) {
            return ['ok' => false, 'erro' => (string)($r['erro'] ?? 'Não foi possível remover o cartão.')];
        }
        return ['ok' => true];
    }

    public function iniciarPagamento(array $ctx): array {
        $sk = $this->chaveSecreta();
        if ($sk === '') {
            return ['ok' => false, 'erro' => 'Stripe não configurado (chave secreta em falta). Configure STRIPE_SECRET_KEY (variável de ambiente) ou stripe_secret_key nas configurações.'];
        }
        $valor = round((float)($ctx['valor'] ?? 0), 2);
        if ($valor <= 0) { return ['ok' => false, 'erro' => 'Valor de pagamento inválido.']; }
        $amount = (int)round($valor * 100);
        $moeda = mb_strtolower(trim((string)config_valor_texto($this->pdo, 'moeda', 'EUR')));
        $ref = (string)($ctx['referencia'] ?? '');
        $marcacaoId = (int)($ctx['marcacao_id'] ?? 0);
        $pagamentoId = (int)($ctx['pagamento_id'] ?? 0);
        $tipo = (string)($ctx['tipo'] ?? 'total');
        $base = gateway_base_url($this->pdo);
        $success = $base . '/pagamento-retorno.php?ref=' . urlencode($ref) . '&stripe=success&session_id={CHECKOUT_SESSION_ID}';
        // Após criar a marcação, a sessão do wizard é limpa. O cancelamento tem
        // de voltar a uma página que consulte a marcação pela referência, não ao
        // formulário que depende dessa sessão.
        $cancel  = $base . '/pagamento-retorno.php?ref=' . urlencode($ref) . '&stripe=cancel';

        $params = [
            'mode' => 'payment',
            'success_url' => $success,
            'cancel_url' => $cancel,
            'client_reference_id' => $ref,
            'line_items[0][price_data][currency]' => $moeda ?: 'eur',
            'line_items[0][price_data][product_data][name]' => ($tipo === 'sinal' ? 'Sinal da consulta ' : 'Pagamento da consulta ') . $ref,
            'line_items[0][price_data][unit_amount]' => (string)$amount,
            'line_items[0][quantity]' => '1',
            'metadata[marcacao_id]' => (string)$marcacaoId,
            'metadata[pagamento_id]' => (string)$pagamentoId,
            'metadata[referencia]' => $ref,
            'metadata[tipo]' => $tipo,
            'payment_intent_data[metadata][marcacao_id]' => (string)$marcacaoId,
            'payment_intent_data[metadata][pagamento_id]' => (string)$pagamentoId,
            'payment_intent_data[metadata][referencia]' => $ref,
            'payment_intent_data[metadata][tipo]' => $tipo,
        ];

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $sk,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['ok' => false, 'erro' => 'Falha na ligação ao Stripe: ' . $err];
        }
        $json = json_decode($resp, true);
        if ($http >= 400 || !is_array($json) || empty($json['id']) || empty($json['url'])) {
            $msg = is_array($json) && isset($json['error']['message']) ? (string)$json['error']['message'] : 'Resposta inválida do Stripe.';
            return ['ok' => false, 'erro' => 'Stripe: ' . $msg];
        }
        $sessionId = (string)$json['id'];
        return [
            'ok' => true,
            'redirect_url' => (string)$json['url'],
            'referencia' => $sessionId,
            'session_id' => $sessionId,
            'idempotency_key' => 'stripe:checkout:' . $sessionId,
            'gateway_metodo' => 'cartao_online',
        ];
    }

    public function validarWebhook(array $request, string $rawBody): array {
        $secret = $this->segredoWebhook();
        if ($secret === '') {
            return ['valido' => false, 'evento' => [], 'erro' => 'Segredo de webhook Stripe não configurado.'];
        }
        $sigHeader = '';
        foreach (['HTTP_STRIPE_SIGNATURE', 'Stripe-Signature', 'stripe-signature'] as $k) {
            if (!empty($_SERVER[$k])) { $sigHeader = (string)$_SERVER[$k]; break; }
            if (!empty($request[$k])) { $sigHeader = (string)$request[$k]; break; }
        }
        if ($sigHeader === '') {
            return ['valido' => false, 'evento' => [], 'erro' => 'Assinatura Stripe em falta.'];
        }
        $parts = [];
        foreach (explode(',', $sigHeader) as $item) {
            $kv = explode('=', trim($item), 2);
            if (count($kv) === 2) { $parts[$kv[0]] = $kv[1]; }
        }
        $t = $parts['t'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if ($t === '' || $v1 === '') {
            return ['valido' => false, 'evento' => [], 'erro' => 'Formato de assinatura inválido.'];
        }
        if (abs(time() - (int)$t) > 300) {
            return ['valido' => false, 'evento' => [], 'erro' => 'Assinatura expirada.'];
        }
        $signed = $t . '.' . $rawBody;
        $expected = hash_hmac('sha256', $signed, $secret);
        if (!hash_equals($expected, $v1)) {
            return ['valido' => false, 'evento' => [], 'erro' => 'Assinatura inválida.'];
        }
        $event = json_decode($rawBody, true);
        if (!is_array($event) || empty($event['type']) || empty($event['id'])) {
            return ['valido' => false, 'evento' => [], 'erro' => 'Evento Stripe inválido.'];
        }
        $type = (string)$event['type'];
        $obj = $event['data']['object'] ?? [];
        if (!is_array($obj)) { $obj = []; }

        $meta = $obj['metadata'] ?? [];
        if (!is_array($meta)) { $meta = []; }
        $marcacaoId = (int)($meta['marcacao_id'] ?? $request['marcacao_id'] ?? 0);
        $pagamentoId = (int)($meta['pagamento_id'] ?? $request['pagamento_id'] ?? 0);
        $referenciaMarc = (string)($meta['referencia'] ?? $obj['client_reference_id'] ?? '');

        $confirmado = null;
        $estado = 'em_processamento';
        $gatewayMetodo = 'cartao_online';
        $txRef = (string)($obj['id'] ?? $event['id']);

        // Valor e moeda do evento. O Stripe usa a unidade mínima da moeda;
        // a lista abaixo cobre as moedas sem casas decimais.
        $valorEvento = null;
        $moedaEvento = '';
        $centavos = $obj['amount_total'] ?? $obj['amount'] ?? null;
        if (is_numeric($centavos)) {
            $moedaEvento = mb_strtolower(trim((string)($obj['currency'] ?? '')));
            $semDecimais = in_array($moedaEvento, ['bif','clp','djf','gnf','jpy','kmf','krw','mga','pyg','rwf','ugx','vnd','vuv','xaf','xof','xpf'], true);
            $valorEvento = round(((float)$centavos) / ($semDecimais ? 1 : 100), 2);
        }

        if ($type === 'checkout.session.completed') {
            $paid = ($obj['payment_status'] ?? '') === 'paid';
            $confirmado = $paid;
            $estado = $paid ? 'pago' : 'em_processamento';
            $txRef = (string)($obj['payment_intent'] ?? $obj['id'] ?? $event['id']);
            $gatewayMetodo = 'cartao_online';
        } elseif ($type === 'checkout.session.async_payment_succeeded') {
            $confirmado = true; $estado = 'pago';
            $txRef = (string)($obj['payment_intent'] ?? $obj['id'] ?? $event['id']);
        } elseif ($type === 'checkout.session.async_payment_failed' || $type === 'checkout.session.expired') {
            $confirmado = false; $estado = 'falhado';
        } elseif ($type === 'payment_intent.succeeded') {
            $confirmado = true; $estado = 'pago';
            $txRef = (string)($obj['id'] ?? $event['id']);
        } elseif ($type === 'payment_intent.processing') {
            $confirmado = false; $estado = 'em_processamento';
            $txRef = (string)($obj['id'] ?? $event['id']);
        } elseif ($type === 'payment_intent.payment_failed') {
            $confirmado = false; $estado = 'falhado';
            $txRef = (string)($obj['id'] ?? $event['id']);
        } elseif ($type === 'payment_intent.canceled') {
            $confirmado = false; $estado = 'cancelado';
            $txRef = (string)($obj['id'] ?? $event['id']);
        } elseif ($type === 'charge.refunded') {
            $refundCents = $obj['amount_refunded'] ?? null;
            $refundCurrency = mb_strtolower(trim((string)($obj['currency'] ?? $moedaEvento)));
            $refundSemDecimais = in_array($refundCurrency, ['bif','clp','djf','gnf','jpy','kmf','krw','mga','pyg','rwf','ugx','vnd','vuv','xaf','xof','xpf'], true);
            $refundAcumulado = is_numeric($refundCents)
                ? round(((float)$refundCents) / ($refundSemDecimais ? 1 : 100), 2)
                : null;
            return ['valido' => true, 'evento' => [
                'gateway' => 'stripe',
                'idempotency_key' => 'stripe:event:' . $event['id'],
                'tipo_evento' => $type,
                'marcacao_id' => $marcacaoId,
                'pagamento_id' => $pagamentoId ?: null,
                'referencia_transacao' => (string)($obj['payment_intent'] ?? ''),
                'moeda' => $refundCurrency !== '' ? $refundCurrency : null,
                'valor_reembolsado_acumulado' => $refundAcumulado,
                'refund' => true,
            ], 'erro' => ''];
        } else {
            return ['valido' => true, 'evento' => [
                'gateway' => 'stripe',
                'idempotency_key' => 'stripe:event:' . $event['id'],
                'tipo_evento' => $type,
                'ignorar' => true,
            ], 'erro' => ''];
        }

        if ($confirmado === null) {
            return ['valido' => true, 'evento' => ['gateway'=>'stripe','idempotency_key'=>'stripe:event:'.$event['id'],'ignorar'=>true], 'erro' => ''];
        }

        return ['valido' => true, 'evento' => [
            'gateway' => 'stripe',
            'idempotency_key' => 'stripe:event:' . $event['id'],
            'tipo_evento' => $type,
            'marcacao_id' => $marcacaoId,
            'pagamento_id' => $pagamentoId ?: null,
            'marcacao_ref' => $referenciaMarc,
            'confirmado' => (bool)$confirmado,
            'estado' => $estado,
            'referencia_transacao' => $txRef,
            'gateway_metodo' => $gatewayMetodo,
            'valor' => $valorEvento,
            'moeda' => $moedaEvento !== '' ? $moedaEvento : null,
        ], 'erro' => ''];
    }
}

function gateway_obter(PDO $pdo): GatewayPagamento {
    $g = trim((string)config_valor_texto($pdo, 'gateway_pagamentos', ''));
    if ($g === 'stripe') return new GatewayStripe($pdo);
    if ($g === 'eupago') return new GatewayEupagoScaffold($pdo);
    return new GatewayNaoConfigurado();
}

/**
 * Garante que a conta local tem um Customer Stripe no ambiente das chaves
 * atualmente configuradas. O identificador é o único dado Stripe pessoal
 * guardado nesta tabela; números e CVC nunca passam pelo servidor da clínica.
 */
function gateway_cliente_obter_ou_criar(PDO $pdo, GatewayStripe $gateway, int $utilizadorId): array
{
    if ($utilizadorId <= 0) {
        return ['ok' => false, 'erro' => 'Conta de utilizador inválida.'];
    }

    $st = $pdo->prepare('SELECT id, nome_completo, email, stripe_customer_id FROM utilizadores WHERE id = ? AND ativo = 1');
    $st->execute([$utilizadorId]);
    $u = $st->fetch();
    if (!$u) {
        return ['ok' => false, 'erro' => 'Conta de utilizador não encontrada ou inativa.'];
    }

    $customerId = trim((string)($u['stripe_customer_id'] ?? ''));
    if ($customerId !== '') {
        $existente = $gateway->obterCliente($customerId);
        if (!empty($existente['ok'])) {
            $metadata = $existente['customer']['metadata'] ?? [];
            $owner = (int)($metadata['utilizador_id'] ?? 0);
            if ($owner === $utilizadorId) {
                return ['ok' => true, 'customer_id' => $customerId];
            }
        }
        // Um ID de teste não existe quando se muda para chaves de produção
        // (e vice-versa). Nesse caso cria-se um Customer para o ambiente atual.
    }

    $criado = $gateway->criarCliente([
        'utilizador_id' => $utilizadorId,
        'nome' => (string)$u['nome_completo'],
        'email' => (string)$u['email'],
    ]);
    if (empty($criado['ok']) || empty($criado['customer_id'])) {
        return ['ok' => false, 'erro' => (string)($criado['erro'] ?? 'Não foi possível preparar a conta de pagamentos.')];
    }

    $novoId = (string)$criado['customer_id'];
    $pdo->prepare('UPDATE utilizadores SET stripe_customer_id = ? WHERE id = ?')
        ->execute([$novoId, $utilizadorId]);

    return ['ok' => true, 'customer_id' => $novoId];
}

/**
 * URL base absoluta para os retornos do gateway.
 * Prioridade: site_base_url (configuração) > domínio do pedido + BASE_URL.
 * Sem host (CLI) devolve apenas o caminho; nesse caso o Stripe rejeita a URL,
 * pelo que site_base_url deve estar configurado em produção.
 */
function gateway_base_url(PDO $pdo): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '' && !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        $host = ''; // rejeita Host inválido (cabeçalho não confiável)
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $basePath = rtrim(defined('BASE_URL') ? (string)BASE_URL : '/', '/');

    $config = trim((string)config_valor_texto($pdo, 'site_base_url', ''));
    if ($config !== '') {
        if (preg_match('~^https?://~i', $config)) {
            return rtrim($config, '/');
        }
        if ($host !== '') {
            return $scheme . '://' . $host . '/' . ltrim($config, '/');
        }
        return rtrim($config, '/');
    }

    if ($host !== '') {
        return $scheme . '://' . $host . $basePath;
    }
    return $basePath;
}

function gateway_stripe_configurado(PDO $pdo): bool {
    $sk = getenv('STRIPE_SECRET_KEY');
    if (is_string($sk) && trim($sk) !== '') { return true; }
    return trim((string)config_valor_texto($pdo, 'stripe_secret_key', '')) !== '';
}
function gateway_stripe_publishable_key(PDO $pdo): string {
    $env = getenv('STRIPE_PUBLISHABLE_KEY');
    if (is_string($env) && trim($env) !== '') { return trim($env); }
    return trim((string)config_valor_texto($pdo, 'stripe_publishable_key', ''));
}
function gateway_stripe_webhook_configurado(PDO $pdo): bool {
    $s = getenv('STRIPE_WEBHOOK_SECRET');
    if (is_string($s) && trim($s) !== '') { return true; }
    return trim((string)config_valor_texto($pdo, 'stripe_webhook_secret', '')) !== '';
}

/**
 * Indica se existe um gateway realmente utilizável para iniciar e confirmar
 * pagamentos online. O Eupago está em scaffold (não inicia cobranças) e o
 * Stripe exige chave secreta + segredo de webhook. Sem isto, o método online
 * não deve aparecer aos pacientes, mesmo com pagamento_online_ativo=1.
 */
function gateway_pagamento_disponivel(PDO $pdo): bool
{
    if (!(gateway_obter($pdo) instanceof GatewayStripe)
        || !gateway_stripe_configurado($pdo)
        || !gateway_stripe_webhook_configurado($pdo)) {
        return false;
    }

    $secret = getenv('STRIPE_SECRET_KEY');
    if (!is_string($secret) || trim($secret) === '') {
        $secret = config_valor_texto($pdo, 'stripe_secret_key', '');
    }
    $publishable = gateway_stripe_publishable_key($pdo);

    $modoTeste = str_starts_with($secret, 'sk_test_')
        && str_starts_with($publishable, 'pk_test_');
    $modoProducao = str_starts_with($secret, 'sk_live_')
        && str_starts_with($publishable, 'pk_live_');

    // Evita expor um checkout quando as chaves pública e secreta são de
    // ambientes diferentes ou não pertencem a um formato Stripe reconhecido.
    return $modoTeste || $modoProducao;
}