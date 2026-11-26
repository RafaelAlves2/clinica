<?php
declare(strict_types=1);

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/servicos/importacao.php';

$u = exigir_login(['admin']);
$pdo = db();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$analise = null;
$validacao = null;
$erros = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_validar();

    $acaoPedido = (string)($_POST['acao'] ?? 'analisar');
    $mapeamentoManual = null;
    if ($acaoPedido === 'validar_mapeamento') {
        $mapeamentoRecebido = $_POST['mapeamento'] ?? null;
        if (!is_array($mapeamentoRecebido)) {
            $erros[] = 'O mapeamento enviado não é válido.';
        } else {
            $mapeamentoManual = [];
            foreach ($mapeamentoRecebido as $campo => $cabecalho) {
                if (!is_string($campo) || !is_scalar($cabecalho)) {
                    $erros[] = 'O mapeamento contém valores inválidos.';
                    $mapeamentoManual = null;
                    break;
                }
                $mapeamentoManual[$campo] = (string)$cabecalho;
            }
        }
    } elseif ($acaoPedido !== 'analisar') {
        $erros[] = 'A operação solicitada não é reconhecida.';
    }

    $ficheiro = $_FILES['ficheiro_csv'] ?? null;
    if ($erros !== []) {
        // Não processa o upload quando a ação ou o mapeamento já são inválidos.
    } elseif (!is_array($ficheiro)) {
        $erros[] = 'Seleciona um ficheiro CSV.';
    } elseif (($ficheiro['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $codigoErro = (int)($ficheiro['error'] ?? UPLOAD_ERR_NO_FILE);
        $erros[] = match ($codigoErro) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O ficheiro excede o limite de tamanho permitido pelo servidor.',
            UPLOAD_ERR_PARTIAL => 'O envio do ficheiro ficou incompleto. Tenta novamente.',
            UPLOAD_ERR_NO_FILE => 'Seleciona um ficheiro CSV.',
            default => 'Não foi possível receber o ficheiro.',
        };
    } else {
        $nome = (string)($ficheiro['name'] ?? '');
        $tamanho = (int)($ficheiro['size'] ?? 0);
        $temporario = (string)($ficheiro['tmp_name'] ?? '');

        if (strtolower(pathinfo($nome, PATHINFO_EXTENSION)) !== 'csv') {
            $erros[] = 'Nesta primeira versão são aceites apenas ficheiros CSV. Um ficheiro Excel pode ser exportado para CSV.';
        } elseif ($tamanho <= 0 || $tamanho > 5 * 1024 * 1024) {
            $erros[] = 'O ficheiro deve ter entre 1 byte e 5 MB.';
        } elseif (!is_uploaded_file($temporario)) {
            $erros[] = 'O ficheiro recebido não passou a validação de upload.';
        } else {
            $conteudo = file_get_contents($temporario);
            if (!is_string($conteudo) || strlen($conteudo) !== $tamanho) {
                $erros[] = 'Não foi possível ler o ficheiro completo.';
            } else {
                $analise = importacao_analisar_csv($conteudo, $mapeamentoManual);
                if (empty($analise['ok'])) {
                    $erros[] = (string)($analise['erro'] ?? 'Não foi possível analisar o CSV.');
                    $analise = null;
                } else {
                    $validacao = importacao_validar_registos_pacientes(
                        $pdo,
                        $analise['registos_mapeados'] ?? [],
                        $analise['mapeamento_efetivo'] ?? []
                    );
                    // Os registos completos só vivem durante este pedido e não são enviados para o HTML.
                    unset($analise['registos_mapeados']);

                    // A auditoria guarda apenas contagens, nunca valores de pacientes.
                    registar_admin(
                        'previsualizar_importacao_csv',
                        'importacao',
                        null,
                        'Pré-visualização CSV: ' . (int)$analise['linhas'] . ' registos, '
                            . count($analise['cabecalhos']) . ' colunas, delimitador '
                            . (string)$analise['delimitador'] . '. Nenhum dado foi importado.'
                    );
                }
            }
            unset($conteudo);
        }
    }
}

