<?php
declare(strict_types=1);

/**
 * Serviço do checkout Stripe (Payment Element).
 *
 * Regras:
 * - O montante é sempre calculado aqui a partir da BD, nunca do browser.
 * - O pagamento é uma tentativa em `pagamentos`, associada à marcação e,
 *   quando existe uma só consulta, também à consulta/paciente.
 * - Nenhum dado completo de cartão passa por este código.
 */

function checkout_carregar_marcacao(PDO $pdo, string $referencia, int $utilizadorId): array
{
    $referencia = trim($referencia);
    if ($referencia === '' || $utilizadorId <= 0) {
        throw new RuntimeException('Marcação inválida.');
    }
    $st = $pdo->prepare('SELECT * FROM marcacoes WHERE referencia = ? AND utilizador_id = ?');
    $st->execute([$referencia, $utilizadorId]);
    $m = $st->fetch();
    if (!$m) {
        throw new RuntimeException('Marcação não encontrada.');
    }
    return $m;
}

/**
 * Resume o produto real, os pacientes e o valor em falta.
 */
function checkout_resumo(PDO $pdo, array $marcacao): array
{
    $st = $pdo->prepare(
        "SELECT c.id, c.paciente_id, c.preco_total, c.estado, c.inicio,
                pac.nome_completo AS paciente,
                pac.email AS paciente_email,
                pac.telemovel AS paciente_telemovel,
                GROUP_CONCAT(DISTINCT t.nome ORDER BY t.nome SEPARATOR ' + ') AS tratamentos
           FROM consultas c
           JOIN pacientes pac ON pac.id = c.paciente_id
           LEFT JOIN consultas_tratamentos ct ON ct.consulta_id = c.id
           LEFT JOIN tratamentos t ON t.id = ct.tratamento_id
          WHERE c.marcacao_id = ?
          GROUP BY c.id, c.paciente_id, c.preco_total, c.estado, c.inicio,
                   pac.nome_completo, pac.email, pac.telemovel
          ORDER BY c.inicio, c.id"
    );
    $st->execute([(int)$marcacao['id']]);
    $consultas = $st->fetchAll();

    $fin = estado_financeiro_marcacao($pdo, (int)$marcacao['id']);
    $valor = round((float)$fin['divida'], 2);
    $total = round((float)$fin['total'], 2);

    $nomesTratamentos = [];
    $pacientes = [];
    foreach ($consultas as $c) {
        foreach (array_filter(array_map('trim', explode(' + ', (string)($c['tratamentos'] ?? '')))) as $nome) {
            if ($nome !== '') { $nomesTratamentos[$nome] = true; }
        }
        $nomePaciente = trim((string)($c['paciente'] ?? ''));
        if ($nomePaciente !== '') { $pacientes[$nomePaciente] = true; }
    }

    $produto = implode(' + ', array_keys($nomesTratamentos));
    if ($produto === '') {
        $produto = 'Pagamento da marcação ' . (string)$marcacao['referencia'];
    }
    $produto = mb_substr($produto, 0, 180);

    $primeira = $consultas[0] ?? null;
    $consultaUnicaId = count($consultas) === 1 ? (int)$consultas[0]['id'] : null;
    $email = trim((string)($primeira['paciente_email'] ?? ''));
    $telefone = trim((string)($primeira['paciente_telemovel'] ?? ''));
    $nome = trim((string)($primeira['paciente'] ?? ''));

    return [
        'consultas'          => $consultas,
        'consulta_unica_id'  => $consultaUnicaId,
        'produto'            => $produto,
        'pacientes'          => array_keys($pacientes),
        'paciente'           => $nome,
        'email'              => $email,
        'telefone'           => $telefone,
        'total'              => $total,
        'valor'              => $valor,
        'pago'               => round((float)$fin['pago'], 2),
        'reembolsado'        => round((float)$fin['reembolsado'], 2),
        'estimado'           => (int)$marcacao['tem_preco_estimado'] === 1,
        'cancelada'          => (string)$marcacao['estado'] === 'cancelada',
        'pode_pagar'         => (string)$marcacao['estado'] !== 'cancelada'
                                && (int)$marcacao['tem_preco_estimado'] !== 1
                                && $valor > 0.0001,
    ];
}

