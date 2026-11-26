<?php
declare(strict_types=1);

const DURACAO_PADRAO_MIN    = 60;
const DIAS_ANTECEDENCIA_MAX = 60;
const MAX_PACIENTES         = 6;

if (!function_exists('config_valor')) { function config_valor(PDO $pdo, string $chave, int $padrao): int
{
    static $cache = [];
    if (!isset($cache[$chave])) {
        $st = $pdo->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
        $st->execute([$chave]);
        $v = $st->fetchColumn();
        $cache[$chave] = ($v !== false && ctype_digit((string)$v)) ? (int)$v : $padrao;
    }
    return $cache[$chave];
} }

function utc(string $dataHora): DateTimeImmutable
{
    return new DateTimeImmutable($dataHora, new DateTimeZone('UTC'));
}

/**
 * Hora atual da clínica.
 *
 * A BD guarda hora local em colunas DATETIME. O valor de NOW() é, por isso,
 * tratado como "wall clock" e rotulado como UTC: assim a aritmética de datas
 * não sofre deslocamentos de horário de verão e as comparações com os valores
 * lidos da BD (também tratados por utc()) são coerentes.
 *
 * A sessão MySQL é fixada ao offset de Europe/Lisbon em db().
 */
function agora_bd(PDO $pdo): DateTimeImmutable
{
    $agora = (string)$pdo->query('SELECT NOW()')->fetchColumn();
    return new DateTimeImmutable($agora, new DateTimeZone('UTC'));
}

function tempo_em_minutos(string $hhmmss): int
{
    [$h, $m] = array_map('intval', explode(':', $hhmmss));
    return $h * 60 + $m;
}

