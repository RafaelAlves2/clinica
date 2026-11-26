<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/marcacao.php';
require __DIR__ . '/includes/pagamentos.php';
require __DIR__ . '/includes/gateway.php';

$u   = exigir_login();
$pdo = db();

$c        = carregar_marcacao($pdo, $u['id']);
$dentista = $c['dentista'];
$prefixo  = $c['prefixo'];
$r        = resumo_da_marcacao($pdo, $c);
$total    = $r['total'];

$pctSinal   = config_valor($pdo, 'sinal_percentagem', 20);
$valorSinal = round($total * $pctSinal / 100, 2);
$horasCancelar = config_valor($pdo, 'cancelamento_antecedencia_h', 24);
$limiteMin  = config_valor($pdo, 'pagamento_limite_min', 30);

$gatewayConfigurado = gateway_pagamento_disponivel($pdo);

$metodos = [
    'presencial' => [
        'ativo' => config_valor($pdo, 'pagamento_presencial_ativo', 1) === 1,
        'titulo' => 'Pagar na clínica',
        'texto' => 'A marcação fica confirmada e pagas no dia da consulta. Valor previsto: ' . formatar_preco($total) . '.',
        'valor' => $total,
        'modo' => 'presencial',
    ],
    'sinal' => [
        'ativo' => config_valor($pdo, 'pagamento_sinal_ativo', 1) === 1 && $valorSinal > 0 && (MODO_DEV || $gatewayConfigurado),
        'titulo' => 'Pagar um sinal agora (' . $pctSinal . '%)',
        'texto' => $gatewayConfigurado
            ? 'Pagas ' . formatar_preco($valorSinal) . ' agora através da Stripe; o valor restante é pago na clínica.'
            : 'Pagas ' . formatar_preco($valorSinal) . ' agora e o restante na clínica. (Pagamento em modo demonstração.)',
        'valor' => $valorSinal,
        'modo' => $gatewayConfigurado ? 'gateway_sinal' : 'demo',
    ],
    'eletronico_antecipado' => [
        'ativo' => config_valor($pdo, 'pagamento_online_ativo', 1) === 1 && !$r['estimado'] && (MODO_DEV || $gatewayConfigurado),
        'titulo' => 'Pagar tudo agora',
        'texto' => $r['estimado']
            ? 'Indisponível: o preço final de alguns tratamentos depende da avaliação clínica.'
            : 'Pagas ' . formatar_preco($total) . ' agora, por via eletrónica.' . (MODO_DEV ? ' (Pagamento em modo demonstração.)' : ' (Processado pelo gateway configurado; confirmação apenas por notificação autenticada do servidor.)'),
        'valor' => $total,
        'modo' => $gatewayConfigurado ? 'gateway' : 'demo',
    ],
];