$rotulosCamposImportacao = [
    'nome_completo' => 'Nome completo',
    'email' => 'Email',
    'telemovel' => 'Telemóvel',
    'data_nascimento' => 'Data de nascimento',
    'numero_utente_sns' => 'Número de utente SNS',
];

$tituloPagina = 'Importação de dados';
require __DIR__ . '/../includes/cabecalho.php';
?>

<p><a href="admin/seccao-sistema.php">← Administração do sistema</a></p>
<h1>Importar dados de outro sistema</h1>
<p class="ajuda">Ferramenta de preparação para migrar dados de programas antigos. Analisa exportações, sugere ou permite corrigir o mapeamento e simula resultados sem alterar os registos.</p>

<div class="alerta alerta-info" role="status">
    <strong>Modo de preparação e simulação.</strong>
    Os ficheiros são lidos temporariamente. Para confirmar um mapeamento, terás de selecionar novamente o CSV;
    assim não precisamos de guardar permanentemente uma cópia de dados pessoais ou clínicos.
    Nenhum paciente, funcionário, consulta, pagamento ou documento é gravado nesta fase.
</div>

<?php foreach ($erros as $erro): ?>
    <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
<?php endforeach; ?>

<section class="cartao">
    <h2>1. Escolher uma exportação</h2>
    <p class="ajuda">
        Aceita CSV até 5 MB, com cabeçalho na primeira linha. São detetados automaticamente
        os separadores ponto e vírgula, vírgula, tabulação e barra vertical.
        Também tenta reconhecer UTF-8, UTF-8 com BOM, UTF-16 e Windows-1252.
    </p>
    <form method="post" action="admin/importar-dados.php" enctype="multipart/form-data">
        <?= csrf_campo() ?>
        <input type="hidden" name="acao" value="analisar">
        <div class="campo">
            <label for="ficheiro_csv">Ficheiro CSV</label>
            <input type="file" id="ficheiro_csv" name="ficheiro_csv" accept=".csv,text/csv" required>
        </div>
        <p class="ajuda">
            No Excel, normalmente podes usar <strong>Ficheiro → Guardar como → CSV UTF-8</strong>.
            Não envies ficheiros reais de pacientes para experiências sem autorização da clínica.
        </p>
        <button type="submit" class="botao">Analisar ficheiro</button>
    </form>
</section>

