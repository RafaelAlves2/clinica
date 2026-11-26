<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/marcacao.php';
require __DIR__ . '/includes/conta.php';
require __DIR__ . '/includes/pagamentos.php';
require __DIR__ . '/includes/gateway.php';

header('Content-Type: application/json; charset=utf-8');

$metodoHttp = (string)($_SERVER['REQUEST_METHOD'] ?? '');
if ($metodoHttp !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'erro' => 'Método não suportado. Use POST.']);
    exit;
}

// Limite de tamanho razoável para webhook (proteção contra DoS).
// Stripe/Eupago enviam payloads <100KB normalmente; 1MB é margem folgada.
$__maxBytes = 1_048_576;
$__clen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($__clen > $__maxBytes) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'erro' => 'Payload demasiado grande.']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > $__maxBytes) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'erro' => 'Payload demasiado grande.']);
    exit;
}

$request = $_POST;
if ($raw !== '' && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $j = json_decode($raw, true);
    if (is_array($j)) { $request = array_merge($request, $j); }
}

try {
    $pdo = db();
    // O gateway é sempre o configurado no sistema. Nunca deve ser escolhido
    // por query string: isso permitiria contornar o gateway ativo.
    $gateway = gateway_obter($pdo);

    $val = $gateway->validarWebhook($request, $raw);

    // FIX (médio): falha de validação devolve SEMPRE 4xx, para TODOS os gateways.
    // Antes só o Stripe recebia 400; Eupago/futuros recebiam 200 e nunca retentavam,
    // perdendo pagamentos quando o erro era transitório.
    if (empty($val['valido'])) {
        error_log('Webhook pagamento rejeitado: ' . ($val['erro'] ?? 'inválido'));
        http_response_code(401);
        echo json_encode(['ok' => false, 'erro' => 'Não autorizado.']);
        exit;
    }

    $evento = $val['evento'] ?? [];

    // Evento marcado como ignorar: acknowledge 200 (é intencional, não é erro).
    if (!empty($evento['ignorar'])) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'ignorado' => true]);
        exit;
    }

    // Reembolso Stripe: reconciliar o montante acumulado e registar o evento
    // numa tabela própria, sem duplicar notificações em entregas repetidas.
    if (!empty($evento['refund'])) {
        try {
            $resRefund = gateway_registar_reembolso_stripe($pdo, $evento);
            try {
                registar_admin(
                    'webhook_refund',
                    'pagamentos',
                    (int)($resRefund['pagamento_id'] ?? 0),
                    'Reembolso Stripe reconciliado: ' . formatar_preco((float)($resRefund['valor_reembolso_novo'] ?? 0))
                    . (!empty($resRefund['deduplicado']) ? ' (duplicado)' : '')
                );
            } catch (Throwable $auditError) {
                error_log('Webhook refund auditoria: ' . $auditError->getMessage());
            }
            http_response_code(200);
            echo json_encode([
                'ok' => true,
                'refund_event' => true,
                'deduplicado' => !empty($resRefund['deduplicado']),
                'estado' => $resRefund['estado'] ?? null,
            ]);
        } catch (GatewayEventoInvalidoException $e) {
            error_log('Webhook refund rejeitado: ' . $e->getMessage());
            http_response_code(400);
            echo json_encode(['ok' => false, 'erro' => 'Evento de reembolso inválido.']);
        } catch (Throwable $e) {
            error_log('Webhook refund erro: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'erro' => 'Erro interno.']);
        }
        exit;
    }

    // FIX (menor): event sem idempotency_key é erro PERMANENTE — 4xx para o gateway não retentar.
    if (empty($evento['idempotency_key'])) {
        error_log('Webhook pagamento sem idempotency_key: ' . json_encode($evento));
        http_response_code(400);
        echo json_encode(['ok' => false, 'erro' => 'Evento sem identificador idempotente.']);
        exit;
    }

    $res = gateway_confirmar_pagamento($pdo, $evento);

    // Guardar apenas marca/últimos 4 dígitos para apresentação no recibo.
    // NUNCA guardar número completo, validade ou CVC. Se a consulta falhar,
    // o pagamento continua confirmado e o webhook não deve falhar por isto.
    if ($gateway instanceof GatewayStripe && ($res['estado'] ?? '') === 'pago') {
        try {
            $piId = trim((string)($evento['referencia_transacao'] ?? ''));
            if ($piId !== '' && str_starts_with($piId, 'pi_')) {
                $pi = $gateway->obterPaymentIntent($piId, true);
                if (!empty($pi['ok']) && is_array($pi['payment_intent'] ?? null)) {
                    $cartao = $gateway->dadosCartao($pi['payment_intent']);
                    if ($cartao !== []) {
                        pagamento_guardar_cartao_mascarado(
                            $pdo,
                            (int)($res['pagamento_id'] ?? 0),
                            (string)$cartao['marca'],
                            (string)$cartao['ultimos4']
                        );
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Webhook Stripe dados cartão: ' . $e->getMessage());
        }
    }

    // FIX (menor): registar em audit trail para rastreabilidade do pagamento.
    try {
        registar_admin('webhook_pagamento', 'pagamentos', (int)($res['pagamento_id'] ?? 0),
            'Evento ' . ($evento['gateway'] ?? '') . ' → estado ' . ($res['estado'] ?? '?')
            . ($res['deduplicado'] ?? false ? ' (duplicado)' : ''));
    } catch (Throwable $e) {
        error_log('Webhook registar_admin: ' . $e->getMessage());
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'deduplicado' => !empty($res['deduplicado']),
        'estado' => $res['estado'] ?? null,
    ]);
} catch (GatewayEventoInvalidoException $e) {
    // Evento inválido de forma permanente: 4xx para o gateway não retentar.
    error_log('Webhook pagamento rejeitado: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Evento inválido.']);
} catch (Throwable $e) {
    error_log('Webhook pagamento erro: ' . $e->getMessage());
    // FIX (médio): erro de processamento devolve 500 para o gateway RETENTAR.
    // Antes devolvia 200 e o pagamento perdia-se silenciosamente.
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro interno.']);
}