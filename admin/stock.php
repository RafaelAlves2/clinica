<?php
declare(strict_types=1);
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/gestao.php';
require __DIR__ . '/../includes/stock.php';

$u   = exigir_login(['admin']);
$pdo = db();

$erros = [];
$materialForm = [
    'id' => 0, 'nome' => '', 'referencia' => '', 'categoria' => 0, 'unidade' => 'un',
    'stock_minimo' => 0, 'stock_maximo' => '', 'preco_unitario' => '',
    'fornecedor' => '', 'validade' => '', 'descricao' => '',
];

// ---------- Ações ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $acao = (string)($_POST['acao'] ?? '');

    try {
        if ($acao === 'movimento') {
            $matId = (int)($_POST['material'] ?? 0);
            $tipo  = (string)($_POST['tipo'] ?? '');
            $qtd   = (int)($_POST['quantidade'] ?? 0);
            $motivo = mb_substr(trim((string)($_POST['motivo'] ?? '')), 0, 255);

            $depois = stock_registar_movimento($pdo, $matId, $tipo, $qtd, $motivo ?: null, $u['id']);
            registar_admin('stock_movimento', 'stock_materiais', $matId, $tipo . ' ' . $qtd);
            flash_definir('sucesso', 'Movimento registado. Stock atual: ' . $depois . '.');
            redirecionar('admin/stock.php');
        }

        if ($acao === 'guardar_material') {
            $materialForm['id'] = (int)($_POST['id'] ?? 0);
            $materialForm['nome'] = trim((string)($_POST['nome'] ?? ''));
            $materialForm['referencia'] = trim((string)($_POST['referencia'] ?? ''));
            $materialForm['categoria'] = (int)($_POST['categoria'] ?? 0);
            $materialForm['unidade'] = trim((string)($_POST['unidade'] ?? 'un'));
            $minRaw = trim((string)($_POST['stock_minimo'] ?? '0'));
            $minValidado = filter_var($minRaw, FILTER_VALIDATE_INT);
            $materialForm['stock_minimo'] = $minValidado === false ? $minRaw : $minValidado;
            $materialForm['stock_maximo'] = trim((string)($_POST['stock_maximo'] ?? ''));
            $precoInput = str_replace(',', '.', trim((string)($_POST['preco_unitario'] ?? '')));
            $materialForm['preco_unitario'] = $precoInput;
            $materialForm['fornecedor'] = trim((string)($_POST['fornecedor'] ?? ''));
            $materialForm['validade'] = trim((string)($_POST['validade'] ?? ''));
            $materialForm['descricao'] = trim((string)($_POST['descricao'] ?? ''));

            $id = $materialForm['id'];
            $min = $minValidado;
            $max = $materialForm['stock_maximo'] !== '' ? filter_var($materialForm['stock_maximo'], FILTER_VALIDATE_INT) : null;
            $preco = $precoInput === '' ? null : (is_numeric($precoInput) ? (float)$precoInput : null);
            $val = $materialForm['validade'] ?: null;
            $catId = $materialForm['categoria'] > 0 ? $materialForm['categoria'] : null;

            if ($id <= 0) { $erros[] = 'Material inválido para edição.'; }
            if (mb_strlen($materialForm['nome']) < 2 || mb_strlen($materialForm['nome']) > 150) {
                $erros[] = 'O nome do material deve ter entre 2 e 150 caracteres.';
            }
            if (mb_strlen($materialForm['referencia']) > 60) { $erros[] = 'A referência é demasiado longa.'; }
            if (mb_strlen($materialForm['unidade']) < 1 || mb_strlen($materialForm['unidade']) > 20) {
                $erros[] = 'A unidade deve ter entre 1 e 20 caracteres.';
            }
            if (mb_strlen($materialForm['fornecedor']) > 120) { $erros[] = 'O fornecedor é demasiado longo.'; }
            if (mb_strlen($materialForm['descricao']) > 255) { $erros[] = 'A descrição é demasiado longa.'; }
            if ($min === false || $min < 0) {
                $erros[] = 'O stock mínimo tem de ser um número inteiro igual ou superior a zero.';
            }
            if ($materialForm['stock_maximo'] !== '' && ($max === false || $max < 0 || ($min !== false && $max < $min))) {
                $erros[] = 'O stock máximo tem de ser vazio ou igual/superior ao stock mínimo.';
            }
            if ($precoInput !== '' && (!preg_match('/^\d+(?:\.\d{1,2})?$/', $precoInput) || $preco === null || $preco > 99999999.99)) {
                $erros[] = 'Indica um preço válido, com no máximo duas casas decimais.';
            }
            if ($val !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)
                || !checkdate((int)substr($val, 5, 2), (int)substr($val, 8, 2), (int)substr($val, 0, 4)))) {
                $erros[] = 'Data de validade inválida.';
            }

            if ($catId !== null) {
                $stCat = $pdo->prepare('SELECT ativo FROM stock_categorias WHERE id = ?');
                $stCat->execute([$catId]);
                $categoriaSelecionada = $stCat->fetch(PDO::FETCH_ASSOC);
                if (!$categoriaSelecionada) {
                    $erros[] = 'A categoria selecionada não existe.';
                } elseif (!(int)$categoriaSelecionada['ativo']) {
                    $stCategoriaAtual = $pdo->prepare('SELECT categoria_id FROM stock_materiais WHERE id = ?');
                    $stCategoriaAtual->execute([$id]);
                    if ((int)$stCategoriaAtual->fetchColumn() !== $catId) {
                        $erros[] = 'Não podes atribuir uma categoria desativada a outro material.';
                    }
                }
            }

            if ($erros === []) {
                try {
                    $stExiste = $pdo->prepare('SELECT 1 FROM stock_materiais WHERE id = ?');
                    $stExiste->execute([$id]);
                    if (!$stExiste->fetchColumn()) {
                        throw new RuntimeException('O material que tentaste editar já não existe.');
                    }

                    $pdo->prepare(
                        'UPDATE stock_materiais
                            SET categoria_id = ?, referencia = ?, nome = ?, descricao = ?, unidade = ?,
                                stock_minimo = ?, stock_maximo = ?, preco_unitario = ?, fornecedor = ?,
                                validade = ?, atualizado_em = NOW()
                          WHERE id = ?'
                    )->execute([
                        $catId, $materialForm['referencia'] ?: null, $materialForm['nome'],
                        $materialForm['descricao'] ?: null, $materialForm['unidade'],
                        $min, $max, $preco, $materialForm['fornecedor'] ?: null, $val, $id,
                    ]);
                    registar_admin('stock_editar_material', 'stock_materiais', $id, $materialForm['nome']);
                    flash_definir('sucesso', 'Material atualizado. A quantidade e o histórico de movimentos foram mantidos.');
                    $visibilidadeRetorno = (string)($_POST['visibilidade'] ?? 'ativos');
                    if (!in_array($visibilidadeRetorno, ['ativos', 'inativos', 'todos'], true)) {
                        $visibilidadeRetorno = 'ativos';
                    }
                    redirecionar('admin/stock.php?visibilidade=' . rawurlencode($visibilidadeRetorno));
                } catch (RuntimeException $e) {
                    throw $e;
                } catch (PDOException $e) {
                    if ((string)$e->getCode() === '23000') {
                        $erros[] = 'A referência indicada já está a ser utilizada por outro material.';
                    } else {
                        throw $e;
                    }
                }
            }
        }

        if ($acao === 'novo_material') {
            $nome   = trim((string)($_POST['nome'] ?? ''));
            $ref    = trim((string)($_POST['referencia'] ?? ''));
            $catId  = (int)($_POST['categoria'] ?? 0) ?: null;
            $unid   = trim((string)($_POST['unidade'] ?? 'un'));
            $qtdRaw = (string)($_POST['quantidade'] ?? '0');
            $minRaw = (string)($_POST['stock_minimo'] ?? '0');
            $maxRaw = trim((string)($_POST['stock_maximo'] ?? ''));
            $qtd    = filter_var($qtdRaw, FILTER_VALIDATE_INT);
            $min    = filter_var($minRaw, FILTER_VALIDATE_INT);
            $max    = $maxRaw !== '' ? filter_var($maxRaw, FILTER_VALIDATE_INT) : null;
            $precoRaw = str_replace(',', '.', trim((string)($_POST['preco_unitario'] ?? '')));
            $preco  = $precoRaw !== '' && is_numeric($precoRaw) ? (float)$precoRaw : null;
            $forn   = trim((string)($_POST['fornecedor'] ?? ''));
            $val    = trim((string)($_POST['validade'] ?? '')) ?: null;
            $desc   = mb_substr(trim((string)($_POST['descricao'] ?? '')), 0, 255);

            if (mb_strlen($nome) < 2 || mb_strlen($nome) > 150) {
                $erros[] = 'O nome do material deve ter entre 2 e 150 caracteres.';
            }
            if ($ref !== '' && mb_strlen($ref) > 60) {
                $erros[] = 'A referência é demasiado longa.';
            }
            if (mb_strlen($unid) < 1 || mb_strlen($unid) > 20) {
                $erros[] = 'A unidade deve ter entre 1 e 20 caracteres.';
            }
            if (mb_strlen($forn) > 120) { $erros[] = 'O fornecedor é demasiado longo.'; }
            if ($qtd === false || $qtd < 0) { $erros[] = 'A quantidade inicial tem de ser igual ou superior a zero.'; }
            if ($min === false || $min < 0) { $erros[] = 'O stock mínimo tem de ser igual ou superior a zero.'; }
            if ($maxRaw !== '' && ($max === false || $max < 0 || ($min !== false && $max < $min))) {
                $erros[] = 'O stock máximo tem de ser vazio ou igual/superior ao stock mínimo.';
            }
            if ($precoRaw !== '' && (!preg_match('/^\d+(?:\.\d{1,2})?$/', $precoRaw) || $preco === null || $preco > 99999999.99)) {
                $erros[] = 'Indica um preço válido, com no máximo duas casas decimais.';
            }
            if ($val !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)
                || !checkdate((int)substr($val, 5, 2), (int)substr($val, 8, 2), (int)substr($val, 0, 4)))) {
                $erros[] = 'Data de validade inválida.';
            }
            if ($catId !== null) {
                $stCat = $pdo->prepare('SELECT ativo FROM stock_categorias WHERE id = ?');
                $stCat->execute([$catId]);
                $categoriaAtiva = $stCat->fetchColumn();
                if ($categoriaAtiva === false || !(int)$categoriaAtiva) {
                    $erros[] = 'Seleciona uma categoria ativa.';
                }
            }

            if ($erros === []) {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare(
                        'INSERT INTO stock_materiais
                            (categoria_id, referencia, nome, descricao, unidade, quantidade, stock_minimo, stock_maximo,
                             preco_unitario, fornecedor, validade)
                         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)'
                    )->execute([
                        $catId, $ref ?: null, $nome, $desc ?: null, $unid,
                        $min, $max, $preco, $forn ?: null, $val,
                    ]);
                    $novoId = (int)$pdo->lastInsertId();

                    // A quantidade inicial também fica registada no histórico de movimentos.
                    if ($qtd > 0) {
                        _stock_aplicar_movimento($pdo, $novoId, 'entrada', $qtd, 'Stock inicial', (int)$u['id']);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    throw $e;
                }

                registar_admin('stock_novo_material', 'stock_materiais', $novoId, $nome);
                flash_definir('sucesso', 'Material criado. A quantidade inicial ficou registada no histórico.');
                redirecionar('admin/stock.php');
            }
        }

        if ($acao === 'alternar_ativo') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare('UPDATE stock_materiais SET ativo = 1 - ativo WHERE id = ?')->execute([$id]);
            registar_admin('stock_alternar_ativo', 'stock_materiais', $id);
            flash_definir('sucesso', 'Estado do material atualizado.');
            redirecionar('admin/stock.php');
        }
    } catch (RuntimeException $e) {
        $erros[] = $e->getMessage();
    } catch (PDOException $e) {
        error_log('Erro stock: ' . $e->getMessage());
        $erros[] = 'Ocorreu um erro. Tenta novamente.';
    }
}

