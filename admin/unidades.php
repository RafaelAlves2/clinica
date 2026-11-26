<?php
declare(strict_types=1);

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/gestao.php';

$u = exigir_login(['admin']);
$pdo = db();

$erros = [];
$form = [
    'id' => 0,
    'nome' => '',
    'descricao' => '',
    'endereco' => '',
    'codigo_postal' => '',
    'cidade' => '',
    'distrito' => '',
    'pais' => 'Portugal',
    'telefone' => '',
    'email' => '',
    'horario' => '',
    'latitude' => '',
    'longitude' => '',
    'principal' => 0,
    'ativo' => 1,
    'ordem' => 0,
];

function unidades_slug(string $texto): string
{
    $texto = trim($texto);
    if (function_exists('iconv')) {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        if ($ascii !== false) {
            $texto = $ascii;
        }
    }
    $slug = strtolower($texto);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? substr($slug, 0, 150) : 'unidade';
}

function unidades_slug_unico(PDO $pdo, string $nome, int $ignorarId = 0): string
{
    $base = unidades_slug($nome);
    $slug = $base;
    $sufixo = 2;
    $st = $pdo->prepare('SELECT id FROM unidades WHERE slug = ? AND id <> ? LIMIT 1');
    while (true) {
        $st->execute([$slug, $ignorarId]);
        if (!$st->fetchColumn()) {
            return $slug;
        }
        $slug = $base . '-' . $sufixo++;
    }
}

function unidades_decimal_form(?string $valor): ?float
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return null;
    }
    $valor = str_replace(',', '.', $valor);
    return is_numeric($valor) ? (float)$valor : null;
}

/**
 * Garante no máximo uma unidade principal e nunca permite que esteja inativa.
 * Se não existir uma unidade principal ativa, escolhe outra unidade ativa.
 */
