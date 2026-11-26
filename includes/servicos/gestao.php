<?php
declare(strict_types=1);

function dentista_do_utilizador(PDO $pdo, int $utilizadorId): ?int
{
    $st = $pdo->prepare('SELECT id FROM dentistas WHERE utilizador_id = ? AND ativo = 1');
    $st->execute([$utilizadorId]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

function por_pagar(PDO $pdo, int $marcacaoId): float
{
    $st = $pdo->prepare('SELECT total_previsto FROM marcacoes WHERE id = ?');
    $st->execute([$marcacaoId]);
    $total = (float)$st->fetchColumn();

    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(GREATEST(0, valor - valor_reembolsado)), 0) FROM pagamentos
          WHERE marcacao_id = ? AND estado IN ('pago','reembolsado','parcialmente_reembolsado')"
    );
    $st->execute([$marcacaoId]);
    return max(0.0, round($total - (float)$st->fetchColumn(), 2));
}

function recalcular_estado_marcacao(PDO $pdo, int $marcacaoId): void
{
    $st = $pdo->prepare(
        "SELECT SUM(estado IN ('pendente','confirmada')) AS ativas, SUM(estado = 'concluida') AS concluidas
           FROM consultas WHERE marcacao_id = ?"
    );
    $st->execute([$marcacaoId]);
    $r = $st->fetch();
    if ((int)$r['ativas'] === 0 && (int)$r['concluidas'] > 0) {
        $pdo->prepare("UPDATE marcacoes SET estado = 'concluida' WHERE id = ? AND estado <> 'cancelada'")->execute([$marcacaoId]);
    }
}

