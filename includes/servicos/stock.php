<?php
declare(strict_types=1);

function stock_alertas(PDO $pdo): int
{
    return (int)$pdo->query(
        'SELECT COUNT(*) FROM stock_materiais
          WHERE ativo = 1 AND quantidade <= stock_minimo'
    )->fetchColumn();
}

function stock_materiais_alerta(PDO $pdo): array
{
    return $pdo->query(
        'SELECT m.*, c.nome AS categoria
           FROM stock_materiais m
           LEFT JOIN stock_categorias c ON c.id = m.categoria_id
          WHERE m.ativo = 1 AND m.quantidade <= m.stock_minimo
          ORDER BY (m.quantidade = 0) DESC, m.quantidade ASC, m.nome'
    )->fetchAll();
}

function stock_estado(int $quantidade, int $minimo): string
{
    if ($quantidade <= 0) { return 'esgotado'; }
    if ($quantidade <= $minimo) { return 'baixo'; }
    return 'ok';
}

function stock_etiqueta(int $quantidade, int $minimo): string
{
    $estado = stock_estado($quantidade, $minimo);
    $texto  = ['esgotado' => 'Esgotado', 'baixo' => 'Stock baixo', 'ok' => 'OK'][$estado];
    return '<span class="estado-etiqueta estado-stock-' . $estado . '">' . e($texto) . '</span>';
}

/**
 * Aplica um movimento (assume transacao JA aberta).
 * Usado por stock_registar_movimento() e stock_receber_encomenda().
 */
function _stock_aplicar_movimento(
    PDO $pdo,
    int $materialId,
    string $tipo,
    int $quantidade,
    ?string $motivo,
    int $utilizadorId
): int {
    if (!in_array($tipo, ['entrada', 'saida', 'ajuste', 'perda'], true)) {
        throw new RuntimeException('Tipo de movimento inválido.');
    }
    if ($quantidade <= 0) {
        throw new RuntimeException('A quantidade tem de ser positiva.');
    }

    $st = $pdo->prepare('SELECT quantidade, nome FROM stock_materiais WHERE id = ? FOR UPDATE');
    $st->execute([$materialId]);
    $mat = $st->fetch();
    if (!$mat) {
        throw new RuntimeException('Material não encontrado.');
    }

    $antes = (int)$mat['quantidade'];

    if ($tipo === 'entrada') {
        $depois = $antes + $quantidade;
    } elseif ($tipo === 'ajuste') {
        $depois = $quantidade;
    } else {
        if ($antes < $quantidade) {
            throw new RuntimeException('Stock insuficiente: só existem ' . $antes . ' unidades de "' . $mat['nome'] . '".');
        }
        $depois = $antes - $quantidade;
    }

    $pdo->prepare(
        'INSERT INTO stock_movimentos
            (material_id, tipo, quantidade, quantidade_antes, quantidade_depois, motivo, utilizador_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$materialId, $tipo, $quantidade, $antes, $depois, $motivo ?: null, $utilizadorId]);

    $pdo->prepare('UPDATE stock_materiais SET quantidade = ?, atualizado_em = NOW() WHERE id = ?')
        ->execute([$depois, $materialId]);

    return $depois;
}