function unidades_normalizar_principal(PDO $pdo, ?int $preferida = null, int $excluirId = 0): void
{
    $pdo->exec('UPDATE unidades SET principal = 0 WHERE ativo = 0 AND principal = 1');

    $principalId = (int)($pdo->query(
        'SELECT id FROM unidades WHERE ativo = 1 AND principal = 1 ORDER BY ordem, nome, id LIMIT 1'
    )->fetchColumn() ?: 0);

    if ($principalId > 0) {
        $st = $pdo->prepare('UPDATE unidades SET principal = 0 WHERE principal = 1 AND id <> ?');
        $st->execute([$principalId]);
        return;
    }

    if ($preferida !== null && $preferida > 0) {
        $st = $pdo->prepare('SELECT id FROM unidades WHERE id = ? AND ativo = 1 LIMIT 1');
        $st->execute([$preferida]);
        $principalId = (int)($st->fetchColumn() ?: 0);
    }

    if ($principalId <= 0) {
        $st = $pdo->prepare(
            'SELECT id FROM unidades WHERE ativo = 1 AND id <> ? ORDER BY ordem, nome, id LIMIT 1'
        );
        $st->execute([$excluirId]);
        $principalId = (int)($st->fetchColumn() ?: 0);
    }

    if ($principalId <= 0) {
        $principalId = (int)($pdo->query(
            'SELECT id FROM unidades WHERE ativo = 1 ORDER BY ordem, nome, id LIMIT 1'
        )->fetchColumn() ?: 0);
    }

    if ($principalId > 0) {
        $pdo->exec('UPDATE unidades SET principal = 0 WHERE principal = 1');
        $st = $pdo->prepare('UPDATE unidades SET principal = 1 WHERE id = ? AND ativo = 1');
        $st->execute([$principalId]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $acao = (string)($_POST['acao'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($acao === 'alternar_estado') {
        $st = $pdo->prepare('SELECT nome, ativo FROM unidades WHERE id = ?');
        $st->execute([$id]);
        $atual = $st->fetch();

        if ($atual) {
            $novoEstado = (int)!((int)$atual['ativo']);
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE unidades SET ativo = ? WHERE id = ?')->execute([$novoEstado, $id]);
                unidades_normalizar_principal(
                    $pdo,
                    $novoEstado === 1 ? $id : null,
                    $novoEstado === 0 ? $id : 0
                );
                $pdo->commit();

                registar_admin($novoEstado ? 'ativar_unidade' : 'desativar_unidade', 'unidades', $id, $atual['nome']);
                flash_definir('sucesso', $novoEstado
                    ? 'Unidade ativada e publicada no globo.'
                    : 'Unidade desativada e removida do globo público.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Erro ao alterar estado da unidade: ' . $e->getMessage());
                flash_definir('erro', 'Não foi possível alterar o estado da unidade.');
            }
        } else {
            flash_definir('erro', 'A unidade selecionada já não existe.');
        }
        redirecionar('admin/unidades.php');
    }

    if ($acao === 'eliminar') {
        $st = $pdo->prepare('SELECT nome, principal FROM unidades WHERE id = ?');
        $st->execute([$id]);
        $atual = $st->fetch();
        if ($atual) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM unidades WHERE id = ?')->execute([$id]);
                unidades_normalizar_principal($pdo);
                $pdo->commit();
                registar_admin('eliminar_unidade', 'unidades', $id, $atual['nome']);
                flash_definir('sucesso', 'Unidade eliminada. Já não aparece no globo público.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Erro ao eliminar unidade: ' . $e->getMessage());
                flash_definir('erro', 'Não foi possível eliminar a unidade.');
            }
        } else {
            flash_definir('erro', 'A unidade selecionada já não existe.');
        }
        redirecionar('admin/unidades.php');
    }

    if ($acao === 'guardar') {
        foreach (array_keys($form) as $campo) {
            if (in_array($campo, ['id', 'principal', 'ativo', 'ordem'], true)) {
                continue;
            }
            $form[$campo] = trim((string)($_POST[$campo] ?? ''));
        }
        $form['id'] = $id;
        $form['principal'] = isset($_POST['principal']) ? 1 : 0;
        $form['ativo'] = isset($_POST['ativo']) ? 1 : 0;
        $form['ordem'] = (int)($_POST['ordem'] ?? 0);

        $nome = $form['nome'];
        $endereco = $form['endereco'];
        $cidade = $form['cidade'];
        $pais = $form['pais'];
        $telefone = $form['telefone'];
        $email = $form['email'];
        $horario = $form['horario'];
        $descricao = $form['descricao'];
        $codigoPostal = $form['codigo_postal'];
        $distrito = $form['distrito'];
        $latOriginal = $form['latitude'];
        $lngOriginal = $form['longitude'];
        $lat = unidades_decimal_form($latOriginal);
        $lng = unidades_decimal_form($lngOriginal);

        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 150) {
            $erros[] = 'O nome deve ter entre 2 e 150 caracteres.';
        }
        if ($endereco === '' || mb_strlen($endereco) > 255) {
            $erros[] = 'Indica uma morada válida (máximo 255 caracteres).';
        }
        if ($cidade === '' || mb_strlen($cidade) > 100) {
            $erros[] = 'Indica a localidade da unidade (máximo 100 caracteres).';
        }
        if ($pais === '' || mb_strlen($pais) > 100) {
            $erros[] = 'Indica o país da unidade.';
        }
        if (mb_strlen($descricao) > 500 || mb_strlen($codigoPostal) > 20
            || mb_strlen($distrito) > 100 || mb_strlen($telefone) > 40
            || mb_strlen($email) > 190 || mb_strlen($horario) > 255) {
            $erros[] = 'Um dos campos ultrapassa o limite de caracteres permitido.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erros[] = 'O endereço de email não é válido.';
        }
        if ($form['principal'] === 1 && $form['ativo'] !== 1) {
            $erros[] = 'A unidade principal tem de estar ativa e publicada.';
        }
        if (($latOriginal === '') xor ($lngOriginal === '')) {
            $erros[] = 'Preenche as duas coordenadas ou deixa ambas vazias.';
        } elseif ($latOriginal !== '' && ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180)) {
            $erros[] = 'As coordenadas não são válidas. Latitude: -90 a 90; longitude: -180 a 180.';
        }
        if ($form['ordem'] < 0 || $form['ordem'] > 32767) {
            $erros[] = 'A ordem deve estar entre 0 e 32767.';
        }

        if ($erros === []) {
            try {
                $slug = unidades_slug_unico($pdo, $nome, $id);
                $pdo->beginTransaction();

                if ($form['principal'] === 1) {
                    $pdo->exec('UPDATE unidades SET principal = 0');
                }

                if ($id > 0) {
                    $st = $pdo->prepare(
                        'UPDATE unidades
                            SET nome = ?, slug = ?, descricao = ?, endereco = ?, codigo_postal = ?,
                                cidade = ?, distrito = ?, pais = ?, telefone = ?, email = ?, horario = ?,
                                latitude = ?, longitude = ?, principal = ?, ativo = ?, ordem = ?
                          WHERE id = ?'
                    );
                    $st->execute([
                        $nome, $slug, $descricao ?: null, $endereco, $codigoPostal ?: null,
                        $cidade, $distrito ?: null, $pais, $telefone ?: null, $email ?: null,
                        $horario ?: null, $lat, $lng, $form['principal'], $form['ativo'], $form['ordem'], $id,
                    ]);
                    if ($st->rowCount() === 0) {
                        $check = $pdo->prepare('SELECT 1 FROM unidades WHERE id = ?');
                        $check->execute([$id]);
                        if (!$check->fetchColumn()) {
                            throw new RuntimeException('A unidade que tentaste editar já não existe.');
                        }
                    }
                    $idGuardado = $id;
                    $acaoRegisto = 'editar_unidade';
                } else {
                    $st = $pdo->prepare(
                        'INSERT INTO unidades
                            (nome, slug, descricao, endereco, codigo_postal, cidade, distrito, pais,
                             telefone, email, horario, latitude, longitude, principal, ativo, ordem)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $st->execute([
                        $nome, $slug, $descricao ?: null, $endereco, $codigoPostal ?: null,
                        $cidade, $distrito ?: null, $pais, $telefone ?: null, $email ?: null,
                        $horario ?: null, $lat, $lng, $form['principal'], $form['ativo'], $form['ordem'],
                    ]);
                    $idGuardado = (int)$pdo->lastInsertId();
                    $acaoRegisto = 'criar_unidade';
                }

                unidades_normalizar_principal(
                    $pdo,
                    $form['principal'] === 1 ? $idGuardado : null,
                    $form['principal'] === 1 ? 0 : $idGuardado
                );
                $pdo->commit();
                registar_admin($acaoRegisto, 'unidades', $idGuardado, $nome);
                flash_definir('sucesso', $form['ativo']
                    ? 'Unidade guardada e publicada na página das unidades.'
                    : 'Unidade guardada como inativa. Não aparece no globo público.');
                redirecionar('admin/unidades.php');
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $erros[] = $e->getMessage();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Erro ao guardar unidade: ' . $e->getMessage());
                $erros[] = 'Não foi possível guardar a unidade. Verifica os dados e tenta novamente.';
            }
        }
    }
}