<?php if (is_array($analise)): ?>
    <section class="cartao">
        <h2>2. Resultado da análise</h2>
        <div class="tabela-scroll">
            <table class="tabela">
                <tbody>
                    <tr><th>Registos analisados</th><td><?= (int)$analise['linhas'] ?></td></tr>
                    <tr><th>Colunas detetadas</th><td><?= count($analise['cabecalhos']) ?></td></tr>
                    <tr><th>Linhas vazias ignoradas</th><td><?= (int)$analise['linhas_vazias'] ?></td></tr>
                    <tr><th>Linhas com colunas irregulares</th><td><?= (int)$analise['linhas_irregulares'] ?></td></tr>
                    <tr><th>Emails repetidos no ficheiro</th><td><?= (int)$analise['duplicados_email'] ?></td></tr>
                    <tr><th>Separador</th><td><?= e((string)$analise['delimitador']) ?></td></tr>
                    <tr><th>Codificação</th><td><?= e((string)$analise['encoding']) ?></td></tr>
                </tbody>
            </table>
        </div>

        <?php foreach (($analise['avisos'] ?? []) as $aviso): ?>
            <div class="alerta alerta-info" role="status"><?= e((string)$aviso) ?></div>
        <?php endforeach; ?>

        <?php if (!empty($analise['colunas_sensiveis'])): ?>
            <p class="ajuda">
                Cabeçalhos potencialmente clínicos detetados:
                <strong><?= e(implode(', ', $analise['colunas_sensiveis'])) ?></strong>.
                Esta análise não substitui a validação de permissões e de proteção dos dados.
            </p>
        <?php endif; ?>

        <h3>Mapeamento automático sugerido</h3>
        <p class="ajuda">Compara os nomes das colunas com os campos conhecidos da ficha de paciente. Só são aceites correspondências exatas após normalização; os campos não reconhecidos ficam por mapear.</p>
        <div class="tabela-scroll">
            <table class="tabela">
                <thead><tr><th>Campo do nosso sistema</th><th>Sugestão automática</th><th>Mapeamento atual</th></tr></thead>
                <tbody>
                    <?php foreach ($rotulosCamposImportacao as $campo => $rotulo): ?>
                        <?php
                            $colunaSugerida = $analise['mapeamento_sugerido'][$campo] ?? null;
                            $colunaAtual = $analise['mapeamento_efetivo'][$campo] ?? null;
                        ?>
                        <tr>
                            <th><?= e($rotulo) ?></th>
                            <td><?= $colunaSugerida !== null ? e((string)$colunaSugerida) : '—' ?></td>
                            <td><?= $colunaAtual !== null ? e((string)$colunaAtual) : 'Não associado' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <section class="cartao">
            <h3>Corrigir ou confirmar o mapeamento</h3>
            <p class="ajuda">
                Confirma que cada campo corresponde à coluna correta no ficheiro de origem.
                Podes deixar campos opcionais por preencher. O nome completo é obrigatório.
                A mesma coluna não pode ser usada duas vezes.
            </p>
            <form method="post" action="admin/importar-dados.php" enctype="multipart/form-data">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="validar_mapeamento">
                <div class="campo">
                    <label for="ficheiro_csv_mapeamento">Selecionar novamente o mesmo ficheiro CSV</label>
                    <input type="file" id="ficheiro_csv_mapeamento" name="ficheiro_csv" accept=".csv,text/csv" required>
                </div>
                <?php foreach ($rotulosCamposImportacao as $campo => $rotulo): ?>
                    <?php $selecionado = (string)($analise['mapeamento_efetivo'][$campo] ?? ''); ?>
                    <div class="campo">
                        <label for="mapeamento_<?= e($campo) ?>"><?= e($rotulo) ?><?= $campo === 'nome_completo' ? ' (obrigatório)' : '' ?></label>
                        <select id="mapeamento_<?= e($campo) ?>" name="mapeamento[<?= e($campo) ?>]">
                            <option value="">— Não importar este campo —</option>
                            <?php foreach ($analise['cabecalhos'] as $cabecalho): ?>
                                <option value="<?= e((string)$cabecalho) ?>" <?= $selecionado === (string)$cabecalho ? 'selected' : '' ?>>
                                    <?= e((string)$cabecalho) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="botao">Validar mapeamento e simular</button>
            </form>
        </section>

        <?php if (is_array($validacao)): ?>
            <h3>Validação dos registos de pacientes</h3>
            <div class="tabela-scroll">
                <table class="tabela">
                    <tbody>
                        <tr><th>Registos com campos válidos</th><td><?= (int)$validacao['validos'] ?></td></tr>
                        <tr><th>Registos com erros</th><td><?= (int)$validacao['invalidos'] ?></td></tr>
                        <?php if (!empty($validacao['comparacao_base_dados_disponivel']) && $validacao['potenciais_novos_sem_correspondencia'] !== null): ?>
                            <tr><th>Potenciais novos (sem correspondência nas regras testadas)</th><td><?= (int)$validacao['potenciais_novos_sem_correspondencia'] ?></td></tr>
                        <?php endif; ?>
                        <tr><th>Possíveis duplicados dentro do ficheiro</th><td><?= (int)$validacao['possiveis_duplicados_ficheiro'] ?></td></tr>
                        <tr><th>Correspondências por email</th><td><?= (int)$validacao['correspondencias_email_base_dados'] ?></td></tr>
                        <tr><th>Correspondências por telefone</th><td><?= (int)$validacao['correspondencias_telefone_base_dados'] ?></td></tr>
                        <tr><th>Correspondências por nome + nascimento</th><td><?= (int)$validacao['correspondencias_nome_data_base_dados'] ?></td></tr>
                        <tr><th>Registos com pelo menos uma correspondência</th><td><?= (int)$validacao['total_correspondencias_base_dados'] ?></td></tr>
                        <tr><th>Registos sem email</th><td><?= (int)$validacao['sem_email'] ?></td></tr>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($validacao['mapeamento_completo'])): ?>
                <h3>Revisão individual dos registos</h3>
                <p class="ajuda">Cada linha recebe um estado preliminar. Por segurança, esta lista mostra apenas o número da linha e o motivo — nunca os valores pessoais do paciente. São apresentadas até 50 linhas por pedido.</p>
                <div class="tabela-scroll">
                    <table class="tabela">
                        <thead><tr><th>Estado</th><th>Quantidade</th></tr></thead>
                        <tbody>
                            <tr><th>Inválidos</th><td><?= (int)($validacao['estados_registos']['invalidos'] ?? 0) ?></td></tr>
                            <tr><th>Possíveis duplicados no ficheiro</th><td><?= (int)($validacao['estados_registos']['possiveis_duplicados'] ?? 0) ?></td></tr>
                            <tr><th>Possíveis correspondências existentes</th><td><?= (int)($validacao['estados_registos']['correspondencias_existentes'] ?? 0) ?></td></tr>
                            <tr><th>Potenciais novos</th><td><?= (int)($validacao['estados_registos']['potenciais_novos'] ?? 0) ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="tabela-scroll">
                    <table class="tabela">
                        <thead><tr><th>Linha CSV</th><th>Estado preliminar</th><th>Motivo</th></tr></thead>
                        <tbody>
                            <?php foreach (($validacao['revisao_registos'] ?? []) as $item): ?>
                                <tr>
                                    <td><?= (int)$item['linha'] ?></td>
                                    <td><?= e((string)$item['estado']) ?></td>
                                    <td><?= e((string)$item['motivo']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($validacao['revisao_registos_limitada'])): ?>
                    <p class="ajuda">A lista está limitada às primeiras 50 linhas. Os totais acima incluem todos os registos analisados.</p>
                <?php endif; ?>
                <div class="alerta alerta-info" role="status">Estes estados são sugestões para revisão humana, não decisões de importação. Uma correspondência pode pertencer a outra pessoa e um potencial novo pode já existir com outros dados.</div>
            <?php endif; ?>

            <?php if (empty($validacao['mapeamento_completo'])): ?>
                <div class="alerta alerta-erro" role="alert">
                    Não foi possível identificar a coluna obrigatória do nome do paciente. Nenhuma validação de conteúdo foi executada.
                </div>
            <?php endif; ?>

            <?php if (empty($validacao['comparacao_base_dados_disponivel'])): ?>
                <div class="alerta alerta-erro" role="alert">
                    A comparação com a base de dados não ficou disponível. Não interpretes a ausência de correspondências como novos pacientes.
                </div>
            <?php elseif ($validacao['potenciais_novos_sem_correspondencia'] !== null): ?>
                <div class="alerta alerta-info" role="status">
                    A simulação identificou <?= (int)$validacao['potenciais_novos_sem_correspondencia'] ?> registo(s) válido(s) sem correspondência por email, telefone ou nome/data. Isto não garante que sejam pacientes novos: ainda podem existir duplicados por outros identificadores ou dados incompletos.
                </div>
            <?php endif; ?>

            <?php if ((int)$validacao['total_correspondencias_base_dados'] > 0): ?>
                <div class="alerta alerta-info" role="status">
                    Foram encontradas <?= (int)$validacao['total_correspondencias_base_dados'] ?> linha(s) com pelo menos uma possível correspondência na base atual. Um email, telefone ou nome/data coincidente não prova que seja a mesma pessoa; cada caso exige revisão humana. Não juntámos nem alterámos registos.
                </div>
            <?php endif; ?>

            <?php if ((int)$validacao['possiveis_duplicados_ficheiro'] > 0): ?>
                <div class="alerta alerta-info" role="status">
                    Existem possíveis duplicados dentro do próprio ficheiro. Podem ser pessoas diferentes que partilham contacto; cada caso precisa de revisão humana.
                </div>
            <?php endif; ?>

            <?php if (!empty($validacao['amostra_revisao'])): ?>
                <h3>Primeiras linhas que precisam de revisão</h3>
                <div class="tabela-scroll">
                    <table class="tabela">
                        <thead><tr><th>Linha CSV</th><th>Motivo</th></tr></thead>
                        <tbody>
                            <?php foreach ($validacao['amostra_revisao'] as $itemRevisao): ?>
                                <tr>
                                    <td><?= (int)$itemRevisao['linha'] ?></td>
                                    <td><?= e((string)$itemRevisao['motivo']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <h3>Colunas identificadas</h3>
        <ul>
            <?php foreach ($analise['cabecalhos'] as $cabecalho): ?>
                <li><?= e((string)$cabecalho) ?></li>
            <?php endforeach; ?>
        </ul>

        <h3>Amostra dos primeiros registos</h3>
        <p class="ajuda">São apresentados no máximo 10 registos e os valores são truncados. A pré-visualização pode conter dados pessoais; utiliza-a apenas num computador autorizado e não partilhes capturas ou exportações sem autorização.</p>
        <div class="tabela-scroll">
            <table class="tabela">
                <thead>
                    <tr>
                        <?php foreach (array_slice($analise['cabecalhos'], 0, 8) as $cabecalho): ?>
                            <th><?= e((string)$cabecalho) ?></th>
                        <?php endforeach; ?>
                        <?php if (count($analise['cabecalhos']) > 8): ?>
                            <th>Outras colunas</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($analise['amostra'] as $linha): ?>
                        <tr>
                            <?php foreach (array_slice($analise['cabecalhos'], 0, 8) as $cabecalho): ?>
                                <td><?= e((string)($linha[$cabecalho] ?? '')) ?></td>
                            <?php endforeach; ?>
                            <?php if (count($analise['cabecalhos']) > 8): ?>
                                <td>+<?= count($analise['cabecalhos']) - 8 ?> colunas</td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="alerta alerta-info" role="status">
            <strong>Importação não executada.</strong>
            O sistema sugeriu o mapeamento, validou alguns campos e comparou email, telefone normalizado e nome/data
            de nascimento apenas como pistas de possível correspondência;
            não verificou relações clínicas nem fundiu registos. Nenhum dado foi inserido, atualizado ou apagado.
        </div>
    </section>
<?php endif; ?>

<section class="cartao">
    <h2>O que esta ferramenta ainda não faz</h2>
    <ul>
        <li>Não liga diretamente a bases de dados ou APIs de programas externos.</li>
        <li>Não importa ficheiros Excel XLS/XLSX, XML, SQL, imagens ou documentos nesta versão.</li>
        <li>Não cria nem atualiza pacientes, contas, funcionários, consultas ou pagamentos.</li>
        <li>As correspondências são apenas por email, telefone normalizado e nome/data de nascimento; não há análise documental nem fusão automática.</li>
        <li>Não guarda ficheiros ou sessões de importação de forma persistente; é necessário voltar a selecionar o CSV para confirmar o mapeamento.</li>
        <li>Não executa importações reais nem cria fichas, contas, consultas, pagamentos ou relações.</li>
        <li>Não substitui o backup, o mapeamento completo das relações nem a aprovação da clínica.</li>
    </ul>
    <p class="ajuda">
        Estas capacidades serão acrescentadas por fases, depois de conhecermos os formatos
        e as regras do sistema que pretendemos migrar.
    </p>
</section>

<?php require __DIR__ . '/../includes/rodape.php'; ?>
