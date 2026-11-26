<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/conteudo.php';

$pdo = db();

// ---------- 1. Página de perfil individual (?ver=ID) ----------
$verId = filter_input(INPUT_GET, 'ver', FILTER_VALIDATE_INT) ?: 0;

if ($verId > 0) {
    $st = $pdo->prepare(
        'SELECT d.id, d.nome_completo, d.fotografia, d.descricao, d.cedula_profissional,
                (SELECT GROUP_CONCAT(e.nome ORDER BY e.nome SEPARATOR ", ")
                   FROM dentistas_especialidades de
                   JOIN especialidades e ON e.id = de.especialidade_id
                  WHERE de.dentista_id = d.id) AS especialidades,
                (SELECT GROUP_CONCAT(t.nome ORDER BY t.nome SEPARATOR ", ")
                   FROM dentistas_tratamentos dt
                   JOIN tratamentos t ON t.id = dt.tratamento_id
                  WHERE dt.dentista_id = d.id) AS tratamentos
           FROM dentistas d
          WHERE d.id = ? AND d.ativo = 1'
    );
    $st->execute([$verId]);
    $dentista = $st->fetch();

    if (!$dentista) {
        flash_definir('erro', 'Médico dentista não encontrado.');
        redirecionar('dentistas.php');
    }

    $tituloPagina = $dentista['nome_completo'];
    require __DIR__ . '/includes/cabecalho.php';
    ?>
    <p><a href="dentistas.php">← Voltar à equipa</a></p>

    <section class="editorial">
        <div class="editorial-media">
            <?= avatar_dentista($dentista) ?>
        </div>
        <div class="editorial-conteudo">
            <h2><?= e($dentista['nome_completo']) ?></h2>

            <?php if (!empty($dentista['cedula_profissional'])): ?>
                <p class="ajuda" style="margin-bottom:var(--esp-3);">
                    Médico dentista inscrito na Ordem dos Médicos Dentistas
                    com a cédula profissional n.º <?= e($dentista['cedula_profissional']) ?>.
                </p>
            <?php endif; ?>

            <?php if (!empty($dentista['especialidades'])): ?>
                <div class="etiquetas">
                    <span class="etiqueta"><?= e($dentista['especialidades']) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($dentista['descricao'])): ?>
                <p><?= nl2br(e($dentista['descricao'])) ?></p>
            <?php endif; ?>

            <?php if (!empty($dentista['tratamentos'])): ?>
                <p class="ajuda"><strong>Tratamentos:</strong> <?= e($dentista['tratamentos']) ?></p>
            <?php endif; ?>

            <div class="acoes">
                <a class="botao" href="marcar-pacientes.php?dentista=<?= (int)$dentista['id'] ?>">Marcar consulta →</a>
                <a class="botao botao-secundario" href="dentistas.php">Ver outros</a>
            </div>
        </div>
    </section>
    <?php
    require __DIR__ . '/includes/rodape.php';
    exit;
}

// ---------- 2. Lista (layout editorial alternado) ----------
$filtroEsp  = filter_input(INPUT_GET, 'especialidade', FILTER_VALIDATE_INT) ?: 0;
$filtroTrat = filter_input(INPUT_GET, 'tratamento',    FILTER_VALIDATE_INT) ?: 0;
$modoMarcar = ($_GET['modo'] ?? '') === 'marcar';

$especialidades = $pdo->query('SELECT id, nome FROM especialidades WHERE ativo = 1 ORDER BY nome')->fetchAll();
$tratamentos    = $pdo->query('SELECT id, nome FROM tratamentos    WHERE ativo = 1 ORDER BY nome')->fetchAll();

$sql = 'SELECT d.id, d.nome_completo, d.fotografia, d.descricao, d.cedula_profissional,
               (SELECT GROUP_CONCAT(e.nome ORDER BY e.nome SEPARATOR ", ")
                  FROM dentistas_especialidades de
                  JOIN especialidades e ON e.id = de.especialidade_id
                 WHERE de.dentista_id = d.id) AS especialidades,
               (SELECT GROUP_CONCAT(t.nome ORDER BY t.nome SEPARATOR ", ")
                  FROM dentistas_tratamentos dt
                  JOIN tratamentos t ON t.id = dt.tratamento_id
                 WHERE dt.dentista_id = d.id) AS tratamentos
          FROM dentistas d
         WHERE d.ativo = 1';