$editarId = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT) ?: 0;
if ($editarId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $pdo->prepare('SELECT * FROM unidades WHERE id = ?');
    $st->execute([$editarId]);
    $unidadeEditar = $st->fetch();
    if ($unidadeEditar) {
        foreach ($form as $campo => $valor) {
            if (array_key_exists($campo, $unidadeEditar)) {
                $form[$campo] = $unidadeEditar[$campo] ?? '';
            }
        }
        $form['id'] = (int)$unidadeEditar['id'];
        $form['principal'] = (int)$unidadeEditar['principal'];
        $form['ativo'] = (int)$unidadeEditar['ativo'];
        $form['ordem'] = (int)$unidadeEditar['ordem'];
    } else {
        flash_definir('erro', 'Unidade não encontrada.');
        redirecionar('admin/unidades.php');
    }
}

$unidades = $pdo->query(
    'SELECT * FROM unidades ORDER BY ativo DESC, principal DESC, ordem ASC, nome ASC'
)->fetchAll();
$totalAtivas = count(array_filter($unidades, static fn(array $unidade): bool => (int)$unidade['ativo'] === 1));
$totalComCoordenadas = count(array_filter($unidades, static fn(array $unidade): bool =>
    $unidade['latitude'] !== null && $unidade['longitude'] !== null
));

$tituloPagina = 'Gerir unidades';
$css_extra = ['css/vendor/leaflet.css', 'css/unidades.css'];
require __DIR__ . '/../includes/cabecalho.php';
?>

