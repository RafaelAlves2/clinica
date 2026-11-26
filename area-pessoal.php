<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/marcacao.php';
require __DIR__ . '/includes/conta.php';

$u   = exigir_login();
$pdo = db();

limpar_reservas_expiradas($pdo);
$agora = agora_bd($pdo);

// Notificações
$naoLidas = nao_lidas($pdo, $u['id']);

// Próximas consultas do utilizador
$st = $pdo->prepare(
    "SELECT c.id, c.inicio, c.fim, c.duracao_min, c.estado, c.preco_total,
            p.nome_completo AS paciente, d.nome_completo AS dentista,
            m.referencia
       FROM consultas c
       JOIN marcacoes m ON m.id = c.marcacao_id
       JOIN pacientes p ON p.id = c.paciente_id
       JOIN dentistas d ON d.id = c.dentista_id
      WHERE m.utilizador_id = ?
        AND c.estado IN ('pendente','confirmada')
        AND c.inicio >= ?
      ORDER BY c.inicio
      LIMIT 5"
);
$st->execute([$u['id'], $agora->format('Y-m-d H:i:s')]);
$proximas = $st->fetchAll();

// Contagem de marcações ativas/passadas
$st = $pdo->prepare(
    "SELECT
        SUM(c.estado IN ('pendente','confirmada') AND c.inicio >= :agora) AS ativas,
        SUM(c.estado = 'concluida') AS concluidas,
        SUM(c.estado = 'cancelada') AS canceladas
       FROM consultas c
       JOIN marcacoes m ON m.id = c.marcacao_id
      WHERE m.utilizador_id = :uid"
);
$st->execute([':agora' => $agora->format('Y-m-d H:i:s'), ':uid' => $u['id']]);
$rConta = $st->fetch() ?: [];
$conta = [
    'ativas'     => (int)($rConta['ativas']     ?? 0),
    'concluidas' => (int)($rConta['concluidas'] ?? 0),
    'canceladas' => (int)($rConta['canceladas'] ?? 0),
];

// Benefícios registados por este utilizador
$st = $pdo->prepare(
    "SELECT bp.estado_validacao
       FROM beneficios_paciente bp
       JOIN autorizacoes_paciente a ON a.paciente_id = bp.paciente_id AND a.utilizador_id = ?
      WHERE bp.estado_validacao IN ('registado','em_validacao','validado','rejeitado')
      ORDER BY bp.criado_em DESC
      LIMIT 20"
);
$st->execute([$u['id']]);
$beneficios = $st->fetchAll();
$beneficiosPendentes = 0;
foreach ($beneficios as $b) {
    if (in_array($b['estado_validacao'], ['registado', 'em_validacao'], true)) {
        $beneficiosPendentes++;
    }
}

$proxima = $proximas[0] ?? null;

$tituloPagina = 'A minha área';
require __DIR__ . '/includes/cabecalho.php';
echo estilos_conta();
?>