function stock_registar_movimento(
    PDO $pdo,
    int $materialId,
    string $tipo,
    int $quantidade,
    ?string $motivo,
    int $utilizadorId
): int {
    $pdo->beginTransaction();
    try {
        $stAtivo = $pdo->prepare('SELECT ativo FROM stock_materiais WHERE id = ? FOR UPDATE');
        $stAtivo->execute([$materialId]);
        $ativo = $stAtivo->fetchColumn();
        if ($ativo === false) {
            throw new RuntimeException('Material não encontrado.');
        }
        if (!(int)$ativo) {
            throw new RuntimeException('Este material está desativado. Reativa-o antes de registar movimentos.');
        }

        $depois = _stock_aplicar_movimento($pdo, $materialId, $tipo, $quantidade, $motivo, $utilizadorId);
        $pdo->commit();
        return $depois;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

function stock_criar_encomenda(
    PDO $pdo,
    ?string $fornecedor,
    ?string $notas,
    array $linhas,
    int $utilizadorId
): int {
    $linhas = array_filter($linhas, fn($q) => (int)$q > 0);
    if ($linhas === []) {
        throw new RuntimeException('Escolhe pelo menos um material para encomendar.');
    }

    $pdo->beginTransaction();
    try {
        for ($i = 0; $i < 10; $i++) {
            $ref = 'ENC-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $st = $pdo->prepare('SELECT 1 FROM stock_encomendas WHERE referencia = ?');
            $st->execute([$ref]);
            if (!$st->fetchColumn()) { break; }
        }

        $pdo->prepare(
            'INSERT INTO stock_encomendas (referencia, fornecedor, estado, notas, criado_por)
             VALUES (?, ?, \'pendente\', ?, ?)'
        )->execute([$ref, $fornecedor ?: null, $notas ?: null, $utilizadorId]);
        $encId = (int)$pdo->lastInsertId();

        $total = 0.0;
        $ins = $pdo->prepare(
            'INSERT INTO stock_encomenda_linhas (encomenda_id, material_id, quantidade, preco_unitario)
             VALUES (?, ?, ?, ?)'
        );

        $linhasInseridas = 0;
        foreach ($linhas as $matId => $qtd) {
            $st = $pdo->prepare('SELECT preco_unitario FROM stock_materiais WHERE id = ? AND ativo = 1');
            $st->execute([(int)$matId]);
            $preco = $st->fetchColumn();
            if ($preco === false) {
                throw new RuntimeException('Um dos materiais selecionados já não existe ou está desativado. Atualiza a página e tenta novamente.');
            }

            $preco = $preco !== null ? (float)$preco : null;
            $ins->execute([$encId, (int)$matId, (int)$qtd, $preco]);
            $linhasInseridas++;
            if ($preco !== null) { $total += $preco * (int)$qtd; }
        }

        if ($linhasInseridas === 0) {
            throw new RuntimeException('A encomenda não contém materiais válidos. Seleciona pelo menos um material ativo.');
        }

        $pdo->prepare('UPDATE stock_encomendas SET total = ? WHERE id = ?')->execute([$total, $encId]);
        $pdo->commit();
        return $encId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/**
 * Marca encomenda como recebida e da entrada em stock.
 * CORRECAO: os movimentos e o UPDATE do estado correm na MESMA transacao.
 */
function stock_receber_encomenda(PDO $pdo, int $encomendaId, int $utilizadorId): void
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, estado, referencia FROM stock_encomendas WHERE id = ? FOR UPDATE');
        $st->execute([$encomendaId]);
        $enc = $st->fetch();
        if (!$enc) { throw new RuntimeException('Encomenda não encontrada.'); }
        if ($enc['estado'] === 'recebida') { throw new RuntimeException('Esta encomenda já foi recebida.'); }
        if ($enc['estado'] === 'cancelada') { throw new RuntimeException('Esta encomenda está cancelada.'); }

        $st = $pdo->prepare('SELECT material_id, quantidade FROM stock_encomenda_linhas WHERE encomenda_id = ?');
        $st->execute([$encomendaId]);
        $linhas = $st->fetchAll();

        foreach ($linhas as $l) {
            _stock_aplicar_movimento(
                $pdo,
                (int)$l['material_id'],
                'entrada',
                (int)$l['quantidade'],
                'Receção da encomenda ' . $enc['referencia'],
                $utilizadorId
            );
        }
        $pdo->prepare("UPDATE stock_encomendas SET estado = 'recebida', recebida_em = NOW() WHERE id = ?")
            ->execute([$encomendaId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

function estilos_stock(): string
{
    return <<<'HTML'
<style>
    .estado-stock-ok        { border-color: var(--sucesso); color: var(--sucesso); }
    .estado-stock-baixo     { border-color: var(--aviso);   color: var(--aviso);   background: var(--aviso-fundo); }
    .estado-stock-esgotado  { border-color: var(--erro);    color: var(--erro);    background: var(--erro-fundo); }
    .estado-stock-pendente  { border-color: var(--info);    color: var(--info); }
    .estado-stock-enviada   { border-color: var(--primaria);color: var(--primaria);}
    .estado-stock-recebida  { border-color: var(--sucesso); color: var(--sucesso); }
    .estado-stock-cancelada { border-color: var(--erro);    color: var(--erro); }
    .stock-resumo { display:grid; grid-template-columns: repeat(auto-fill, minmax(200px,1fr)); gap: var(--esp-4); margin-bottom: var(--esp-5); }
    .stock-resumo .cartao { padding: var(--esp-4); text-align:center; }
    .stock-resumo .numero { font-size: 1.8rem; }
    .stock-filtros { display:flex; flex-wrap:wrap; gap:var(--esp-3); align-items:flex-end; margin-bottom:var(--esp-5); }
    .stock-filtros .campo { margin:0; min-width:160px; }
    .stock-tabela input[type="number"] { width: 5.5rem; }
    .stock-tabela .acoes { display:flex; gap:.3rem; flex-wrap:wrap; }
    .stock-alerta-linha { border-left:4px solid var(--erro); }
    .stock-alerta-linha.baixo { border-left-color: var(--aviso); }
</style>
HTML;
}