<div class="unidades-admin">
    <div class="unidades-admin-topo">
        <div>
            <p class="unidades-kicker">Gestão clínica</p>
            <h1>Unidades</h1>
            <p class="unidades-intro">Gere os locais da clínica. As unidades ativas com coordenadas aparecem automaticamente no globo da página pública.</p>
        </div>
        <a class="botao botao-secundario" href="admin/seccao-gestao-clinica.php">← Gestão clínica</a>
    </div>

    <div class="unidades-indicadores">
        <article><strong><?= count($unidades) ?></strong><span>Total de unidades</span></article>
        <article><strong><?= $totalAtivas ?></strong><span>Ativas e publicadas</span></article>
        <article><strong><?= $totalComCoordenadas ?></strong><span>Com localização no mapa</span></article>
    </div>

    <?php foreach ($erros as $erro): ?>
        <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
    <?php endforeach; ?>

    <section class="unidades-lista painel">
        <div class="unidades-seccao-topo">
            <div>
                <h2>Unidades registadas</h2>
                <p class="ajuda">Desativar oculta a unidade sem apagar os dados. Eliminar remove-a definitivamente.</p>
            </div>
            <a class="botao" href="admin/unidades.php#form-unidade">+ Nova unidade</a>
        </div>

        <?php if (!$unidades): ?>
            <p class="unidades-vazio">Ainda não existem unidades registadas.</p>
        <?php else: ?>
            <div class="unidades-tabela-scroll">
                <table class="tabela unidades-tabela">
                    <thead><tr><th>Unidade</th><th>Localização</th><th>Estado</th><th>Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($unidades as $unidade): ?>
                        <tr>
                            <td>
                                <strong><?= e($unidade['nome']) ?></strong>
                                <?php if ((int)$unidade['principal'] === 1): ?><span class="unidade-etiqueta principal">Principal</span><?php endif; ?>
                                <small><?= e($unidade['telefone'] ?? 'Sem telefone') ?></small>
                            </td>
                            <td>
                                <?= e($unidade['cidade']) ?>, <?= e($unidade['pais']) ?>
                                <small><?= e($unidade['endereco']) ?></small>
                                <?php if ($unidade['latitude'] === null): ?><small class="unidade-sem-coord">Sem coordenadas — não aparece no globo</small><?php endif; ?>
                            </td>
                            <td><span class="unidade-etiqueta <?= (int)$unidade['ativo'] === 1 ? 'ativa' : 'inativa' ?>"><?= (int)$unidade['ativo'] === 1 ? 'Ativa' : 'Inativa' ?></span></td>
                            <td class="unidade-acoes">
                                <a class="botao botao-secundario" href="admin/unidades.php?editar=<?= (int)$unidade['id'] ?>#form-unidade">Editar</a>
                                <form method="post" action="admin/unidades.php">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="id" value="<?= (int)$unidade['id'] ?>">
                                    <input type="hidden" name="acao" value="alternar_estado">
                                    <button class="botao botao-secundario" type="submit"><?= (int)$unidade['ativo'] === 1 ? 'Desativar' : 'Ativar' ?></button>
                                </form>
                                <form method="post" action="admin/unidades.php" onsubmit="return confirm('Eliminar definitivamente esta unidade? Esta ação não pode ser anulada.');">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="id" value="<?= (int)$unidade['id'] ?>">
                                    <input type="hidden" name="acao" value="eliminar">
                                    <button class="botao botao-perigo" type="submit">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="unidades-formulario painel" id="form-unidade">
        <div class="unidades-seccao-topo">
            <div>
                <h2><?= $form['id'] > 0 ? 'Editar unidade' : 'Adicionar unidade' ?></h2>
                <p class="ajuda">Define a morada e coloca o marcador no local exato do mapa.</p>
            </div>
            <?php if ($form['id'] > 0): ?><a class="botao botao-secundario" href="admin/unidades.php">Cancelar edição</a><?php endif; ?>
        </div>

        <form method="post" action="admin/unidades.php#form-unidade" class="unidade-form" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="guardar">
            <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">

            <div class="unidade-campos-grelha">
                <div class="campo unidade-campo-largo">
                    <label for="unidade-nome">Nome da unidade *</label>
                    <input id="unidade-nome" name="nome" required maxlength="150" value="<?= e((string)$form['nome']) ?>" placeholder="Ex.: Clínica Dentária de Faro">
                </div>
                <div class="campo unidade-campo-largo">
                    <label for="unidade-descricao">Descrição</label>
                    <textarea id="unidade-descricao" name="descricao" rows="2" maxlength="500" placeholder="Breve descrição da unidade"><?= e((string)$form['descricao']) ?></textarea>
                </div>
                <div class="campo unidade-campo-largo">
                    <label for="unidade-endereco">Morada *</label>
                    <input id="unidade-endereco" name="endereco" required maxlength="255" value="<?= e((string)$form['endereco']) ?>" placeholder="Rua, número e complemento">
                </div>
                <div class="campo">
                    <label for="unidade-codigo-postal">Código postal</label>
                    <input id="unidade-codigo-postal" name="codigo_postal" maxlength="20" value="<?= e((string)$form['codigo_postal']) ?>" placeholder="8000-000">
                </div>
                <div class="campo">
                    <label for="unidade-cidade">Localidade *</label>
                    <input id="unidade-cidade" name="cidade" required maxlength="100" value="<?= e((string)$form['cidade']) ?>" placeholder="Faro">
                </div>
                <div class="campo">
                    <label for="unidade-distrito">Distrito / região</label>
                    <input id="unidade-distrito" name="distrito" maxlength="100" value="<?= e((string)$form['distrito']) ?>">
                </div>
                <div class="campo">
                    <label for="unidade-pais">País *</label>
                    <input id="unidade-pais" name="pais" required maxlength="100" value="<?= e((string)$form['pais']) ?>">
                </div>
                <div class="campo">
                    <label for="unidade-telefone">Telefone</label>
                    <input id="unidade-telefone" name="telefone" maxlength="40" value="<?= e((string)$form['telefone']) ?>">
                </div>
                <div class="campo">
                    <label for="unidade-email">Email</label>
                    <input id="unidade-email" type="email" name="email" maxlength="190" value="<?= e((string)$form['email']) ?>">
                </div>
                <div class="campo unidade-campo-largo">
                    <label for="unidade-horario">Horário de funcionamento</label>
                    <input id="unidade-horario" name="horario" maxlength="255" value="<?= e((string)$form['horario']) ?>" placeholder="Seg–Sex: 09:00–18:00">
                </div>
            </div>

            <div class="unidade-mapa-topo">
                <div>
                    <h3>Localização no mapa</h3>
                    <p class="ajuda">Arrasta o mapa e clica na posição da unidade. Também podes arrastar o marcador.</p>
                </div>
                <a class="botao botao-secundario" id="unidade-google-maps" href="https://www.google.com/maps" target="_blank" rel="noopener">Abrir Google Maps ↗</a>
            </div>
            <div id="unidade-mapa-editor" class="unidade-mapa-editor" role="application" aria-label="Mapa para escolher a localização da unidade"></div>
            <div class="unidade-coordenadas">
                <div class="campo">
                    <label for="unidade-latitude">Latitude</label>
                    <input id="unidade-latitude" name="latitude" inputmode="decimal" value="<?= e((string)$form['latitude']) ?>" placeholder="37.0172197">
                </div>
                <div class="campo">
                    <label for="unidade-longitude">Longitude</label>
                    <input id="unidade-longitude" name="longitude" inputmode="decimal" value="<?= e((string)$form['longitude']) ?>" placeholder="-7.9359366">
                </div>
                <button class="botao botao-secundario" id="unidade-localizar-coordenadas" type="button">Atualizar marcador</button>
            </div>

            <div class="unidade-opcoes">
                <label class="unidade-check"><input type="checkbox" name="principal" value="1" <?= (int)$form['principal'] === 1 ? 'checked' : '' ?>> <span>Definir como unidade principal</span></label>
                <label class="unidade-check"><input type="checkbox" name="ativo" value="1" <?= (int)$form['ativo'] === 1 ? 'checked' : '' ?>> <span>Publicar no site e no globo</span></label>
                <div class="campo">
                    <label for="unidade-ordem">Ordem de apresentação</label>
                    <input id="unidade-ordem" type="number" name="ordem" min="0" max="32767" value="<?= (int)$form['ordem'] ?>">
                </div>
            </div>

            <div class="unidade-form-acoes">
                <button class="botao" type="submit"><?= $form['id'] > 0 ? 'Guardar alterações' : 'Adicionar unidade' ?></button>
                <a class="botao botao-secundario" href="unidades.php" target="_blank" rel="noopener">Ver página pública ↗</a>
            </div>
        </form>
    </section>
</div>

<script src="js/vendor/leaflet.js"></script>
<script src="js/admin-unidades.js"></script>
<?php require __DIR__ . '/../includes/rodape.php'; ?>
