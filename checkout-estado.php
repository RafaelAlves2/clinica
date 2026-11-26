<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/gateway.php';
require __DIR__ . '/includes/pagamentos.php';
require __DIR__ . '/includes/checkout.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$responder = static function (int $http, array $dados): void {
    http_response_code($http);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$u = utilizador_atual();
$uid = (int)($u['id'] ?? 0);
if ($uid <= 0) {
    $responder(401, ['ok' => false, 'erro' => 'Sessão expirada. Inicia sessão novamente.']);
}

$pdo = db();
try {
    $st = $pdo->prepare('SELECT id, ativo FROM utilizadores WHERE id = ?');
    $st->execute([$uid]);
    $conta = $st->fetch();
    if (!$conta || (int)$conta['ativo'] !== 1) {
        $responder(401, ['ok' => false, 'erro' => 'Sessão inválida. Inicia sessão novamente.']);
    }
} catch (Throwable $e) {
    error_log('Checkout estado conta: ' . $e->getMessage());
    $responder(500, ['ok' => false, 'erro' => 'Não foi possível validar a sessão.']);
}

$ref  = trim((string)($_GET['ref'] ?? ''));
$piId = trim((string)($_GET['payment_intent'] ?? ''));

try {
    $marcacao = checkout_carregar_marcacao($pdo, $ref, $uid);
    $resumo   = checkout_resumo($pdo, $marcacao);
    $pagamento = checkout_pagamento_confirmacao($pdo, (int)$marcacao['id'], $piId);
} catch (RuntimeException $e) {
    $responder(404, ['ok' => false, 'erro' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Checkout estado: ' . $e->getMessage());
    $responder(500, ['ok' => false, 'erro' => 'Não foi possível consultar o pagamento.']);
}

$estado = $pagamento ? (string)$pagamento['estado'] : 'pendente';
$valor = $pagamento ? round((float)$pagamento['valor'], 2) : round((float)$resumo['valor'], 2);
$terminal = in_array($estado, ['pago', 'falhado', 'recusado', 'cancelado', 'reembolsado', 'parcialmente_reembolsado'], true);
$cartao = '';

if ($estado === 'pago') {
    $gateway = gateway_obter($pdo);
    if ($gateway instanceof GatewayStripe) {
        try {
            $dados = checkout_dados_cartao($pdo, $gateway, $pagamento);
            if (!empty($dados['marca']) && !empty($dados['ultimos4'])) {
                $cartao = pagamento_cartao_legivel((string)$dados['marca'], (string)$dados['ultimos4']);
            }
        } catch (Throwable $e) {
            error_log('Checkout estado cartão: ' . $e->getMessage());
        }
    }
}

$mensagem = match ($estado) {
    'pago' => $pagamento && (string)($pagamento['metodo'] ?? '') === 'sinal' && (float)$resumo['valor'] > 0.0001
        ? 'Sinal confirmado. Falta pagar ' . formatar_preco((float)$resumo['valor']) . ' na clínica.'
        : 'Pagamento confirmado.',
    'em_processamento' => 'O pagamento está a ser processado pelo banco.',
    'falhado', 'recusado' => 'O pagamento não foi autorizado. Nenhum valor foi cobrado.',
    'cancelado' => 'O pagamento foi cancelado.',
    'reembolsado' => 'O pagamento foi reembolsado. O valor voltou a ficar em dívida na marcação.',
    'parcialmente_reembolsado' => 'Foi registado um reembolso parcial. Consulta o saldo em falta.',
    'parcialmente_pago' => 'O pagamento parcial foi registado; falta liquidar o restante.',
    default => 'A aguardar confirmação segura da Stripe.',
};

$responder(200, [
    'ok' => true,
    'estado' => $estado,
    'estado_legivel' => pagamento_estado_legivel($estado),
    'terminal' => $terminal,
    'pago' => $estado === 'pago',
    'falhado' => in_array($estado, ['falhado', 'recusado', 'cancelado'], true),
    'mensagem' => $mensagem,
    'valor' => $valor,
    'valor_formatado' => formatar_preco($valor),
    'cartao' => $cartao,
    'pode_repetir' => in_array($estado, ['falhado', 'recusado', 'cancelado', 'reembolsado', 'parcialmente_reembolsado'], true) && !empty($resumo['pode_pagar']),
]);