$params = [];
if ($filtroEsp > 0) {
    $sql .= ' AND EXISTS (SELECT 1 FROM dentistas_especialidades de
                          WHERE de.dentista_id = d.id AND de.especialidade_id = ?)';
    $params[] = $filtroEsp;
}
if ($filtroTrat > 0) {
    $sql .= ' AND (
        EXISTS (SELECT 1 FROM dentistas_tratamentos dt
                 WHERE dt.dentista_id = d.id AND dt.tratamento_id = ?)
        OR EXISTS (SELECT 1 FROM tratamentos tg
                    WHERE tg.id = ? AND tg.ativo = 1 AND tg.especialidade_id IS NULL)
        OR EXISTS (SELECT 1 FROM tratamentos te
                    JOIN dentistas_especialidades de2 ON de2.especialidade_id = te.especialidade_id
                   WHERE te.id = ? AND te.ativo = 1 AND te.especialidade_id IS NOT NULL
                     AND de2.dentista_id = d.id)
    )';
    $params[] = $filtroTrat;
    $params[] = $filtroTrat;
    $params[] = $filtroTrat;
}
$sql .= ' ORDER BY d.nome_completo';

$st = $pdo->prepare($sql);
$st->execute($params);
$dentistas = $st->fetchAll();

$tituloPagina = 'A nossa equipa';
require __DIR__ . '/includes/cabecalho.php';
?>

<section class="interior-hero">
    <div class="interior-hero-top">
        <div class="interior-hero-kicker">
            <span class="kicker-num">02 / 2026</span>
            <span class="kicker-line" aria-hidden="true"></span>
            <span>Quem cuida de ti</span>
        </div>
        <span class="interior-hero-aside">Equipa clínica</span>
    </div>
    <h1><?= e(conteudo('equipa_titulo', 'A nossa equipa')) ?><span class="ponto">.</span></h1>
    <div class="interior-hero-bottom">
        <p>
            Todos os profissionais estão inscritos na Ordem dos Médicos Dentistas (OMD)
            e identificados pela respetiva cédula profissional.
        </p>
        <span><?= count($dentistas) ?> profissional<?= count($dentistas) === 1 ? '' : 'is' ?> ↓</span>
    </div>
</section>

<form method="get" action="dentistas.php" class="filtros">
    <?php if ($modoMarcar): ?>
        <input type="hidden" name="modo" value="marcar">
    <?php endif; ?>

    <div class="campo">
        <label for="especialidade">Especialidade</label>
        <select id="especialidade" name="especialidade">
            <option value="0">Todas</option>
            <?php foreach ($especialidades as $esp): ?>
                <option value="<?= (int)$esp['id'] ?>" <?= $filtroEsp === (int)$esp['id'] ? 'selected' : '' ?>>
                    <?= e($esp['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="campo">
        <label for="tratamento">Tratamento</label>
        <select id="tratamento" name="tratamento">
            <option value="0">Todos</option>
            <?php foreach ($tratamentos as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $filtroTrat === (int)$t['id'] ? 'selected' : '' ?>>
                    <?= e($t['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="campo">
        <button type="submit" class="botao">Filtrar</button>
        <?php if ($filtroEsp > 0 || $filtroTrat > 0): ?>
            <a class="botao botao-secundario" href="dentistas.php<?= $modoMarcar ? '?modo=marcar' : '' ?>">Limpar</a>
        <?php endif; ?>
    </div>
</form>

<?php if (!$dentistas): ?>
    <div class="alerta alerta-info">Não há médicos dentistas que correspondam aos filtros escolhidos.</div>
<?php else: ?>
    <?php foreach ($dentistas as $d): ?>
        <article class="editorial">
            <div class="editorial-media">
                <?= avatar_dentista($d) ?>
            </div>
            <div class="editorial-conteudo">
                <h2><?= e($d['nome_completo']) ?></h2>

                <?php if (!empty($d['cedula_profissional'])): ?>
                    <p class="ajuda" style="margin-bottom:var(--esp-3);">
                        Cédula OMD n.º <?= e($d['cedula_profissional']) ?>
                    </p>
                <?php endif; ?>

                <?php if ($d['especialidades']): ?>
                    <div class="etiquetas">
                        <span class="etiqueta"><?= e($d['especialidades']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($d['descricao']): ?>
                    <p><?= e($d['descricao']) ?></p>
                <?php endif; ?>

                <?php if ($d['tratamentos']): ?>
                    <p class="ajuda"><strong>Tratamentos:</strong> <?= e($d['tratamentos']) ?></p>
                <?php endif; ?>

                <div class="acoes">
                    <?php $tratParam = $filtroTrat > 0 ? '&tratamento=' . $filtroTrat : ''; ?>
                    <?php if ($modoMarcar): ?>
                        <a class="botao" href="marcar-pacientes.php?dentista=<?= (int)$d['id'] . $tratParam ?>">Marcar com este médico dentista →</a>
                    <?php else: ?>
                        <a class="botao" href="dentistas.php?ver=<?= (int)$d['id'] ?>">Ver perfil completo</a>
                        <a class="botao botao-secundario" href="marcar-pacientes.php?dentista=<?= (int)$d['id'] . $tratParam ?>">Marcar consulta</a>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/rodape.php'; ?>