function checkout_pagamento_em_curso(PDO $pdo, int $marcacaoId, string $tipo = 'total'): ?array
{
    if ($tipo === 'sinal') {
        $sql = "SELECT * FROM pagamentos
                 WHERE marcacao_id = ? AND metodo = 'sinal' AND gateway = 'stripe'
                   AND estado IN ('pendente','em_processamento','falhado','recusado','cancelado')
                 ORDER BY id DESC LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([$marcacaoId]);
    } else {
        $sql = "SELECT * FROM pagamentos
                 WHERE marcacao_id = ?
                   AND metodo IN ('eletronico_antecipado', 'cartao_online')
                   AND estado IN ('pendente', 'em_processamento')
                 ORDER BY id DESC LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([$marcacaoId]);
    }
    $p = $st->fetch();
    return $p ?: null;
}

function checkout_criar_pagamento(PDO $pdo, array $marcacao, array $resumo): array
{
    $pdo->prepare(
        "INSERT INTO pagamentos
            (marcacao_id, consulta_id, metodo, valor, estado, gateway, gateway_metodo, prestador)
         VALUES (?, ?, 'cartao_online', ?, 'pendente', 'stripe', 'cartao_online', 'stripe')"
    )->execute([
        (int)$marcacao['id'],
        $resumo['consulta_unica_id'],
        round((float)$resumo['valor'], 2),
    ]);
    $id = (int)$pdo->lastInsertId();
    $st = $pdo->prepare('SELECT * FROM pagamentos WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: [];
}

/**
 * Obtém ou cria a intenção de pagamento. Reutiliza o PaymentIntent quando o
 * valor se mantém; se o valor mudou ou a tentativa foi cancelada, fecha a
 * tentativa antiga e cria uma nova, preservando o histórico.
 *
 * @return array{ok:bool,erro?:string,estado?:string,client_secret?:string,payment_intent_id?:string,pagamento_id?:int,payment_intent?:array}
 */
function checkout_preparar_intencao(
    PDO $pdo,
    GatewayStripe $gateway,
    array $marcacao,
    array $resumo,
    string $email,
    string $tipo = 'total',
    string $customerId = ''
): array {
    $tipo = $tipo === 'sinal' ? 'sinal' : 'total';
    $pag = checkout_pagamento_em_curso($pdo, (int)$marcacao['id'], $tipo);

    if ($tipo === 'sinal' && !$pag) {
        return ['ok' => false, 'erro' => 'Não existe um sinal pendente associado a esta marcação.'];
    }
    $valor = $tipo === 'sinal'
        ? round((float)($pag['valor'] ?? 0), 2)
        : round((float)$resumo['valor'], 2);

    if ($valor <= 0.0001 || $valor > (float)$resumo['valor'] + 0.0001) {
        return ['ok' => false, 'erro' => 'O valor a pagar já não corresponde ao saldo da marcação. Atualiza a página ou contacta a clínica.'];
    }
    if ($resumo['estimado']) {
        return ['ok' => false, 'erro' => 'Esta marcação inclui preços estimados. O pagamento online só fica disponível depois da avaliação clínica.'];
    }

    $piId = $pag ? trim((string)($pag['referencia_transacao'] ?? '')) : '';

    if ($piId !== '' && !str_starts_with($piId, 'pi_')) {
        // Referência de outro gateway/checkout antigo: não reutilizar.
        $pdo->prepare("UPDATE pagamentos SET estado='cancelado', cancelado_em=NOW() WHERE id=? AND estado IN ('pendente','em_processamento')")
            ->execute([(int)$pag['id']]);
        $pag = null;
        $piId = '';
    }

    if ($pag && $piId !== '') {
        $consultaPi = $gateway->obterPaymentIntent($piId, true);
        if (empty($consultaPi['ok']) || !is_array($consultaPi['payment_intent'] ?? null)) {
            return ['ok' => false, 'erro' => 'Não foi possível consultar o pagamento no Stripe. Tenta novamente.'];
        }
        $pi = $consultaPi['payment_intent'];
        $status = (string)($pi['status'] ?? '');
        $amountPi = (int)($pi['amount'] ?? 0);
        $amountEsperado = (int)round($valor * 100);
        $clienteEsperado = trim($customerId);
        $clienteAtual = trim((string)($pi['customer'] ?? ''));
        $customerDiferente = $clienteEsperado !== '' && $clienteAtual !== $clienteEsperado;

        if ($status === 'succeeded') {
            return [
                'ok' => true,
                'estado' => 'pago',
                'pagamento_id' => (int)$pag['id'],
                'payment_intent_id' => $piId,
                'payment_intent' => $pi,
            ];
        }

        if (!$customerDiferente
            && $amountPi === $amountEsperado
            && in_array($status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
            $pdo->prepare("UPDATE pagamentos SET estado='pendente', falhado_em=NULL, cancelado_em=NULL, atualizado_em=NOW() WHERE id=?")
                ->execute([(int)$pag['id']]);
            return [
                'ok' => true,
                'estado' => $status,
                'client_secret' => (string)($pi['client_secret'] ?? ''),
                'payment_intent_id' => $piId,
                'pagamento_id' => (int)$pag['id'],
                'payment_intent' => $pi,
            ];
        }

        if (!$customerDiferente && $amountPi === $amountEsperado && $status === 'processing') {
            $pdo->prepare("UPDATE pagamentos SET estado='em_processamento', falhado_em=NULL, atualizado_em=NOW() WHERE id=?")
                ->execute([(int)$pag['id']]);
            return [
                'ok' => true,
                'estado' => 'processing',
                'pagamento_id' => (int)$pag['id'],
                'payment_intent_id' => $piId,
                'payment_intent' => $pi,
            ];
        }

        // Valor diferente ou intenção já cancelada: fechar e recomeçar.
        if (in_array($status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
            $cancel = $gateway->cancelarPaymentIntent($piId);
            if (empty($cancel['ok'])) {
                return ['ok' => false, 'erro' => 'Não foi possível atualizar o valor do pagamento no Stripe. Contacta a clínica.'];
            }
        } elseif ($status !== 'canceled') {
            return ['ok' => false, 'erro' => 'O pagamento já não pode ser alterado neste estado (' . $status . '). Contacta a clínica.'];
        }
        $pdo->prepare("UPDATE pagamentos SET estado='cancelado', cancelado_em=NOW(), observacoes=TRIM(CONCAT(COALESCE(observacoes,''), ' [Tentativa substituída]')) WHERE id=?")
            ->execute([(int)$pag['id']]);
        $pag = null;
    }

    if (!$pag) {
        if ($tipo === 'sinal') {
            $pdo->prepare(
                "INSERT INTO pagamentos
                    (marcacao_id, consulta_id, metodo, valor, estado, gateway, gateway_metodo, prestador)
                 VALUES (?, ?, 'sinal', ?, 'pendente', 'stripe', 'cartao_online', 'stripe')"
            )->execute([(int)$marcacao['id'], $resumo['consulta_unica_id'], $valor]);
            $id = (int)$pdo->lastInsertId();
            $st = $pdo->prepare('SELECT * FROM pagamentos WHERE id = ?');
            $st->execute([$id]);
            $pag = $st->fetch() ?: null;
        } else {
            $pag = checkout_criar_pagamento($pdo, $marcacao, $resumo);
        }
        if (!$pag) {
            return ['ok' => false, 'erro' => 'Não foi possível registar a tentativa de pagamento.'];
        }
    } else {
        $metodoRegisto = $tipo === 'sinal' ? 'sinal' : 'cartao_online';
        $pdo->prepare(
            "UPDATE pagamentos
                SET consulta_id = ?, valor = ?, metodo = ?,
                    gateway = 'stripe', gateway_metodo = 'cartao_online',
                    prestador = 'stripe', estado = 'pendente',
                    falhado_em = NULL, cancelado_em = NULL,
                    cartao_marca = NULL, cartao_ultimos4 = NULL
              WHERE id = ?"
        )->execute([
            $resumo['consulta_unica_id'],
            $valor,
            $metodoRegisto,
            (int)$pag['id'],
        ]);
    }

    $pi = $gateway->criarPaymentIntent([
        'marcacao_id'  => (int)$marcacao['id'],
        'pagamento_id' => (int)$pag['id'],
        'referencia'   => (string)$marcacao['referencia'],
        'valor'        => $valor,
        'tipo'         => $tipo,
        'email'        => $email,
        'customer_id'  => $customerId,
    ]);
    if (empty($pi['ok'])) {
        return ['ok' => false, 'erro' => (string)($pi['erro'] ?? 'Não foi possível iniciar o pagamento.')];
    }

    $pdo->prepare(
        "UPDATE pagamentos
            SET referencia_transacao = ?, gateway = 'stripe', gateway_metodo = 'cartao_online',
                prestador = 'stripe', estado = 'pendente', cartao_marca = NULL, cartao_ultimos4 = NULL
          WHERE id = ?"
    )->execute([(string)$pi['payment_intent_id'], (int)$pag['id']]);

    return [
        'ok' => true,
        'estado' => (string)($pi['status'] ?? 'requires_payment_method'),
        'client_secret' => (string)$pi['client_secret'],
        'payment_intent_id' => (string)$pi['payment_intent_id'],
        'pagamento_id' => (int)$pag['id'],
    ];
}

/**
 * Pagamento mais relevante para a confirmação. Se vier um payment_intent do
 * retorno, só o aceita se pertencer a esta marcação.
 */
function checkout_pagamento_confirmacao(PDO $pdo, int $marcacaoId, string $paymentIntentId = ''): ?array
{
    $paymentIntentId = trim($paymentIntentId);
    if ($paymentIntentId !== '' && str_starts_with($paymentIntentId, 'pi_')) {
        $st = $pdo->prepare(
            "SELECT * FROM pagamentos
              WHERE marcacao_id = ? AND gateway = 'stripe' AND referencia_transacao = ?
              ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$marcacaoId, $paymentIntentId]);
        $p = $st->fetch();
        if ($p) { return $p; }
    }
    $st = $pdo->prepare(
        "SELECT * FROM pagamentos
          WHERE marcacao_id = ? AND gateway = 'stripe'
            AND metodo IN ('eletronico_antecipado', 'cartao_online', 'sinal')
          ORDER BY id DESC
          LIMIT 1"
    );
    $st->execute([$marcacaoId]);
    $p = $st->fetch();
    return $p ?: null;
}

/**
 * Devolve marca/últimos 4 dígitos, primeiro da BD e, se faltar, da API Stripe.
 * Nunca devolve nem guarda o número completo, validade ou CVC.
 */
function checkout_dados_cartao(PDO $pdo, GatewayStripe $gateway, ?array $pagamento): array
{
    if (!$pagamento) {
        return ['marca' => '', 'ultimos4' => ''];
    }
    $marca = trim((string)($pagamento['cartao_marca'] ?? ''));
    $ultimos4 = trim((string)($pagamento['cartao_ultimos4'] ?? ''));
    if ($marca !== '' && strlen($ultimos4) === 4) {
        return ['marca' => $marca, 'ultimos4' => $ultimos4];
    }

    $piId = trim((string)($pagamento['referencia_transacao'] ?? ''));
    if ($piId === '' || !str_starts_with($piId, 'pi_')) {
        return ['marca' => '', 'ultimos4' => ''];
    }
    $consulta = $gateway->obterPaymentIntent($piId, true);
    if (empty($consulta['ok']) || !is_array($consulta['payment_intent'] ?? null)) {
        return ['marca' => '', 'ultimos4' => ''];
    }
    $cartao = $gateway->dadosCartao($consulta['payment_intent']);
    if ($cartao === []) {
        return ['marca' => '', 'ultimos4' => ''];
    }
    try {
        pagamento_guardar_cartao_mascarado($pdo, (int)$pagamento['id'], (string)$cartao['marca'], (string)$cartao['ultimos4']);
    } catch (Throwable $e) {
        error_log('Checkout dados cartão: ' . $e->getMessage());
    }
    return ['marca' => (string)$cartao['marca'], 'ultimos4' => (string)$cartao['ultimos4']];
}

function checkout_url_confirmacao(PDO $pdo, string $referencia): string
{
    return gateway_base_url($pdo) . '/checkout-confirmacao.php?ref=' . urlencode($referencia);
}