$erros  = [];
$metodo = (string)($_POST['metodo'] ?? 'presencial');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();

    if (!isset($metodos[$metodo]) || !$metodos[$metodo]['ativo']) {
        $erros[] = 'Escolhe uma forma de pagamento disponível.';
    }
    if (!isset($_POST['aceita_condicoes'])) {
        $erros[] = 'Tens de aceitar as condições de pagamento e cancelamento para continuar.';
    }

    if ($erros === []) {
        $estado = $metodo === 'presencial' ? 'confirmada' : 'pendente';
        $modoPagamento = (string)($metodos[$metodo]['modo'] ?? 'presencial');

        try {
            $pdo->beginTransaction();

            $ref = gerar_referencia($pdo);
            $pdo->prepare(
                'INSERT INTO marcacoes (referencia, utilizador_id, estado, total_previsto, tem_preco_estimado)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$ref, $u['id'], $estado, $total, $r['estimado'] ? 1 : 0]);
            $marcacaoId = (int)$pdo->lastInsertId();

            foreach ($r['linhas'] as $l) {
                $pid = (int)$l['paciente']['id'];
                $t   = $l['totais'];

                $pdo->prepare(
                    'INSERT INTO consultas (marcacao_id, paciente_id, dentista_id, inicio, fim, duracao_min, estado, preco_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $marcacaoId, $pid, (int)$dentista['id'],
                    $l['inicio']->format('Y-m-d H:i:00'), $l['fim']->format('Y-m-d H:i:00'),
                    $t['duracao'], $estado, $t['total'],
                ]);
                $consultaId = (int)$pdo->lastInsertId();

                $insT = $pdo->prepare(
                    'INSERT INTO consultas_tratamentos (consulta_id, tratamento_id, beneficio_id, preco_original, desconto, preco_final, duracao_min, preco_estimado)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($t['linhas'] as $tr) {
                    $po = round((float)($tr['preco_original'] ?? $tr['preco']), 2);
                    $desc = round((float)($tr['desconto'] ?? 0), 2);
                    $pf = round((float)($tr['preco_final'] ?? ($po - $desc)), 2);
                    $insT->execute([
                        $consultaId,
                        (int)$tr['id'],
                        !empty($tr['beneficio_id']) ? (int)$tr['beneficio_id'] : null,
                        $po,
                        $desc,
                        $pf,
                        (int)$tr['duracao_min'],
                        $tr['tipo_preco'] === 'fixo' ? 0 : 1,
                    ]);
                }

                if ($estado === 'pendente') {
                    $up = $pdo->prepare(
                        'UPDATE horarios_ocupados
                            SET consulta_id = ?, token_reserva = NULL, expira_em = NOW() + INTERVAL ? MINUTE
                          WHERE consulta_id IS NULL AND token_reserva = ?'
                    );
                    $up->execute([$consultaId, $limiteMin, token_paciente($prefixo, $pid)]);
                } else {
                    $up = $pdo->prepare(
                        'UPDATE horarios_ocupados
                            SET consulta_id = ?, token_reserva = NULL, expira_em = NULL
                          WHERE consulta_id IS NULL AND token_reserva = ?'
                    );
                    $up->execute([$consultaId, token_paciente($prefixo, $pid)]);
                }
                if ($up->rowCount() !== count(blocos_da_consulta($pdo, $l['inicio']->format('Y-m-d H:i:00'), $t['duracao']))) {
                    throw new RuntimeException('A reserva de ' . $l['paciente']['nome_completo'] . ' expirou. Escolhe o horário outra vez.');
                }
            }

            $gatewayNome = in_array($modoPagamento, ['gateway', 'gateway_sinal'], true) ? 'stripe' : null;
            $pdo->prepare(
                "INSERT INTO pagamentos (marcacao_id, metodo, valor, estado, gateway) VALUES (?, ?, ?, 'pendente', ?)"
            )->execute([$marcacaoId, $metodo, $metodos[$metodo]['valor'], $gatewayNome]);

            if ($estado === 'confirmada') {
                criar_notificacao($pdo, $u['id'], $marcacaoId, 'confirmacao_marcacao', 'Marcação confirmada',
                    'A tua marcação ' . $ref . ' está confirmada. Consulta os detalhes na tua área pessoal.');
            } else {
                criar_notificacao($pdo, $u['id'], $marcacaoId, 'pagamento_pendente', 'Pagamento pendente',
                    'A marcação ' . $ref . ' aguarda pagamento. Tens ' . $limiteMin . ' minutos para o concluir.');
            }

            $pdo->commit();

            unset($_SESSION['marcacao']);

            if ($estado === 'confirmada') {
                flash_definir('sucesso', 'Marcação confirmada!');
                redirecionar('marcar-confirmacao.php?ref=' . urlencode($ref));
            }
            if ($modoPagamento === 'gateway_sinal') {
                redirecionar('checkout.php?ref=' . urlencode($ref) . '&tipo=sinal');
            }
            if ($modoPagamento === 'gateway') {
                redirecionar('checkout.php?ref=' . urlencode($ref));
            }
            redirecionar('marcar-pagamento-demo.php?ref=' . urlencode($ref));
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            flash_definir('erro', $e->getMessage());
            redirecionar('marcar-calendario.php');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Erro ao criar marcação: ' . $e->getMessage());
            $erros[] = 'Ocorreu um erro. Tenta novamente.';
        }
    }
}

$tituloPagina = 'Marcar: pagamento';
require __DIR__ . '/includes/cabecalho.php';
echo estilos_marcacao();
?>

<div class="dash-root">

    <div class="dash-page-top">
        <div>
            <span class="dash-kicker">Marcação · Passo 5</span>
            <h1>Pagamento</h1>
            <p>Valor total previsto: <strong><?= e(formatar_preco($total)) ?></strong>
               <?= $r['estimado'] ? '(inclui preços estimados, a confirmar após a avaliação clínica)' : '' ?></p>
        </div>
    </div>

    <?= passos_marcacao(5) ?>

    <?php foreach ($erros as $erro): ?>
        <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
    <?php endforeach; ?>

    <form method="post" action="marcar-pagamento.php" novalidate>
        <?= csrf_campo() ?>

        <fieldset class="campo">
            <legend>Forma de pagamento</legend>
            <?php foreach ($metodos as $chave => $m): if (!$m['ativo'] && !($chave === 'eletronico_antecipado' && $r['estimado'])) { continue; } ?>
                <label class="check" style="align-items:flex-start; margin-bottom:.8rem !important;">
                    <input type="radio" name="metodo" value="<?= e($chave) ?>"
                           <?= $metodo === $chave ? 'checked' : '' ?> <?= $m['ativo'] ? '' : 'disabled' ?>>
                    <span>
                        <strong><?= e($m['titulo']) ?></strong><br>
                        <span class="ajuda"><?= e($m['texto']) ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <div class="alerta alerta-info" role="note">
            <strong>Condições:</strong> podes cancelar ou pedir alteração com pelo menos <?= (int)$horasCancelar ?> horas de antecedência.
            Os reembolsos e sinais seguem as regras definidas pela clínica.
            Se escolheres pagamento eletrónico e não o concluíres em <?= (int)$limiteMin ?> minutos, a marcação é cancelada e os horários ficam livres.
        </div>

        <div class="campo campo-checkbox">
            <input type="checkbox" id="aceita_condicoes" name="aceita_condicoes" value="1" <?= isset($_POST['aceita_condicoes']) ? 'checked' : '' ?>>
            <label for="aceita_condicoes">Li e aceito as condições indicadas acima.</label>
        </div>

        <a class="botao botao-secundario" href="marcar-resumo.php">← Resumo</a>
        <button type="submit" class="botao">Confirmar marcação</button>
    </form>

</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>