<div class="dash-root">

    <div class="dash-page-top">
        <div>
            <span class="dash-kicker">A minha área</span>
            <h1>Olá, <?= e($u['nome']) ?></h1>
            <p>Resumo das tuas consultas, marcações e benefícios.</p>
        </div>
        <div class="dash-avatar" aria-hidden="true">
            <?= e(mb_strtoupper(mb_substr($u['nome'], 0, 1))) ?>
        </div>
    </div>

    <?= submenu_conta('inicio', $naoLidas) ?>

    <?php if ($proxima):
        $ini = utc($proxima['inicio']);
        $fim = utc($proxima['fim']);
    ?>
        <div class="dash-welcome">
            <span class="dash-kicker">Próxima consulta</span>
            <h2><?= e(data_extenso($ini)) ?> às <em><?= e($ini->format('H:i')) ?></em></h2>
            <p>
                <?= e($proxima['paciente']) ?> com <?= e($proxima['dentista']) ?>
                · até às <?= e($fim->format('H:i')) ?>
                · <?= e(formatar_preco($proxima['preco_total'])) ?>
            </p>
            <a class="dash-welcome-cta" href="marcar-confirmacao.php?ref=<?= e(urlencode($proxima['referencia'])) ?>">
                Ver detalhes <span aria-hidden="true">→</span>
            </a>
        </div>
    <?php else: ?>
        <div class="dash-welcome">
            <span class="dash-kicker">Sem consultas marcadas</span>
            <h2>Pronto para a próxima <em>consulta</em>?</h2>
            <p>Marca uma consulta em poucos passos e começa a cuidar do teu sorriso.</p>
            <a class="dash-welcome-cta" href="marcar.php">
                Marcar consulta <span aria-hidden="true">→</span>
            </a>
        </div>
    <?php endif; ?>

    <div class="dash-stat-grid">
        <a href="minhas-marcacoes.php">
            <span>Consultas por realizar</span>
            <strong><?= $conta['ativas'] ?></strong>
            <small><?= $conta['ativas'] === 1 ? '1 consulta ativa' : $conta['ativas'] . ' consultas ativas' ?></small>
        </a>
        <a href="minhas-marcacoes.php">
            <span>Concluídas</span>
            <strong><?= $conta['concluidas'] ?></strong>
            <small>Histórico completo</small>
        </a>
        <a href="minhas-marcacoes.php">
            <span>Canceladas</span>
            <strong><?= $conta['canceladas'] ?></strong>
            <small>Ver histórico</small>
        </a>
        <a href="notificacoes.php">
            <span>Notificações</span>
            <strong><?= (int)$naoLidas ?></strong>
            <small><?= (int)$naoLidas === 0 ? 'Tudo lido' : 'Por ler' ?></small>
        </a>
    </div>

    <?php if ($beneficiosPendentes > 0): ?>
        <div class="alerta alerta-info" role="status">
            Tens <?= $beneficiosPendentes ?> benefício(s) a aguardar validação pela clínica.
            <a href="seguros.php">Ver seguros e benefícios</a>
        </div>
    <?php endif; ?>

    <div class="dash-section-title">
        <div>
            <span class="dash-kicker">Agenda</span>
            <h2>Próximas <span>consultas</span></h2>
        </div>
        <?php if ($proximas): ?>
            <a href="minhas-marcacoes.php">Ver todas</a>
        <?php endif; ?>
    </div>

    <?php if (!$proximas): ?>
        <div class="dash-empty">
            <span class="dash-empty-symbol" aria-hidden="true">◷</span>
            <h3>Ainda não tens consultas marcadas</h3>
            <p>Marca a tua primeira consulta e começa a cuidar do teu sorriso.</p>
            <a class="botao" href="marcar.php">Marcar consulta</a>
        </div>
    <?php else: ?>
        <div class="dash-list">
            <?php foreach ($proximas as $c):
                $ini = utc($c['inicio']);
                $fim = utc($c['fim']);
            ?>
                <div class="dash-list-row">
                    <span class="dash-list-mark" aria-hidden="true">◷</span>
                    <div class="dash-list-copy">
                        <strong>
                            <?= e(data_extenso($ini)) ?> · <?= e($ini->format('H:i')) ?>–<?= e($fim->format('H:i')) ?>
                        </strong>
                        <span><?= e($c['paciente']) ?> · <?= e($c['dentista']) ?></span>
                        <small>
                            <?= e(rotulo('marcacao', $c['estado'])) ?>
                            · <?= e(formatar_preco($c['preco_total'])) ?>
                        </small>
                    </div>
                    <div class="dash-list-end">
                        <a class="dash-list-action" href="marcar-confirmacao.php?ref=<?= e(urlencode($c['referencia'])) ?>">
                            Ver detalhes
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="dash-section-title">
        <div>
            <span class="dash-kicker">Atalhos</span>
            <h2>O que <span>precisas</span></h2>
        </div>
    </div>

    <div class="dash-list">
        <a class="dash-list-row" href="marcar.php">
            <span class="dash-list-mark" aria-hidden="true">+</span>
            <div class="dash-list-copy">
                <strong>Marcar nova consulta</strong>
                <small>Escolhe tratamento, dentista e horário</small>
            </div>
            <span class="dash-list-end" aria-hidden="true">→</span>
        </a>
        <a class="dash-list-row" href="minhas-marcacoes.php">
            <span class="dash-list-mark" aria-hidden="true">◷</span>
            <div class="dash-list-copy">
                <strong>As minhas marcações</strong>
                <small>Consulta, altera ou cancela marcações</small>
            </div>
            <span class="dash-list-end" aria-hidden="true">→</span>
        </a>
        <a class="dash-list-row" href="perfil.php">
            <span class="dash-list-mark" aria-hidden="true">◉</span>
            <div class="dash-list-copy">
                <strong>Perfil e familiares</strong>
                <small>Dados pessoais e dependentes</small>
            </div>
            <span class="dash-list-end" aria-hidden="true">→</span>
        </a>
        <a class="dash-list-row" href="seguros.php">
            <span class="dash-list-mark" aria-hidden="true">◇</span>
            <div class="dash-list-copy">
                <strong>Seguros e benefícios</strong>
                <small>Planos, seguradoras e vantagens</small>
            </div>
            <span class="dash-list-end" aria-hidden="true">→</span>
        </a>
        <a class="dash-list-row" href="notificacoes.php">
            <span class="dash-list-mark" aria-hidden="true">◆</span>
            <div class="dash-list-copy">
                <strong>Notificações</strong>
                <small><?= (int)$naoLidas ?> por ler</small>
            </div>
            <span class="dash-list-end" aria-hidden="true">→</span>
        </a>
    </div>

</div>

<?php require __DIR__ . '/includes/rodape.php'; ?>