// ---------- Filtros ----------
$q         = trim((string)($_GET['q'] ?? ''));
$catFiltro = (int)($_GET['categoria'] ?? 0);
$estFiltro = (string)($_GET['estado'] ?? 'todos');
if (!in_array($estFiltro, ['todos', 'baixo', 'esgotado', 'ok'], true)) { $estFiltro = 'todos'; }
$visibilidade = (string)($_GET['visibilidade'] ?? $_POST['visibilidade'] ?? 'ativos');
if (!in_array($visibilidade, ['ativos', 'inativos', 'todos'], true)) { $visibilidade = 'ativos'; }

$where  = ['1=1'];
$params = [];
if ($visibilidade === 'ativos') { $where[] = 'm.ativo = 1'; }
if ($visibilidade === 'inativos') { $where[] = 'm.ativo = 0'; }

if ($q !== '') {
    $where[] = '(m.nome LIKE ? OR m.referencia LIKE ? OR m.fornecedor LIKE ?)';
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($catFiltro > 0) {
    $where[] = 'm.categoria_id = ?';
    $params[] = $catFiltro;
}
if ($estFiltro === 'esgotado') { $where[] = 'm.quantidade <= 0'; }
if ($estFiltro === 'baixo')    { $where[] = 'm.quantidade > 0 AND m.quantidade <= m.stock_minimo'; }
if ($estFiltro === 'ok')       { $where[] = 'm.quantidade > m.stock_minimo'; }

$sql = 'SELECT m.*, c.nome AS categoria
          FROM stock_materiais m
          LEFT JOIN stock_categorias c ON c.id = m.categoria_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY (m.quantidade = 0) DESC, (m.quantidade <= m.stock_minimo) DESC, m.nome';
$st = $pdo->prepare($sql);
$st->execute($params);
$materiais = $st->fetchAll();

$categorias = $pdo->query('SELECT id, nome, ativo FROM stock_categorias ORDER BY ativo DESC, nome')->fetchAll();

$editarId = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT) ?: 0;
if ($editarId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stEditar = $pdo->prepare(
        'SELECT id, nome, referencia, categoria_id, unidade, stock_minimo, stock_maximo,
                preco_unitario, fornecedor, validade, descricao
           FROM stock_materiais WHERE id = ?'
    );
    $stEditar->execute([$editarId]);
    if ($mEditar = $stEditar->fetch(PDO::FETCH_ASSOC)) {
        $materialForm = [
            'id' => (int)$mEditar['id'],
            'nome' => $mEditar['nome'],
            'referencia' => (string)($mEditar['referencia'] ?? ''),
            'categoria' => (int)($mEditar['categoria_id'] ?? 0),
            'unidade' => $mEditar['unidade'],
            'stock_minimo' => (int)$mEditar['stock_minimo'],
            'stock_maximo' => $mEditar['stock_maximo'] !== null ? (string)$mEditar['stock_maximo'] : '',
            'preco_unitario' => $mEditar['preco_unitario'] !== null ? (string)$mEditar['preco_unitario'] : '',
            'fornecedor' => (string)($mEditar['fornecedor'] ?? ''),
            'validade' => (string)($mEditar['validade'] ?? ''),
            'descricao' => (string)($mEditar['descricao'] ?? ''),
        ];
    } else {
        flash_definir('erro', 'Material não encontrado.');
        redirecionar('admin/stock.php');
    }
}

