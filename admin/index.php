<?php
declare(strict_types=1);
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/gestao.php';
require __DIR__ . '/../includes/stock.php';
require __DIR__ . '/../includes/servicos/admin.php';

$u   = exigir_login(['admin', 'rececao']);
$pdo = db();

$hoje = (new DateTimeImmutable('now'))->format('Y-m-d');
$total = [
    'dentistas'    => (int)$pdo->query('SELECT COUNT(*) FROM dentistas WHERE ativo = 1')->fetchColumn(),
    'tratamentos'  => (int)$pdo->query('SELECT COUNT(*) FROM tratamentos WHERE ativo = 1')->fetchColumn(),
    'pacientes'    => (int)$pdo->query('SELECT COUNT(*) FROM pacientes WHERE ativo = 1')->fetchColumn(),
    'candidaturas' => (int)$pdo->query("SELECT COUNT(*) FROM candidaturas WHERE estado = 'nova'")->fetchColumn(),
];
$indicadores = [];
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM consultas WHERE DATE(inicio)=? AND estado NOT IN ('cancelada')");
    $st->execute([$hoje]); $indicadores['consultas_hoje'] = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM consultas WHERE DATE(inicio)=? AND estado='cancelada'");
    $st->execute([$hoje]); $indicadores['canceladas_hoje'] = (int)$st->fetchColumn();
    $st = $pdo->query("SELECT COUNT(*) FROM lista_espera WHERE estado='ativa'"); $indicadores['lista_espera'] = (int)$st->fetchColumn();
} catch (Throwable $e) { $indicadores = []; }

$pendencias = pendencias_admin($pdo, $u);
$totalPendencias = 0;
foreach ($pendencias as $pendencia) {
    $totalPendencias += (int)$pendencia['valor'];
}

$tituloPagina = 'Administração';
$css_extra = ['css/dashboard.css'];
require __DIR__ . '/../includes/cabecalho.php';
?>

<div class="dash-root adm-root">

    <div class="dash-page-top">
        <div>
            <span class="dash-kicker">Administração</span>
            <h1>Painel de gestão da clínica</h1>
            <p>
                Aqui controlas o funcionamento diário da clínica: a equipa e os horários,
                a comunicação com os pacientes, as marcações, os pagamentos e os recursos
                materiais. Escolhe uma área para começar.
            </p>
        </div>
        <div class="dash-avatar" aria-hidden="true">
            <?= e(iniciais($u['nome'])) ?>
        </div>
    </div>

    <?php if ($totalPendencias > 0): ?>
        <section class="adm-pending" aria-labelledby="adm-pendencias-titulo">
            <div class="adm-pending-head">
                <div>
                    <span class="dash-kicker">Ações pendentes</span>
                    <h2 id="adm-pendencias-titulo">
                        <?= $totalPendencias === 1
                            ? '1 item precisa de atenção'
                            : $totalPendencias . ' itens precisam de atenção' ?>
                    </h2>
                    <p class="ajuda">
                        Este é o mesmo total que aparece junto a “Administração” no menu.
                        Cada número tem uma ligação direta para o local onde pode ser tratado.
                    </p>
                </div>
                <span class="adm-pending-total" aria-hidden="true"><?= $totalPendencias ?></span>
            </div>

            <div class="adm-pending-grid">
                <?php foreach ($pendencias as $pendencia): $valor = (int)$pendencia['valor']; ?>
                    <a class="adm-pending-item<?= $valor === 0 ? ' is-zero' : '' ?>"
                       href="<?= e($pendencia['url']) ?>">
                        <span><?= e($pendencia['rotulo']) ?></span>
                        <strong><?= $valor ?></strong>
                        <small><?= $valor === 0 ? 'Sem pendências' : 'Abrir e tratar' ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php else: ?>
        <div class="adm-clear" role="status">
            <span class="adm-clear-icon" aria-hidden="true"><?= admin_icone('check', 20) ?></span>
            <div>
                <strong>Sem pendências.</strong>
                <?= $u['papel'] === 'admin'
                    ? 'Não há mensagens, pedidos, avaliações ou alertas de stock por tratar.'
                    : 'Não há mensagens de contacto nem pedidos de alteração por tratar.' ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="dash-section-title">
        <div>
            <span class="dash-kicker">Áreas de gestão</span>
            <h2>O que queres <span>gerir</span>?</h2>
        </div>
    </div>

    <div class="adm-section-grid">
        <?= admin_seccoes_html($u) ?>
    </div>

    <div class="dash-section-title">
        <div>
            <span class="dash-kicker">Hoje</span>
            <h2>Resumo <span>rápido</span></h2>
        </div>
        <a href="admin/relatorios.php">Ver relatórios</a>
    </div>

    <div class="dash-stat-grid">
        <a href="admin/marcacoes.php">
            <span>Consultas hoje</span>
            <strong><?= (int)($indicadores['consultas_hoje'] ?? 0) ?></strong>
            <small>Não canceladas</small>
        </a>
        <a href="admin/marcacoes.php">
            <span>Canceladas hoje</span>
            <strong><?= (int)($indicadores['canceladas_hoje'] ?? 0) ?></strong>
            <small>Ver agenda</small>
        </a>
        <a href="admin/lista-espera.php">
            <span>Lista de espera</span>
            <strong><?= (int)($indicadores['lista_espera'] ?? 0) ?></strong>
            <small>Pedidos ativos</small>
        </a>
        <a href="admin/relatorios.php">
            <span>Pacientes ativos</span>
            <strong><?= $total['pacientes'] ?></strong>
            <small>Ver relatórios</small>
        </a>
    </div>

    <?php if ($u['papel'] === 'admin'): ?>
        <div class="dash-section-title">
            <div>
                <span class="dash-kicker">Visão geral</span>
                <h2>Estado da <span>clínica</span></h2>
            </div>
        </div>

        <div class="adm-info-grid">
            <a class="adm-info-card" href="admin/dentistas.php">
                <span>Dentistas ativos</span>
                <strong><?= $total['dentistas'] ?></strong>
                <small>Equipa clínica</small>
            </a>
            <a class="adm-info-card" href="admin/tratamentos.php">
                <span>Tratamentos ativos</span>
                <strong><?= $total['tratamentos'] ?></strong>
                <small>Catálogo clínico</small>
            </a>
            <a class="adm-info-card" href="admin/candidaturas.php">
                <span>Candidaturas novas</span>
                <strong><?= $total['candidaturas'] ?></strong>
                <small>Recrutamento</small>
            </a>
        </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/rodape.php'; ?>