function prefixo_reserva(): string
{
    if (empty($_SESSION['marcacao']['token'])) {
        $_SESSION['marcacao']['token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['marcacao']['token'];
}

function token_paciente(string $prefixo, int $pacienteId): string
{
    return $prefixo . ':' . $pacienteId;
}

function data_extenso(DateTimeImmutable $d): string
{
    return nome_dia((int)$d->format('N')) . ', ' . $d->format('d/m/Y');
}

function limpar_reservas_expiradas(PDO $pdo): void
{
    $pdo->exec('DELETE FROM horarios_ocupados WHERE consulta_id IS NULL AND expira_em < NOW()');

    $ids = $pdo->query(
        "SELECT DISTINCT c.marcacao_id
           FROM horarios_ocupados h
           JOIN consultas c ON c.id = h.consulta_id
           JOIN marcacoes m ON m.id = c.marcacao_id
          WHERE h.expira_em IS NOT NULL AND h.expira_em < NOW() AND m.estado = 'pendente'"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $marcacaoId) {
        cancelar_marcacao_interna($pdo, (int)$marcacaoId, 'Pagamento não concluído a tempo.');
    }
}

function cancelar_marcacao_interna(PDO $pdo, int $marcacaoId, string $motivo): void
{
    $pdo->prepare(
        "UPDATE marcacoes SET estado = 'cancelada', motivo_cancelamento = ?, cancelada_em = NOW() WHERE id = ?"
    )->execute([$motivo, $marcacaoId]);
    $pdo->prepare("UPDATE consultas SET estado = 'cancelada' WHERE marcacao_id = ?")->execute([$marcacaoId]);
    $pdo->prepare(
        'DELETE FROM horarios_ocupados WHERE consulta_id IN (SELECT id FROM consultas WHERE marcacao_id = ?)'
    )->execute([$marcacaoId]);
    $pdo->prepare(
        "UPDATE pagamentos SET estado = 'cancelado', cancelado_em = NOW() WHERE marcacao_id = ? AND estado IN ('pendente','em_processamento')"
    )->execute([$marcacaoId]);
}

function libertar_reservas(PDO $pdo, string $prefixo, ?int $pacienteId = null): void
{
    if ($pacienteId !== null) {
        $pdo->prepare('DELETE FROM horarios_ocupados WHERE consulta_id IS NULL AND token_reserva = ?')
            ->execute([token_paciente($prefixo, $pacienteId)]);
    } else {
        $pdo->prepare('DELETE FROM horarios_ocupados WHERE consulta_id IS NULL AND token_reserva LIKE ?')
            ->execute([$prefixo . ':%']);
    }
}

function renovar_reservas(PDO $pdo, string $prefixo): void
{
    $min = config_valor($pdo, 'reserva_temporaria_min', 10);
    $pdo->prepare(
        'UPDATE horarios_ocupados SET expira_em = NOW() + INTERVAL ? MINUTE
          WHERE consulta_id IS NULL AND token_reserva LIKE ?'
    )->execute([$min, $prefixo . ':%']);
}

function slots_do_dia(PDO $pdo, int $dentistaId, DateTimeImmutable $dia, string $prefixo): array
{
    $bloco  = config_valor($pdo, 'bloco_agenda_min', 30);
    $diaSem = (int)$dia->format('N');
    $agora  = agora_bd($pdo)->format('Y-m-d H:i:s');
    $ini    = $dia->format('Y-m-d') . ' 00:00:00';
    $fim    = $dia->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    $st = $pdo->prepare('SELECT hora_inicio, hora_fim FROM horarios_funcionamento WHERE dia_semana = ? ORDER BY hora_inicio');
    $st->execute([$diaSem]);
    $clinica = $st->fetchAll();
    if (!$clinica) {
        return [];
    }

    $st = $pdo->prepare('SELECT hora_inicio, hora_fim FROM disponibilidades_dentista WHERE dentista_id = ? AND dia_semana = ?');
    $st->execute([$dentistaId, $diaSem]);
    $dentista = $st->fetchAll();

    if (!$dentista) {
        $dentista = $clinica;
    }

    $st = $pdo->prepare(
        'SELECT inicio, fim FROM bloqueios_horario
          WHERE (dentista_id IS NULL OR dentista_id = ?) AND inicio < ? AND fim > ?'
    );
    $st->execute([$dentistaId, $fim, $ini]);
    $bloqueios = $st->fetchAll();

    $st = $pdo->prepare(
        'SELECT inicio_bloco, consulta_id, token_reserva FROM horarios_ocupados
          WHERE dentista_id = ? AND inicio_bloco >= ? AND inicio_bloco < ?
            AND (consulta_id IS NOT NULL OR expira_em > NOW())'
    );
    $st->execute([$dentistaId, $ini, $fim]);
    $ocupados = [];
    foreach ($st as $o) {
        $ocupados[$o['inicio_bloco']] = $o;
    }

    $dentro = function (array $blocos, int $a, int $b): bool {
        foreach ($blocos as $bl) {
            if ($a >= tempo_em_minutos($bl['hora_inicio']) && $b <= tempo_em_minutos($bl['hora_fim'])) {
                return true;
            }
        }
        return false;
    };

    $minIni = min(array_map(fn($b) => tempo_em_minutos($b['hora_inicio']), $clinica));
    $maxFim = max(array_map(fn($b) => tempo_em_minutos($b['hora_fim']), $clinica));

    $slots = [];
    for ($m = $minIni; $m + $bloco <= $maxFim; $m += $bloco) {
        $inicioDT = $dia->setTime(intdiv($m, 60), $m % 60);
        $chave    = $inicioDT->format('Y-m-d H:i:00');
        $fimSlot  = $inicioDT->modify('+' . $bloco . ' minutes')->format('Y-m-d H:i:00');
        $token    = null;

        if ($chave <= $agora || !$dentro($clinica, $m, $m + $bloco) || !$dentro($dentista, $m, $m + $bloco)) {
            $estado = 'indisponivel';
        } else {
            $estado = 'livre';
            foreach ($bloqueios as $b) {
                if ($chave < $b['fim'] && $fimSlot > $b['inicio']) { $estado = 'indisponivel'; break; }
            }
            if ($estado === 'livre' && isset($ocupados[$chave])) {
                $o = $ocupados[$chave];
                if ($o['consulta_id'] !== null) {
                    $estado = 'ocupado';
                } elseif (str_starts_with((string)$o['token_reserva'], $prefixo . ':')) {
                    $estado = 'reservado_meu';
                    $token  = $o['token_reserva'];
                } else {
                    $estado = 'reservado_outro';
                }
            }
        }

        $slots[$chave] = ['inicio' => $chave, 'hora' => $inicioDT->format('H:i'), 'estado' => $estado, 'token' => $token];
    }
    return $slots;
}

function blocos_da_consulta(PDO $pdo, string $inicio, int $duracaoMin): array
{
    $bloco  = config_valor($pdo, 'bloco_agenda_min', 30);
    $n      = (int)ceil($duracaoMin / $bloco);
    $t      = utc($inicio);
    $chaves = [];
    for ($i = 0; $i < $n; $i++) {
        $chaves[] = $t->modify('+' . ($i * $bloco) . ' minutes')->format('Y-m-d H:i:00');
    }
    return $chaves;
}

function inicio_valido(array $slots, array $chaves, string $tokenPaciente): bool
{
    foreach ($chaves as $k) {
        $s = $slots[$k] ?? null;
        if ($s === null) { return false; }
        if ($s['estado'] === 'livre') { continue; }
        if ($s['estado'] === 'reservado_meu' && $s['token'] === $tokenPaciente) { continue; }
        return false;
    }
    return true;
}

function proxima_data_livre(PDO $pdo, int $dentistaId, DateTimeImmutable $desde, string $prefixo): ?DateTimeImmutable
{
    for ($i = 0; $i <= DIAS_ANTECEDENCIA_MAX; $i++) {
        $d = $desde->modify('+' . $i . ' days');
        foreach (slots_do_dia($pdo, $dentistaId, $d, $prefixo) as $s) {
            if ($s['estado'] === 'livre') { return $d; }
        }
    }
    return null;
}

function reservar_horario(PDO $pdo, int $dentistaId, int $pacienteId, string $inicio, int $duracaoMin, string $prefixo): void
{
    $token   = token_paciente($prefixo, $pacienteId);
    $minutos = config_valor($pdo, 'reserva_temporaria_min', 10);

    limpar_reservas_expiradas($pdo);

    $chaves = blocos_da_consulta($pdo, $inicio, $duracaoMin);
    $slots  = slots_do_dia($pdo, $dentistaId, utc(substr($inicio, 0, 10) . ' 00:00:00'), $prefixo);
    if (!inicio_valido($slots, $chaves, $token)) {
        throw new RuntimeException('esse horário já não está disponível.');
    }

    libertar_reservas($pdo, $prefixo, $pacienteId);

    $ins = $pdo->prepare(
        'INSERT INTO horarios_ocupados (dentista_id, inicio_bloco, token_reserva, expira_em)
         VALUES (?, ?, ?, NOW() + INTERVAL ? MINUTE)'
    );
    foreach ($chaves as $k) {
        try {
            $ins->execute([$dentistaId, $k, $token, $minutos]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new RuntimeException('esse horário acabou de ser ocupado por outra pessoa.');
            }
            throw $e;
        }
    }
}

function estilos_marcacao(): string
{
    return <<<'HTML'
<style>
    .passos { display:flex; flex-wrap:wrap; gap:.5rem; list-style:none; padding:0; margin:0 0 1.5rem; counter-reset:passo; }
    .passos li { counter-increment:passo; padding:.4rem .85rem; border:1px solid var(--borda); border-radius:999px; font-size:.9rem; color:var(--texto-suave); background:var(--superficie); }
    .passos li::before { content:counter(passo) ". "; }
    .passos li.atual { background:var(--destaque); color:var(--destaque-texto); border-color:var(--destaque); font-weight:700; }
    .passos li.feito { color:var(--sucesso); border-color:var(--sucesso); }

    .navdia { display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; justify-content:space-between; padding:1rem; margin:1rem 0; border:1px solid var(--borda); border-radius:14px; background:var(--superficie); }
    .navdia form { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; }
    .navdia label { font-size:.85rem; font-weight:600; color:var(--texto-suave); }
    .navdia input[type="date"], .campo input[type="date"] {
        padding:.6rem .75rem; font-size:1rem; font-family:inherit;
        border:1px solid var(--borda); border-radius:9px; background:var(--fundo); color:var(--texto);
    }

    .legenda { display:flex; flex-wrap:wrap; gap:.5rem .7rem; font-size:.85rem; margin:.8rem 0 0; padding:0; list-style:none; }
    .legenda .bloco { display:inline-flex; align-items:center; padding:.28rem .7rem; text-align:left; border-radius:999px; border-width:1px; }
    .calendario { display:grid; grid-template-columns:repeat(auto-fill,minmax(112px,1fr)); gap:.55rem; margin:1rem 0; padding:0; list-style:none; }
    .bloco { min-width:0; padding:.65rem .55rem; border-radius:11px; border:1.5px solid var(--borda); background:var(--superficie); text-align:center; font-size:.85rem; }
    .bloco strong { display:block; margin-bottom:.18rem; font-size:1.02rem; font-variant-numeric:tabular-nums; }
    .bloco span { display:block; line-height:1.35; }
    .bloco-livre { border-color:var(--sucesso); background:var(--sucesso-fundo, var(--superficie)); }
    .bloco-ocupado { background:var(--erro-fundo); border-color:var(--erro); color:var(--erro); }
    .bloco-indisponivel { background:var(--borda); color:var(--texto-suave); border-style:dashed; }
    .bloco-reservado_outro { background:var(--info-fundo); border-color:var(--info); color:var(--info); border-style:dotted; }
    .bloco-reservado_meu { background:var(--destaque); border-color:var(--destaque); color:var(--destaque-texto); }

    .agenda-resumo { display:flex; flex-wrap:wrap; gap:.55rem; margin:1rem 0 .35rem; }
    .agenda-resumo span { display:inline-flex; align-items:center; gap:.4rem; padding:.4rem .7rem; border:1px solid var(--borda); border-radius:999px; background:var(--superficie); color:var(--texto-suave); font-size:.82rem; }
    .agenda-resumo strong { color:var(--texto); font-variant-numeric:tabular-nums; }
    .agenda-titulo { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:flex-end; gap:.75rem; margin:1.5rem 0 .7rem; }
    .agenda-titulo h2 { margin:0; }
    .agenda-titulo p { margin:.25rem 0 0; }

    .horario-pessoa { min-width:0; margin:1.15rem 0 0; padding:1rem; border:1px solid var(--borda); border-radius:14px; background:var(--superficie); }
    .horario-pessoa legend { padding:0 .35rem; font-weight:700; color:var(--texto); }
    .horario-pessoa-ajuda { margin:.1rem 0 .8rem; color:var(--texto-suave); font-size:.88rem; }
    .horario-grupo { margin-top:.9rem; }
    .horario-grupo h3 { margin:0 0 .55rem; font-size:.88rem; font-weight:600; color:var(--texto-suave); }
    .horario-opcoes { display:grid; grid-template-columns:repeat(auto-fill,minmax(112px,1fr)); gap:.55rem; }
    .horario-opcao { position:relative; display:block; min-width:0; cursor:pointer; }
    .horario-opcao input { position:absolute; width:1px; height:1px; opacity:0; }
    .horario-opcao-conteudo { display:flex; flex-direction:column; justify-content:center; gap:.18rem; min-height:68px; padding:.65rem .75rem; border:1.5px solid var(--borda); border-radius:11px; background:var(--fundo); transition:border-color .15s ease, background .15s ease, transform .15s ease; }
    .horario-opcao-conteudo strong { color:var(--texto); font-size:1.08rem; line-height:1.2; font-variant-numeric:tabular-nums; }
    .horario-opcao-conteudo small { color:var(--texto-suave); font-size:.78rem; }
    .horario-opcao:hover .horario-opcao-conteudo { border-color:var(--destaque); transform:translateY(-1px); }
    .horario-opcao input:checked + .horario-opcao-conteudo { border-color:var(--destaque); background:color-mix(in srgb, var(--destaque) 10%, var(--superficie)); box-shadow:0 0 0 1px var(--destaque); }
    .horario-opcao input:focus-visible + .horario-opcao-conteudo { outline:3px solid color-mix(in srgb, var(--destaque) 28%, transparent); outline-offset:3px; }
    .horario-opcao-vazio { padding:.8rem; border:1px dashed var(--borda); border-radius:10px; color:var(--texto-suave); font-size:.88rem; }
    .horario-acoes { display:flex; flex-wrap:wrap; gap:.6rem; margin-top:1.25rem; }
    @media (max-width:600px) {
        .navdia { align-items:stretch; }
        .navdia form { width:100%; }
        .navdia input[type="date"] { flex:1; min-width:0; }
        .horario-opcoes { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .calendario { grid-template-columns:repeat(2,minmax(0,1fr)); }
    }
    @media (prefers-reduced-motion:reduce) {
        .horario-opcao-conteudo { transition:none; }
    }
</style>
HTML;
}

function passos_marcacao(int $atual): string
{
    $nomes = ['Pacientes', 'Horário', 'Tratamentos', 'Resumo', 'Pagamento'];
    $html  = '<ol class="passos" aria-label="Progresso da marcação">';
    foreach ($nomes as $i => $nome) {
        $n   = $i + 1;
        $cls = $n < $atual ? 'feito' : ($n === $atual ? 'atual' : '');
        $html .= '<li class="' . $cls . '"' . ($n === $atual ? ' aria-current="step"' : '') . '>' . e($nome) . '</li>';
    }
    return $html . '</ol>';
}

function carregar_marcacao(PDO $pdo, int $utilizadorId): array
{
    $m = $_SESSION['marcacao'] ?? [];
    if (empty($m['dentista_id'])) {
        redirecionar('dentistas.php?modo=marcar');
    }
    if (empty($m['pacientes'])) {
        redirecionar('marcar-pacientes.php');
    }

    $st = $pdo->prepare('SELECT id, nome_completo FROM dentistas WHERE id = ? AND ativo = 1');
    $st->execute([(int)$m['dentista_id']]);
    $dentista = $st->fetch();
    if (!$dentista) {
        unset($_SESSION['marcacao']);
        redirecionar('dentistas.php?modo=marcar');
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM disponibilidades_dentista WHERE dentista_id = ?');
    $st->execute([(int)$dentista['id']]);
    $dentistaTemHorario = (int)$st->fetchColumn() > 0;

    $ids = array_map('intval', $m['pacientes']);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare(
        "SELECT p.id, p.nome_completo
           FROM pacientes p
           JOIN autorizacoes_paciente a ON a.paciente_id = p.id AND a.utilizador_id = ? AND a.pode_marcar = 1
          WHERE p.id IN ($in) AND p.ativo = 1"
    );
    $st->execute(array_merge([$utilizadorId], $ids));
    $porId = [];
    foreach ($st as $p) { $porId[(int)$p['id']] = $p; }
    if (count($porId) !== count($ids)) {
        redirecionar('marcar-pacientes.php');
    }
    $pacientes = [];
    foreach ($ids as $pid) { $pacientes[] = $porId[$pid]; }

    $prefixo = prefixo_reserva();
    limpar_reservas_expiradas($pdo);
    $avisos = [];
    foreach (($_SESSION['marcacao']['horarios'] ?? []) as $pid => $h) {
        $esperado = count(blocos_da_consulta($pdo, $h['inicio'], (int)$h['duracao']));
        $st = $pdo->prepare('SELECT COUNT(*) FROM horarios_ocupados WHERE consulta_id IS NULL AND token_reserva = ?');
        $st->execute([token_paciente($prefixo, (int)$pid)]);
        if ((int)$st->fetchColumn() < $esperado) {
            unset($_SESSION['marcacao']['horarios'][$pid]);
            $avisos[] = 'A reserva de ' . ($porId[(int)$pid]['nome_completo'] ?? 'um paciente') . ' expirou. Escolhe o horário outra vez.';
        }
    }

    return [
        'dentista'             => $dentista,
        'pacientes'            => $pacientes,
        'porId'                => $porId,
        'prefixo'              => $prefixo,
        'avisos'               => $avisos,
        'dentista_tem_horario' => $dentistaTemHorario,
    ];
}

function tratamentos_do_dentista(PDO $pdo, int $dentistaId): array
{
    $st = $pdo->prepare(
        'SELECT DISTINCT t.id, t.nome, t.descricao, t.preco, t.tipo_preco, t.duracao_min
           FROM tratamentos t
          WHERE t.ativo = 1
            AND (
                t.especialidade_id IS NULL
                OR EXISTS (
                    SELECT 1 FROM dentistas_tratamentos dt
                     WHERE dt.dentista_id = ? AND dt.tratamento_id = t.id
                )
                OR EXISTS (
                    SELECT 1 FROM dentistas_especialidades de
                     WHERE de.dentista_id = ? AND de.especialidade_id = t.especialidade_id
                )
            )
          ORDER BY t.nome'
    );
    $st->execute([$dentistaId, $dentistaId]);
    $r = [];
    foreach ($st as $t) { $r[(int)$t['id']] = $t; }
    return $r;
}

function beneficio_desconto_tratamento(PDO $pdo, int $pacienteId, int $tratamentoId, string $dataConsulta, float $precoOriginal): ?array
{
    $data = substr($dataConsulta, 0, 10);
    $precoOriginal = round($precoOriginal, 2);
    $st = $pdo->prepare(
        "SELECT bp.id AS beneficio_id, s.tipo AS seguradora_tipo, pl.nome AS plano_nome,
                pt.tipo_desconto, pt.valor, pt.preco_convencionado, pt.valor_max_comparticipado, pt.percentagem_comparticipacao, pt.condicoes_especificas
           FROM beneficios_paciente bp
           JOIN planos_beneficio pl ON pl.id = bp.plano_id AND pl.ativo = 1
           JOIN seguradoras s ON s.id = pl.seguradora_id AND s.ativo = 1
           JOIN planos_tratamentos pt ON pt.plano_id = pl.id AND pt.tratamento_id = ?
          WHERE bp.paciente_id = ?
            AND bp.estado_validacao = 'validado'
            AND (bp.valido_de IS NULL OR bp.valido_de <= ?)
            AND (bp.valido_ate IS NULL OR bp.valido_ate >= ?)
            AND (pt.ativo IS NULL OR pt.ativo = 1)"
    );
    $st->execute([$tratamentoId, $pacienteId, $data, $data]);
    $melhor = null;
    foreach ($st->fetchAll() as $r) {
        $desc = 0.0;
        $motivo = '';
        if ($r['preco_convencionado'] !== null && $r['preco_convencionado'] !== '') {
            $conv = round((float)$r['preco_convencionado'], 2);
            $desc = max(0.0, round($precoOriginal - $conv, 2));
            $motivo = 'preço convencionado';
        }
        if ($desc <= 0.0001 && $r['percentagem_comparticipacao'] !== null && $r['percentagem_comparticipacao'] !== '') {
            $pct = (float)$r['percentagem_comparticipacao'];
            $comp = round($precoOriginal * ($pct / 100), 2);
            if ($r['valor_max_comparticipado'] !== null && $r['valor_max_comparticipado'] !== '') {
                $comp = min($comp, round((float)$r['valor_max_comparticipado'], 2));
            }
            $desc = max(0.0, $comp);
            $motivo = 'comparticipação';
        }
        if ($desc <= 0.0001 && $r['valor'] !== null && (float)$r['valor'] > 0) {
            if (($r['tipo_desconto'] ?? 'percentagem') === 'percentagem') {
                $desc = round($precoOriginal * ((float)$r['valor'] / 100), 2);
                $motivo = 'desconto percentual';
            } else {
                $desc = round((float)$r['valor'], 2);
                $motivo = 'desconto fixo';
            }
            if ($r['valor_max_comparticipado'] !== null && $r['valor_max_comparticipado'] !== '' && $motivo !== 'desconto fixo') {
                $desc = min($desc, round((float)$r['valor_max_comparticipado'], 2));
            }
        }
        $desc = min(max(0.0, $desc), $precoOriginal);
        if ($desc > 0.0001 && ($melhor === null || $desc > $melhor['desconto'])) {
            $melhor = [
                'beneficio_id' => (int)$r['beneficio_id'],
                'desconto' => $desc,
                'motivo' => $motivo,
                'plano_nome' => $r['plano_nome'] ?? null,
                'seguradora_tipo' => $r['seguradora_tipo'] ?? null,
            ];
        }
    }
    return $melhor;
}

function totais_tratamentos(array $disponiveis, array $ids, ?PDO $pdo = null, ?int $pacienteId = null, ?string $dataConsulta = null): array
{
    $dur = 0;
    $total = 0.0;
    $totalOriginal = 0.0;
    $totalDesconto = 0.0;
    $estimado = false;
    $linhas = [];
    foreach ($ids as $id) {
        $t = $disponiveis[$id];
        $precoOriginal = round((float)$t['preco'], 2);
        $desconto = 0.0;
        $beneficioId = null;
        if ($pdo !== null && $pacienteId !== null && $dataConsulta !== null) {
            $ap = beneficio_desconto_tratamento($pdo, $pacienteId, (int)$t['id'], $dataConsulta, $precoOriginal);
            if ($ap) {
                $desconto = (float)$ap['desconto'];
                $beneficioId = (int)$ap['beneficio_id'];
            }
        }
        $precoFinal = round($precoOriginal - $desconto, 2);
        $dur   += (int)$t['duracao_min'];
        $total += $precoFinal;
        $totalOriginal += $precoOriginal;
        $totalDesconto += $desconto;
        if ($t['tipo_preco'] === 'sujeito_avaliacao') { $estimado = true; }
        $linha = $t;
        $linha['preco_original'] = $precoOriginal;
        $linha['desconto'] = $desconto;
        $linha['preco_final'] = $precoFinal;
        $linha['beneficio_id'] = $beneficioId;
        $linhas[] = $linha;
    }
    return [
        'duracao' => $dur,
        'total' => round($total, 2),
        'total_original' => round($totalOriginal, 2),
        'total_desconto' => round($totalDesconto, 2),
        'estimado' => $estimado,
        'linhas' => $linhas,
    ];
}

function resumo_da_marcacao(PDO $pdo, array $c): array
{
    $dentista  = $c['dentista'];
    $horarios  = $_SESSION['marcacao']['horarios'] ?? [];
    $disponiveis = tratamentos_do_dentista($pdo, (int)$dentista['id']);

    $linhas = [];
    $total = 0.0;
    $estimado = false;

    foreach ($c['pacientes'] as $p) {
        $pid = (int)$p['id'];
        if (!isset($horarios[$pid])) {
            flash_definir('info', $c['avisos'] ? implode(' ', $c['avisos']) : 'Escolhe primeiro o horário de todas as pessoas.');
            redirecionar('marcar-calendario.php');
        }
        $ids = array_map('intval', $_SESSION['marcacao']['tratamentos'][$pid] ?? []);
        $valido = $ids !== [];
        foreach ($ids as $id) {
            if (!isset($disponiveis[$id])) { $valido = false; }
        }
        if (!$valido) {
            flash_definir('info', 'Escolhe os tratamentos de ' . $p['nome_completo'] . '.');
            redirecionar('marcar-tratamentos.php');
        }
        $t = totais_tratamentos($disponiveis, $ids, $pdo, $pid, $horarios[$pid]['inicio'] ?? null);
        if ($t['duracao'] !== (int)$horarios[$pid]['duracao']) {
            redirecionar('marcar-tratamentos.php');
        }
        $ini = utc($horarios[$pid]['inicio']);
        $linhas[] = [
            'paciente' => $p,
            'inicio'   => $ini,
            'fim'      => $ini->modify('+' . $t['duracao'] . ' minutes'),
            'totais'   => $t,
        ];
        $total += $t['total'];
        if ($t['estimado']) { $estimado = true; }
    }
    return ['linhas' => $linhas, 'total' => round($total, 2), 'estimado' => $estimado];
}

function gerar_referencia(PDO $pdo): string
{
    for ($i = 0; $i < 10; $i++) {
        $ref = 'CD-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $st = $pdo->prepare('SELECT 1 FROM marcacoes WHERE referencia = ?');
        $st->execute([$ref]);
        if (!$st->fetchColumn()) { return $ref; }
    }
    throw new RuntimeException('Não foi possível gerar a referência da marcação.');
}

function rotulo(string $grupo, string $valor): string
{
    static $r = [
        'marcacao'  => ['pendente' => 'Pendente', 'confirmada' => 'Confirmada', 'cancelada' => 'Cancelada', 'concluida' => 'Concluída'],
        'pagamento' => [
            'pendente' => 'Pendente', 'em_processamento' => 'Em processamento', 'pago' => 'Pago',
            'parcialmente_pago' => 'Parcialmente pago', 'recusado' => 'Recusado', 'falhado' => 'Falhado',
            'cancelado' => 'Cancelado', 'reembolsado' => 'Reembolsado', 'parcialmente_reembolsado' => 'Parcialmente reembolsado',
        ],
        'metodo'    => [
            'eletronico_antecipado' => 'Pagamento eletrónico antecipado',
            'presencial' => 'Pagamento na clínica',
            'sinal' => 'Sinal',
            'mbway' => 'MB WAY',
            'multibanco' => 'Multibanco',
            'cartao_online' => 'Cartão (Visa/Mastercard)',
            'outro' => 'Outro',
        ],
    ];
    return $r[$grupo][$valor] ?? $valor;
}

function criar_notificacao(PDO $pdo, int $utilizadorId, ?int $marcacaoId, string $tipo, string $assunto, string $mensagem): void
{
    $pdo->prepare(
        "INSERT INTO notificacoes (utilizador_id, marcacao_id, tipo, canal, assunto, mensagem, estado, enviada_em)
         VALUES (?, ?, ?, 'interna', ?, ?, 'enviada', NOW())"
    )->execute([$utilizadorId, $marcacaoId, $tipo, $assunto, $mensagem]);
}