$alertas = stock_materiais_alerta($pdo);

$tituloPagina = 'Stock de materiais';
require __DIR__ . '/../includes/cabecalho.php';
echo estilos_gestao();
echo estilos_stock();
?>

<p><a href="admin/index.php">← Painel</a></p>
<h1>Stock de materiais</h1>

<div class="stock-resumo">
    <div class="cartao"><p class="numero"><?= count($materiais) ?></p><p class="ajuda">Materiais listados</p></div>
    <div class="cartao"><p class="numero"><?= count(array_filter($alertas, fn($a) => (int)$a['quantidade'] > 0)) ?></p><p class="ajuda">Com stock baixo</p></div>
    <div class="cartao"><p class="numero"><?= count(array_filter($alertas, fn($a) => (int)$a['quantidade'] === 0)) ?></p><p class="ajuda">Esgotados</p></div>
    <div class="cartao"><p class="numero"><?= count($alertas) ?></p><p class="ajuda">Alertas totais</p></div>
</div>

<?php if ($alertas): ?>
    <section class="cartao seccao">
        <h2>⚠ Alertas de stock</h2>
        <div class="grelha grelha-larga">
            <?php foreach ($alertas as $a): ?>
                <article class="cartao stock-alerta-linha <?= (int)$a['quantidade'] > 0 ? 'baixo' : '' ?>">
                    <strong><?= e($a['nome']) ?></strong>
                    <span class="ajuda"><?= e($a['categoria'] ?? 'Sem categoria') ?> · <?= e($a['unidade']) ?></span>
                    <span>
                        <?= stock_etiqueta((int)$a['quantidade'], (int)$a['stock_minimo']) ?>
                        Restam <strong><?= (int)$a['quantidade'] ?></strong>
                        (mínimo: <?= (int)$a['stock_minimo'] ?>)
                    </span>
                    <form method="post" action="admin/stock.php" class="acoes" style="margin-top:.5rem;">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="acao" value="movimento">
                        <input type="hidden" name="material" value="<?= (int)$a['id'] ?>">
                        <input type="hidden" name="tipo" value="entrada">
                        <input type="hidden" name="motivo" value="Reposição rápida">
                        <label class="ajuda" for="q-<?= (int)$a['id'] ?>">Repor</label>
                        <input type="number" id="q-<?= (int)$a['id'] ?>" name="quantidade" min="1" value="<?= max(1, (int)$a['stock_minimo']) ?>" required>
                        <button type="submit" class="botao botao-secundario">Entrada</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
        <p><a class="botao" href="admin/stock-encomendas.php?novo=1">Criar encomenda de reposição →</a></p>
    </section>