function concluir_consulta(PDO $pdo, int $consultaId, ?int $dentistaRestrito): void
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, marcacao_id, dentista_id, inicio, estado FROM consultas WHERE id = ? FOR UPDATE');
        $st->execute([$consultaId]);
        $c = $st->fetch();

        if (!$c || ($dentistaRestrito !== null && (int)$c['dentista_id'] !== $dentistaRestrito)) {
            throw new RuntimeException('Consulta não encontrada.');
        }
        if ($c['estado'] !== 'confirmada') {
            throw new RuntimeException('Só se podem concluir consultas confirmadas.');
        }
        if (utc($c['inicio']) > agora_bd($pdo)) {
            throw new RuntimeException('Esta consulta ainda não começou.');
        }

        $pdo->prepare("UPDATE consultas SET estado = 'concluida' WHERE id = ?")->execute([$consultaId]);
        recalcular_estado_marcacao($pdo, (int)$c['marcacao_id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

function registar_pagamento_clinica(PDO $pdo, int $marcacaoId, float $valor, int $funcionarioId): float
{
    if (function_exists('registar_pagamento_presencial_detalhado')) {
        $r = registar_pagamento_presencial_detalhado($pdo, [
            'marcacao_id' => $marcacaoId,
            'valor' => $valor,
            'metodo_detalhe' => 'dinheiro',
            'staff_id' => $funcionarioId,
            'observacoes' => 'Registo via compatibilidade (método não especificado)',
        ]);
        return (float)$r['resto'];
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, estado, utilizador_id, referencia FROM marcacoes WHERE id = ? FOR UPDATE');
        $st->execute([$marcacaoId]);
        $m = $st->fetch();
        if (!$m || !in_array($m['estado'], ['confirmada', 'concluida'], true)) {
            throw new RuntimeException('Só se registam pagamentos em marcações confirmadas ou concluídas.');
        }
        $falta = por_pagar($pdo, $marcacaoId);
        $valor = round($valor, 2);
        if ($falta <= 0) { throw new RuntimeException('Esta marcação já está toda paga.'); }
        if ($valor <= 0 || $valor > $falta) { throw new RuntimeException('O valor tem de ser entre 0,01 € e ' . formatar_preco($falta) . '.'); }
        $referencia = 'CLINICA-' . strtoupper(bin2hex(random_bytes(4)));
        $st = $pdo->prepare("SELECT id FROM pagamentos WHERE marcacao_id = ? AND metodo = 'presencial' AND estado = 'pendente' ORDER BY id LIMIT 1 FOR UPDATE");
        $st->execute([$marcacaoId]);
        $pendente = $st->fetchColumn();
        if ($pendente) {
            $pdo->prepare("UPDATE pagamentos SET valor = ?, estado = 'pago', prestador = 'clinica', referencia_transacao = ?, pago_em = NOW(), data_pagamento=NOW(), registado_por=? WHERE id = ?")
                ->execute([$valor, $referencia, $funcionarioId, $pendente]);
        } else {
            $pdo->prepare("INSERT INTO pagamentos (marcacao_id, metodo, valor, estado, prestador, referencia_transacao, pago_em, data_pagamento, registado_por) VALUES (?, 'presencial', ?, 'pago', 'clinica', ?, NOW(), NOW(), ?)")
                ->execute([$marcacaoId, $valor, $referencia, $funcionarioId]);
        }
        $pdo->prepare("DELETE FROM pagamentos WHERE marcacao_id = ? AND metodo = 'presencial' AND estado = 'pendente'")->execute([$marcacaoId]);
        $resto = round($falta - $valor, 2);
        if ($resto > 0) {
            $pdo->prepare("INSERT INTO pagamentos (marcacao_id, metodo, valor, estado) VALUES (?, 'presencial', ?, 'pendente')")->execute([$marcacaoId, $resto]);
        }
        criar_notificacao($pdo, (int)$m['utilizador_id'], $marcacaoId, 'pagamento_confirmado', 'Pagamento recebido',
            'Recebemos ' . formatar_preco($valor) . ' da marcação ' . $m['referencia'] . '.' . ($resto > 0 ? ' Falta pagar ' . formatar_preco($resto) . '.' : ' Está tudo pago.'));
        registar_admin('registar_pagamento', 'marcacoes', $marcacaoId, formatar_preco($valor));
        $pdo->commit();
        return $resto;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

function aprovar_pedido(PDO $pdo, int $pedidoId, int $funcionarioId, string $resposta): void
{
    $pdo->beginTransaction();
    try {
        limpar_reservas_expiradas($pdo);

        $st = $pdo->prepare(
            "SELECT pa.id, pa.consulta_id, pa.novo_inicio,
                    c.dentista_id, c.duracao_min, c.estado AS consulta_estado,
                    m.id AS marcacao_id, m.referencia, m.utilizador_id AS dono
               FROM pedidos_alteracao pa
               JOIN consultas c ON c.id = pa.consulta_id
               JOIN marcacoes m ON m.id = c.marcacao_id
              WHERE pa.id = ? AND pa.estado = 'pendente' FOR UPDATE"
        );
        $st->execute([$pedidoId]);
        $p = $st->fetch();
        if (!$p) {
            throw new RuntimeException('Este pedido já foi tratado.');
        }
        if (!in_array($p['consulta_estado'], ['pendente', 'confirmada'], true)) {
            throw new RuntimeException('A consulta já não está ativa.');
        }

        $pdo->prepare('DELETE FROM horarios_ocupados WHERE consulta_id = ?')->execute([$p['consulta_id']]);

        $novo   = $p['novo_inicio'];
        $chaves = blocos_da_consulta($pdo, $novo, (int)$p['duracao_min']);
        $slots  = slots_do_dia($pdo, (int)$p['dentista_id'], utc(substr($novo, 0, 10) . ' 00:00:00'), '-sem-sessao-');
        if (!inicio_valido($slots, $chaves, '-sem-sessao-')) {
            throw new RuntimeException('O novo horário já não está disponível. Recusa o pedido ou pede ao paciente outra hora.');
        }

        $ins = $pdo->prepare('INSERT INTO horarios_ocupados (dentista_id, inicio_bloco, consulta_id) VALUES (?, ?, ?)');
        foreach ($chaves as $k) {
            $ins->execute([$p['dentista_id'], $k, $p['consulta_id']]);
        }

        $fim = utc($novo)->modify('+' . (int)$p['duracao_min'] . ' minutes')->format('Y-m-d H:i:00');
        $pdo->prepare('UPDATE consultas SET inicio = ?, fim = ? WHERE id = ?')->execute([$novo, $fim, $p['consulta_id']]);
        $pdo->prepare("UPDATE pedidos_alteracao SET estado = 'aprovado', decidido_por = ?, decidido_em = NOW() WHERE id = ?")
            ->execute([$funcionarioId, $pedidoId]);

        $quando = utc($novo);
        criar_notificacao($pdo, (int)$p['dono'], (int)$p['marcacao_id'], 'alteracao_data', 'Alteração aprovada',
            'A consulta da marcação ' . $p['referencia'] . ' passou para ' . data_extenso($quando) . ' às ' . $quando->format('H:i') . '.'
            . ($resposta !== '' ? ' ' . $resposta : ''));

        registar_admin('aprovar_alteracao', 'pedidos_alteracao', $pedidoId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

function recusar_pedido(PDO $pdo, int $pedidoId, int $funcionarioId, string $resposta): void
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            "SELECT pa.id, m.id AS marcacao_id, m.referencia, m.utilizador_id AS dono
               FROM pedidos_alteracao pa
               JOIN consultas c ON c.id = pa.consulta_id
               JOIN marcacoes m ON m.id = c.marcacao_id
              WHERE pa.id = ? AND pa.estado = 'pendente' FOR UPDATE"
        );
        $st->execute([$pedidoId]);
        $p = $st->fetch();
        if (!$p) {
            throw new RuntimeException('Este pedido já foi tratado.');
        }
        $pdo->prepare("UPDATE pedidos_alteracao SET estado = 'recusado', decidido_por = ?, decidido_em = NOW() WHERE id = ?")
            ->execute([$funcionarioId, $pedidoId]);
        criar_notificacao($pdo, (int)$p['dono'], (int)$p['marcacao_id'], 'alteracao_data', 'Alteração recusada',
            'Não foi possível alterar a consulta da marcação ' . $p['referencia'] . '; mantém-se na data original.'
            . ($resposta !== '' ? ' ' . $resposta : ''));
        registar_admin('recusar_alteracao', 'pedidos_alteracao', $pedidoId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/**
 * Pendências administrativas com a respetiva origem.
 *
 * Fonte única de verdade para o badge do menu e para o painel de ações
 * pendentes. Assim, o número apresentado pode ser sempre explicado.
 *
 * @return array<string, array{rotulo: string, valor: int, url: string}>
 */
function pendencias_admin(PDO $pdo, array $u): array
{
    $papel = (string)($u['papel'] ?? '');
    if (!in_array($papel, ['admin', 'rececao'], true)) {
        return [];
    }

    $itens = [
        'mensagens' => [
            'rotulo' => 'Mensagens por ler',
            'valor'  => (int)$pdo->query("SELECT COUNT(*) FROM mensagens_contacto WHERE lida = 0")->fetchColumn(),
            'url'    => 'admin/contactos.php',
        ],
        'pedidos' => [
            'rotulo' => 'Pedidos de alteração',
            'valor'  => (int)$pdo->query("SELECT COUNT(*) FROM pedidos_alteracao WHERE estado = 'pendente'")->fetchColumn(),
            'url'    => 'admin/pedidos.php',
        ],
    ];

    if ($papel === 'admin') {
        $itens['avaliacoes'] = [
            'rotulo' => 'Avaliações por moderar',
            'valor'  => (int)$pdo->query("SELECT COUNT(*) FROM avaliacoes WHERE estado = 'pendente'")->fetchColumn(),
            'url'    => 'admin/avaliacoes.php',
        ];
        $itens['stock'] = [
            'rotulo' => 'Alertas de stock',
            'valor'  => (int)$pdo->query("SELECT COUNT(*) FROM stock_materiais WHERE ativo = 1 AND quantidade <= stock_minimo")->fetchColumn(),
            'url'    => 'admin/stock.php',
        ];
    }

    return $itens;
}

function badge_admin(PDO $pdo, array $u): int
{
    $total = 0;
    foreach (pendencias_admin($pdo, $u) as $item) {
        $total += (int)$item['valor'];
    }
    return $total;
}

function estilos_gestao(): string
{
    return <<<'HTML'
<style>
    .separadores { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; }
    .filtros-gestao { display: flex; flex-wrap: wrap; gap: .8rem; align-items: flex-end; margin-bottom: 1.5rem; }
    .filtros-gestao .campo { margin: 0; }
    .filtros-gestao input[type="date"], .campo input[type="number"] {
        padding: .55rem .7rem; font-size: 1rem; font-family: inherit;
        border: 1px solid var(--borda); border-radius: 8px; background: var(--fundo); color: var(--texto);
    }
    .linha-consulta { display: grid; gap: .2rem; }
    .linha-consulta .acoes { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-top: .5rem; }
    .linha-consulta .acoes form { display: inline-flex; gap: .4rem; align-items: center; }
    .linha-consulta input[type="number"] { width: 7rem; }
    .estado-etiqueta { display: inline-block; padding: .1rem .6rem; border-radius: 999px; border: 1px solid var(--borda); font-size: .85rem; }
    .estado-confirmada { border-color: var(--sucesso); color: var(--sucesso); }
    .estado-pendente { border-color: var(--info); color: var(--info); }
    .estado-cancelada { border-color: var(--erro); color: var(--erro); }
    .estado-concluida { border-color: var(--texto-suave); color: var(--texto-suave); }
</style>
HTML;
}