<?php endif; ?>

<?php foreach ($erros as $erro): ?>
    <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
<?php endforeach; ?>

<form method="get" action="admin/stock.php" class="stock-filtros">
    <div class="campo">
        <label for="q">Procurar</label>
        <input type="text" id="q" name="q" maxlength="100" value="<?= e($q) ?>" placeholder="nome, referência, fornecedor">
    </div>
    <div class="campo">
        <label for="visibilidade">Estado do material</label>
        <select id="visibilidade" name="visibilidade">
            <option value="ativos" <?= $visibilidade === 'ativos' ? 'selected' : '' ?>>Ativos</option>
            <option value="inativos" <?= $visibilidade === 'inativos' ? 'selected' : '' ?>>Desativados</option>
            <option value="todos" <?= $visibilidade === 'todos' ? 'selected' : '' ?>>Todos</option>
        </select>
    </div>
    <div class="campo">
        <label for="categoria">Categoria</label>
        <select id="categoria" name="categoria">
            <option value="0">Todas</option>
            <?php foreach ($categorias as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $catFiltro === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="campo">
        <label for="estado">Estado</label>
        <select id="estado" name="estado">
            <?php foreach (['todos' => 'Todos', 'baixo' => 'Stock baixo', 'esgotado' => 'Esgotados', 'ok' => 'OK'] as $v => $t): ?>
                <option value="<?= e($v) ?>" <?= $estFiltro === $v ? 'selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="campo"><button type="submit" class="botao">Filtrar</button></div>
</form>

<?php if (!$materiais): ?>
    <div class="alerta alerta-info">Não há materiais com estes filtros.</div>
<?php else: ?>
    <div class="tabela-scroll">
        <table class="tabela stock-tabela">
            <thead>
                <tr><th>Material</th><th>Categoria</th><th>Quantidade</th><th>Mínimo</th><th>Stock</th><th>Movimento</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php foreach ($materiais as $m): ?>
                <tr>
                    <td>
                        <strong><?= e($m['nome']) ?></strong>
                        <?php if (!(int)$m['ativo']): ?><br><span class="estado-inativo">Material desativado</span><?php endif; ?>
                        <?php if ($m['referencia']): ?><br><span class="ajuda">Ref. <?= e($m['referencia']) ?></span><?php endif; ?>
                        <br><span class="ajuda">Unid.: <?= e($m['unidade']) ?><?= $m['fornecedor'] ? ' · ' . e($m['fornecedor']) : '' ?></span>
                    </td>
                    <td><?= e($m['categoria'] ?? '—') ?></td>
                    <td><strong><?= (int)$m['quantidade'] ?></strong></td>
                    <td><?= (int)$m['stock_minimo'] ?></td>
                    <td><?= stock_etiqueta((int)$m['quantidade'], (int)$m['stock_minimo']) ?></td>
                    <td>
                        <?php if ((int)$m['ativo']): ?>
                            <form method="post" action="admin/stock.php" class="form-inline" style="gap:.3rem;">
                                <?= csrf_campo() ?>
                                <input type="hidden" name="acao" value="movimento">
                                <input type="hidden" name="material" value="<?= (int)$m['id'] ?>">
                                <select name="tipo" aria-label="Tipo">
                                    <option value="entrada">Entrada</option>
                                    <option value="saida">Saída</option>
                                    <option value="perda">Perda</option>
                                    <option value="ajuste">Ajuste</option>
                                </select>
                                <input type="number" name="quantidade" min="1" placeholder="qtd" required>
                                <button type="submit" class="botao botao-secundario">Registar</button>
                            </form>
                        <?php else: ?>
                            <span class="ajuda">Reativa o material para registar movimentos.</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="botao botao-secundario" href="admin/stock.php?editar=<?= (int)$m['id'] ?>&amp;visibilidade=<?= e($visibilidade) ?>#form-material">Editar</a>
                        <a class="botao botao-secundario" href="admin/stock-movimentos.php?material=<?= (int)$m['id'] ?>">Histórico</a>
                        <form method="post" action="admin/stock.php" class="form-inline">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="acao" value="alternar_ativo">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button type="submit" class="botao botao-secundario"
                                    title="<?= (int)$m['ativo'] ? 'Desativar sem apagar o histórico' : 'Reativar este material' ?>"
                                    onclick="return confirm('<?= (int)$m['ativo'] ? 'Desativar' : 'Reativar' ?> este material?');">
                                <?= (int)$m['ativo'] ? 'Desativar' : 'Reativar' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<section class="cartao seccao" id="form-material">
    <h2><?= $materialForm['id'] > 0 ? 'Editar material' : 'Novo material' ?></h2>
    <?php if ($materialForm['id'] > 0): ?>
        <p class="ajuda">A quantidade atual não é alterada neste formulário, para preservar o histórico. Usa os movimentos de entrada, saída, perda ou ajuste.</p>
    <?php endif; ?>
    <form method="post" action="admin/stock.php#form-material" novalidate>
        <?= csrf_campo() ?>
        <input type="hidden" name="visibilidade" value="<?= e($visibilidade) ?>">
        <input type="hidden" name="acao" value="<?= $materialForm['id'] > 0 ? 'guardar_material' : 'novo_material' ?>">
        <input type="hidden" name="id" value="<?= (int)$materialForm['id'] ?>">
        <div class="campo">
            <label for="nome">Nome <span class="obrigatorio">*</span></label>
            <input type="text" id="nome" name="nome" required maxlength="150" value="<?= e($materialForm['nome']) ?>">
        </div>
        <div class="campo">
            <label for="referencia">Referência (SKU)</label>
            <input type="text" id="referencia" name="referencia" maxlength="60" value="<?= e($materialForm['referencia']) ?>">
        </div>
        <div class="campo">
            <label for="categoria-material">Categoria</label>
            <select id="categoria-material" name="categoria">
                <option value="0">— nenhuma —</option>
                <?php foreach ($categorias as $c): ?>
                    <?php if (!(int)$c['ativo'] && $materialForm['categoria'] !== (int)$c['id']) { continue; } ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $materialForm['categoria'] === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= e($c['nome']) ?><?= !(int)$c['ativo'] ? ' (desativada)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label for="unidade">Unidade</label>
            <input type="text" id="unidade" name="unidade" maxlength="20" value="<?= e($materialForm['unidade']) ?>">
        </div>
        <?php if ($materialForm['id'] === 0): ?>
            <div class="campo">
                <label for="quantidade">Quantidade inicial</label>
                <input type="number" id="quantidade" name="quantidade" min="0" value="0">
            </div>
        <?php endif; ?>
        <div class="campo">
            <label for="stock_minimo">Stock mínimo</label>
            <input type="number" id="stock_minimo" name="stock_minimo" min="0" step="1" value="<?= e((string)$materialForm['stock_minimo']) ?>">
        </div>
        <div class="campo">
            <label for="stock_maximo">Stock máximo (opcional)</label>
            <input type="number" id="stock_maximo" name="stock_maximo" min="0" value="<?= e($materialForm['stock_maximo']) ?>">
        </div>
        <div class="campo">
            <label for="preco_unitario">Preço unitário (€)</label>
            <input type="text" id="preco_unitario" name="preco_unitario" maxlength="10" placeholder="ex.: 8.50" value="<?= e($materialForm['preco_unitario']) ?>">
        </div>
        <div class="campo">
            <label for="fornecedor">Fornecedor</label>
            <input type="text" id="fornecedor" name="fornecedor" maxlength="120" value="<?= e($materialForm['fornecedor']) ?>">
        </div>
        <div class="campo">
            <label for="validade">Validade (opcional)</label>
            <input type="date" id="validade" name="validade" value="<?= e($materialForm['validade']) ?>">
        </div>
        <div class="campo">
            <label for="descricao">Descrição (opcional)</label>
            <input type="text" id="descricao" name="descricao" maxlength="255" value="<?= e($materialForm['descricao']) ?>">
        </div>
        <button type="submit" class="botao"><?= $materialForm['id'] > 0 ? 'Guardar alterações' : 'Criar material' ?></button>
        <?php if ($materialForm['id'] > 0): ?>
            <a class="botao botao-secundario" href="admin/stock.php">Cancelar edição</a>
        <?php endif; ?>
    </form>
</section>

<?php require __DIR__ . '/../includes/rodape.php'; ?>