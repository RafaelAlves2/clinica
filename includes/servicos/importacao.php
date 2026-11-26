<?php
declare(strict_types=1);

/**
 * Leitura e pré-validação de exportações CSV para migração.
 *
 * Esta primeira versão é deliberadamente read-only: não grava registos de
 * pacientes, contas, consultas ou pagamentos na base de dados.
 */

function importacao_normalizar_cabecalho(string $valor): string
{
    $valor = mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', $valor)), 'UTF-8');
    $valor = strtr($valor, [
        'á'=>'a', 'à'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a',
        'é'=>'e', 'è'=>'e', 'ê'=>'e', 'ë'=>'e',
        'í'=>'i', 'ì'=>'i', 'î'=>'i', 'ï'=>'i',
        'ó'=>'o', 'ò'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o',
        'ú'=>'u', 'ù'=>'u', 'û'=>'u', 'ü'=>'u',
        'ç'=>'c', 'ñ'=>'n',
    ]);
    if (function_exists('iconv')) {
        $transliterado = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
        if (is_string($transliterado)) {
            $valor = $transliterado;
        }
    }
    $valor = preg_replace('/[^a-z0-9]+/', '_', $valor) ?? '';
    return trim($valor, '_');
}

/**
 * Sugere o mapeamento dos cabeçalhos de uma exportação para o modelo local.
 * Só usa correspondências exatas após normalização; não tenta adivinhar dados
 * clínicos nem associa uma coluna a mais do que um campo.
 *
 * @return array<string,string> campo interno => cabeçalho original
 */
function importacao_sugerir_mapeamento_pacientes(array $cabecalhos): array
{
    $aliases = [
        'nome_completo' => [
            'nome_completo', 'nome', 'nome_paciente', 'nome_cliente',
            'nome_do_paciente', 'nome_do_cliente', 'full_name', 'patient_name',
            'client_name', 'name',
        ],
        'email' => [
            'email', 'e_mail', 'correio_eletronico', 'email_address', 'mail',
        ],
        'telemovel' => [
            'telemovel', 'telefone', 'telefone_movel', 'telemovel_contacto',
            'telefone_contacto', 'numero_telefone', 'mobile', 'phone',
        ],
        'data_nascimento' => [
            'data_nascimento', 'nascimento', 'data_de_nascimento',
            'birth_date', 'date_of_birth', 'birthdate', 'dob',
        ],
        'numero_utente_sns' => [
            'numero_utente_sns', 'utente_sns', 'numero_sns', 'sns_number',
            'sns',
        ],
    ];

    $porNome = [];
    foreach ($cabecalhos as $cabecalho) {
        $normalizado = importacao_normalizar_cabecalho((string)$cabecalho);
        if ($normalizado !== '') {
            $porNome[$normalizado] = (string)$cabecalho;
        }
    }

    $resultado = [];
    $cabecalhosUsados = [];
    foreach ($aliases as $campo => $opcoes) {
        foreach ($opcoes as $alias) {
            $chave = importacao_normalizar_cabecalho($alias);
            if (isset($porNome[$chave]) && !isset($cabecalhosUsados[$chave])) {
                $resultado[$campo] = $porNome[$chave];
                $cabecalhosUsados[$chave] = true;
                break;
            }
        }
    }
    return $resultado;
}

/** Converte formatos de data comuns para YYYY-MM-DD sem corrigir datas inválidas. */
function importacao_data_iso(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }
    foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d.m.Y'] as $formato) {
        $data = DateTimeImmutable::createFromFormat($formato, $valor);
        $erros = DateTimeImmutable::getLastErrors();
        if ($data !== false
            && ($erros === false || ((int)$erros['warning_count'] === 0 && (int)$erros['error_count'] === 0))
            && $data->format(substr($formato, 1)) === $valor) {
            return $data->format('Y-m-d');
        }
    }
    return null;
}

/** @return array{delimitador:string,campos:int} */
function importacao_detectar_delimitador(string $primeiraLinha): array
{
    $melhor = ['delimitador' => ';', 'campos' => 0];
    foreach ([";", ",", "\t", "|"] as $delimitador) {
        $campos = str_getcsv($primeiraLinha, $delimitador, '"', '');
        $quantidade = is_array($campos) ? count($campos) : 0;
        if ($quantidade > $melhor['campos']) {
            $melhor = ['delimitador' => $delimitador, 'campos' => $quantidade];
        }
    }
    return $melhor;
}

/**
 * @return array{
 *   ok:bool,erro?:string,avisos?:array,delimitador?:string,encoding?:string,
 *   cabecalhos?:array,linhas?:int,linhas_vazias?:int,linhas_irregulares?:int,
 *   duplicados_email?:int,colunas_sensiveis?:array,amostra?:array,
 *   mapeamento_sugerido?:array,mapeamento_efetivo?:array,modo_mapeamento?:string,
 *   registos_mapeados?:array
 * }
 */
function importacao_analisar_csv(string $conteudo, ?array $mapeamentoManual = null): array
{
    if ($conteudo === '') {
        return ['ok' => false, 'erro' => 'O ficheiro está vazio.'];
    }
    $encoding = 'UTF-8';
    if (str_starts_with($conteudo, "\xFF\xFE")) {
        $conteudo = mb_convert_encoding(substr($conteudo, 2), 'UTF-8', 'UTF-16LE');
        $encoding = 'UTF-16 LE convertido para UTF-8';
    } elseif (str_starts_with($conteudo, "\xFE\xFF")) {
        $conteudo = mb_convert_encoding(substr($conteudo, 2), 'UTF-8', 'UTF-16BE');
        $encoding = 'UTF-16 BE convertido para UTF-8';
    } elseif (str_starts_with($conteudo, "\xEF\xBB\xBF")) {
        $conteudo = substr($conteudo, 3);
        $encoding = 'UTF-8 com BOM';
    } elseif (!mb_check_encoding($conteudo, 'UTF-8')) {
        $convertido = function_exists('iconv')
            ? @iconv('Windows-1252', 'UTF-8//IGNORE', $conteudo)
            : false;
        if (!is_string($convertido) || !mb_check_encoding($convertido, 'UTF-8')) {
            return ['ok' => false, 'erro' => 'Não foi possível reconhecer a codificação. Exporta o ficheiro em UTF-8.'];
        }
        $conteudo = $convertido;
        $encoding = 'Windows-1252 convertido para UTF-8';
    }

    if (str_contains($conteudo, "\0")) {
        return ['ok' => false, 'erro' => 'O ficheiro parece conter dados binários. Exporta-o como CSV em UTF-8.'];
    }

    $primeiraLinha = strtok($conteudo, "\r\n");
    if (!is_string($primeiraLinha) || trim($primeiraLinha) === '') {
        return ['ok' => false, 'erro' => 'Não foi encontrada uma linha de cabeçalhos.'];
    }

    $detecao = importacao_detectar_delimitador($primeiraLinha);
    if ($detecao['campos'] < 2) {
        return ['ok' => false, 'erro' => 'Não foi possível identificar pelo menos duas colunas. Confirma se o ficheiro é CSV.'];
    }

    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        return ['ok' => false, 'erro' => 'Não foi possível preparar a leitura do ficheiro.'];
    }
    fwrite($stream, $conteudo);
    rewind($stream);

    $cabecalhosRaw = fgetcsv($stream, null, $detecao['delimitador'], '"', '');
    if (!is_array($cabecalhosRaw) || count($cabecalhosRaw) < 2 || count($cabecalhosRaw) > 60) {
        fclose($stream);
        return ['ok' => false, 'erro' => 'O CSV tem um número de colunas inválido. O limite é de 60.'];
    }

    $cabecalhos = [];
    $normalizados = [];
    $duplicadosCabecalho = [];
    foreach ($cabecalhosRaw as $indice => $cabecalho) {
        $rotulo = trim((string)$cabecalho);
        if ($indice === 0) {
            $rotulo = preg_replace('/^\xEF\xBB\xBF/', '', $rotulo) ?? $rotulo;
        }
        $normalizado = importacao_normalizar_cabecalho($rotulo);
        if ($rotulo === '' || $normalizado === '') {
            fclose($stream);
            return ['ok' => false, 'erro' => 'Existe uma coluna sem nome. Corrige os cabeçalhos antes de continuar.'];
        }
        if (isset($normalizados[$normalizado])) {
            $duplicadosCabecalho[] = $rotulo;
        }
        $normalizados[$normalizado] = $indice;
        $cabecalhos[] = $rotulo;
    }
    if ($duplicadosCabecalho !== []) {
        fclose($stream);
        return ['ok' => false, 'erro' => 'Existem cabeçalhos repetidos ou equivalentes: ' . implode(', ', array_unique($duplicadosCabecalho)) . '. Renomeia essas colunas para evitar associações erradas.'];
    }

    $mapeamentoSugerido = importacao_sugerir_mapeamento_pacientes($cabecalhos);
    $camposPermitidos = [
        'nome_completo',
        'email',
        'telemovel',
        'data_nascimento',
        'numero_utente_sns',
    ];
    $mapeamentoEfetivo = $mapeamentoSugerido;
    $modoMapeamento = 'automatico';

    if ($mapeamentoManual !== null) {
        $mapeamentoEfetivo = [];
        $cabecalhosUsadosManual = [];
        foreach ($mapeamentoManual as $campo => $cabecalhoEscolhido) {
            if (!in_array((string)$campo, $camposPermitidos, true)) {
                fclose($stream);
                return ['ok' => false, 'erro' => 'O mapeamento contém um campo de destino não permitido.'];
            }
            $cabecalhoEscolhido = trim((string)$cabecalhoEscolhido);
            if ($cabecalhoEscolhido === '') {
                continue;
            }
            $indiceEscolhido = array_search($cabecalhoEscolhido, $cabecalhos, true);
            if ($indiceEscolhido === false) {
                fclose($stream);
                return ['ok' => false, 'erro' => 'Uma das colunas escolhidas já não existe no ficheiro. Volta a selecionar o CSV.'];
            }
            if (isset($cabecalhosUsadosManual[$indiceEscolhido])) {
                fclose($stream);
                return ['ok' => false, 'erro' => 'A mesma coluna de origem não pode ser associada a dois campos diferentes.'];
            }
            $cabecalhosUsadosManual[$indiceEscolhido] = true;
            $mapeamentoEfetivo[(string)$campo] = $cabecalhoEscolhido;
        }

        if (empty($mapeamentoEfetivo['nome_completo'])) {
            fclose($stream);
            return ['ok' => false, 'erro' => 'É obrigatório associar uma coluna ao campo Nome completo.'];
        }
        $modoMapeamento = 'manual';
    }

    $indicesMapeados = [];
    foreach ($mapeamentoEfetivo as $campo => $cabecalho) {
        $indice = array_search($cabecalho, $cabecalhos, true);
        if ($indice !== false) {
            $indicesMapeados[$campo] = (int)$indice;
        }
    }

    $emailIndice = $indicesMapeados['email'] ?? null;

    $sensivelPalavras = [
        'diagnostico', 'anamnese', 'processo_clinico', 'observacao_clinica',
        'radiografia', 'radiografias', 'medicacao', 'alergia', 'historico_saude',
        'dados_clinicos', 'doenca', 'patologia',
    ];
    $colunasSensiveis = [];
    foreach ($normalizados as $nome => $indice) {
        foreach ($sensivelPalavras as $palavra) {
            if (str_contains($nome, $palavra)) {
                $colunasSensiveis[] = $cabecalhos[$indice];
                break;
            }
        }
    }

    $linhas = 0;
    $linhasVazias = 0;
    $linhasIrregulares = 0;
    $duplicadosEmail = 0;
    $emailsVistos = [];
    $amostra = [];
    $registosMapeados = [];
    $maxLinhas = 10000;
    $maxCelulaBytes = 20000;

    while (($registo = fgetcsv($stream, null, $detecao['delimitador'], '"', '')) !== false) {
        if ($registo === [null] || $registo === []) {
            $linhasVazias++;
            continue;
        }
        $todosVazios = true;
        foreach ($registo as $valor) {
            if (trim((string)$valor) !== '') {
                $todosVazios = false;
                break;
            }
        }
        if ($todosVazios) {
            $linhasVazias++;
            continue;
        }

        $linhas++;
        if ($linhas > $maxLinhas) {
            fclose($stream);
            return ['ok' => false, 'erro' => 'O ficheiro ultrapassa o limite de 10 000 registos por pré-visualização. Divide a exportação em ficheiros mais pequenos.'];
        }

        if (count($registo) !== count($cabecalhos)) {
            $linhasIrregulares++;
            if (count($registo) > count($cabecalhos)) {
                $registosMapeados[] = [];
                continue;
            }
            $registo = array_pad($registo, count($cabecalhos), '');
        }

        $linha = [];
        foreach ($cabecalhos as $indice => $cabecalho) {
            $valor = (string)($registo[$indice] ?? '');
            if (strlen($valor) > $maxCelulaBytes) {
                fclose($stream);
                return ['ok' => false, 'erro' => 'Foi encontrada uma célula demasiado grande (mais de 20 KB). Verifica se o CSV inclui anexos codificados ou conteúdo indevido.'];
            }
            $linha[$cabecalho] = importacao_mascarar_valor_amostra($cabecalho, trim($valor), $colunasSensiveis);
        }

        $registoMapeado = [];
        foreach ($indicesMapeados as $campo => $indiceMapeado) {
            $registoMapeado[$campo] = trim((string)($registo[$indiceMapeado] ?? ''));
        }
        $registosMapeados[] = $registoMapeado;

        if ($emailIndice !== null) {
            $email = mb_strtolower(trim((string)($registo[$emailIndice] ?? '')));
            if ($email !== '') {
                if (isset($emailsVistos[$email])) {
                    $duplicadosEmail++;
                }
                $emailsVistos[$email] = true;
            }
        }

        if (count($amostra) < 10) {
            $amostra[] = $linha;
        }
    }
    fclose($stream);

    if ($linhas === 0) {
        return ['ok' => false, 'erro' => 'O ficheiro contém cabeçalhos, mas não tem registos para analisar.'];
    }

    $avisos = [];
    if ($linhasIrregulares > 0) {
        $avisos[] = $linhasIrregulares . ' linha(s) têm um número de colunas diferente do cabeçalho. Devem ser revistas antes de qualquer importação.';
    }
    if ($duplicadosEmail > 0) {
        $avisos[] = $duplicadosEmail . ' email(s) repetidos dentro do próprio ficheiro. Isto não prova que sejam duplicados na base de dados atual.';
    }
    if ($colunasSensiveis !== []) {
        $avisos[] = 'Foram detetados cabeçalhos potencialmente clínicos. Utiliza apenas dados autorizados e um ambiente protegido; esta pré-visualização não guarda o ficheiro.';
    }

    return [
        'ok' => true,
        'delimitador' => $detecao['delimitador'] === "\t" ? 'TAB' : $detecao['delimitador'],
        'encoding' => $encoding,
        'cabecalhos' => $cabecalhos,
        'linhas' => $linhas,
        'linhas_vazias' => $linhasVazias,
        'linhas_irregulares' => $linhasIrregulares,
        'duplicados_email' => $duplicadosEmail,
        'colunas_sensiveis' => $colunasSensiveis,
        'avisos' => $avisos,
        'amostra' => $amostra,
        'mapeamento_sugerido' => $mapeamentoSugerido,
        'mapeamento_efetivo' => $mapeamentoEfetivo,
        'modo_mapeamento' => $modoMapeamento,
        'registos_mapeados' => $registosMapeados,
    ];
}


/**
 * Procura correspondências exatas por email na base local.
 * Devolve apenas o indicador de existência; não expõe dados de outros pacientes.
 *
 * @return array{ok:bool,emails_existentes?:array<string,bool>,erro?:string}
 */
function importacao_emails_existentes(PDO $pdo, array $emails): array
{
    $emails = array_values(array_unique(array_filter(array_map(
        static fn($email): string => mb_strtolower(trim((string)$email)),
        $emails
    ), static fn(string $email): bool => $email !== '')));

    if ($emails === []) {
        return ['ok' => true, 'emails_existentes' => []];
    }

    $encontrados = [];
    try {
        foreach (array_chunk($emails, 200) as $lote) {
            $marcadores = implode(',', array_fill(0, count($lote), '?'));
            $sql = "SELECT p.email AS email_paciente, u.email AS email_conta
                    FROM pacientes p
                    LEFT JOIN utilizadores u ON u.id = p.utilizador_id
                    WHERE LOWER(TRIM(COALESCE(p.email, ''))) IN ($marcadores)
                       OR LOWER(TRIM(COALESCE(u.email, ''))) IN ($marcadores)";
            $st = $pdo->prepare($sql);
            $st->execute(array_merge($lote, $lote));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $registo) {
                foreach (['email_paciente', 'email_conta'] as $campo) {
                    $email = mb_strtolower(trim((string)($registo[$campo] ?? '')));
                    if ($email !== '') {
                        $encontrados[$email] = true;
                    }
                }
            }

            // Também deteta contas de paciente ainda não ligadas a uma ficha.
            $sqlContas = "SELECT email FROM utilizadores
                          WHERE papel = 'paciente'
                            AND LOWER(TRIM(email)) IN ($marcadores)";
            $stContas = $pdo->prepare($sqlContas);
            $stContas->execute($lote);
            foreach ($stContas->fetchAll(PDO::FETCH_COLUMN) as $emailConta) {
                $email = mb_strtolower(trim((string)$emailConta));
                if ($email !== '') {
                    $encontrados[$email] = true;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Importação: falha ao comparar emails com a base de dados: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não foi possível comparar os emails com os registos atuais.'];
    }

    return ['ok' => true, 'emails_existentes' => $encontrados];
}

/**
 * Valida registos de pacientes usando o mapeamento efetivo e compara
 * emails, telefones e nome/data com a base atual. Não altera dados.
 *
 * @return array<string,mixed>
 */
function importacao_validar_registos_pacientes(PDO $pdo, array $registos, array $mapeamento): array
{
    $total = count($registos);
    if (!isset($mapeamento['nome_completo'])) {
        return [
            'mapeamento_completo' => false,
            'campos_obrigatorios_em_falta' => ['nome_completo'],
            'total' => $total,
            'validos' => 0,
            'invalidos' => $total,
            'possiveis_duplicados_ficheiro' => 0,
            'correspondencias_email_base_dados' => 0,
            'correspondencias_telefone_base_dados' => 0,
            'correspondencias_nome_data_base_dados' => 0,
            'total_correspondencias_base_dados' => 0,
            'potenciais_novos_sem_correspondencia' => 0,
            'sem_email' => $total,
            'comparacao_base_dados_disponivel' => true,
            'amostra_revisao' => [],
        ];
    }

    $validos = 0;
    $invalidos = 0;
    $linhasValidas = [];
    $semEmail = 0;
    $emailsDoFicheiro = [];
    $chavesVistas = [];
    $possiveisDuplicados = 0;
    $linhasRevisao = [];
    $duplicadosIndices = [];
    $problemasPorIndice = [];

    foreach ($registos as $indice => $registo) {
        $problemas = [];
        $nome = trim((string)($registo['nome_completo'] ?? ''));
        $emailRaw = trim((string)($registo['email'] ?? ''));
        $email = mb_strtolower($emailRaw);
        $telefoneRaw = trim((string)($registo['telemovel'] ?? ''));
        $telefone = preg_replace('/\D+/', '', $telefoneRaw) ?? '';
        $nascimentoRaw = trim((string)($registo['data_nascimento'] ?? ''));
        $snsRaw = trim((string)($registo['numero_utente_sns'] ?? ''));

        if ($nome === '' || mb_strlen($nome) > 150) {
            $problemas[] = 'Nome em falta ou demasiado longo';
        }
        if ($emailRaw !== '' && (!filter_var($emailRaw, FILTER_VALIDATE_EMAIL) || mb_strlen($emailRaw) > 190)) {
            $problemas[] = 'Email inválido';
        }
        if ($telefoneRaw !== '' && (strlen($telefone) < 7 || strlen($telefone) > 15)) {
            $problemas[] = 'Telefone inválido';
        }
        $nascimentoIso = null;
        if ($nascimentoRaw !== '') {
            $nascimentoIso = importacao_data_iso($nascimentoRaw);
            if ($nascimentoIso === null) {
                $problemas[] = 'Data de nascimento inválida ou não reconhecida';
            } elseif ($nascimentoIso > (new DateTimeImmutable('today'))->format('Y-m-d')) {
                $problemas[] = 'Data de nascimento no futuro';
            }
        }
        if ($snsRaw !== '') {
            $sns = preg_replace('/\D+/', '', $snsRaw) ?? '';
            if (strlen($sns) !== 9) {
                $problemas[] = 'Número SNS deve conter 9 algarismos';
            }
        }

        if ($email === '') {
            $semEmail++;
        }

        $chavesLinha = [];
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $chavesLinha[] = 'email:' . $email;
        }
        if ($telefone !== '' && strlen($telefone) >= 7) {
            $chavesLinha[] = 'telefone:' . $telefone;
        }
        if ($nome !== '' && $nascimentoIso !== null) {
            $chavesLinha[] = 'nome-data:' . importacao_normalizar_cabecalho($nome) . ':' . $nascimentoIso;
        }

        $duplicadoNaLinha = false;
        foreach ($chavesLinha as $chaveDuplicado) {
            if (isset($chavesVistas[$chaveDuplicado])) {
                $duplicadoNaLinha = true;
                $indiceAnterior = (int)$chavesVistas[$chaveDuplicado];
                if (!isset($duplicadosIndices[$indiceAnterior])) {
                    $possiveisDuplicados++;
                }
                $duplicadosIndices[$indiceAnterior] = true;
            } else {
                $chavesVistas[$chaveDuplicado] = $indice;
            }
        }
        if ($duplicadoNaLinha) {
            if (!isset($duplicadosIndices[$indice])) {
                $possiveisDuplicados++;
            }
            $duplicadosIndices[$indice] = true;
            if (count($linhasRevisao) < 20) {
                $linhasRevisao[] = [
                    'linha' => $indice + 2,
                    'motivo' => 'Possível duplicado por email, telefone ou nome/data dentro do próprio ficheiro',
                ];
            }
        }

        if ($problemas === []) {
            $validos++;
            $linhasValidas[$indice] = true;
        } else {
            $invalidos++;
            $problemasPorIndice[$indice] = $problemas;
            if (count($linhasRevisao) < 20) {
                $linhasRevisao[] = [
                    'linha' => $indice + 2,
                    'motivo' => implode('; ', $problemas),
                ];
            }
        }

        if ($email !== '' && isset($emailsDoFicheiro[$email])) {
            $indiceAnteriorEmail = (int)$emailsDoFicheiro[$email];
            if (!isset($duplicadosIndices[$indiceAnteriorEmail])) {
                $possiveisDuplicados++;
            }
            $duplicadosIndices[$indiceAnteriorEmail] = true;
            if (!isset($duplicadosIndices[$indice])) {
                $possiveisDuplicados++;
            }
            $duplicadosIndices[$indice] = true;
            if (count($linhasRevisao) < 20) {
                $linhasRevisao[] = [
                    'linha' => $indice + 2,
                    'motivo' => 'Email repetido no ficheiro',
                ];
            }
        }
        if ($email !== '' && !isset($emailsDoFicheiro[$email])) {
            $emailsDoFicheiro[$email] = $indice;
        }
    }

    $comparacao = importacao_comparar_registos_pacientes($pdo, $registos);
    $indicesComCorrespondencia = $comparacao['indices_correspondencia'] ?? [];
    $potenciaisNovos = null;
    if (!empty($comparacao['ok'])) {
        $potenciaisNovos = 0;
        foreach ($linhasValidas as $indice => $_) {
            if (!isset($indicesComCorrespondencia[$indice])) {
                $potenciaisNovos++;
            }
        }
    }

    $estadosRegistos = [
        'invalidos' => 0,
        'possiveis_duplicados' => 0,
        'correspondencias_existentes' => 0,
        'potenciais_novos' => 0,
    ];
    $revisaoRegistos = [];
    foreach ($registos as $indice => $_registo) {
        $temProblemas = isset($problemasPorIndice[$indice]);
        $duplicado = isset($duplicadosIndices[$indice]);
        $correspondencia = isset($indicesComCorrespondencia[$indice]);
        if ($temProblemas) {
            $estado = 'inválido';
            $motivo = implode('; ', $problemasPorIndice[$indice]);
            $estadosRegistos['invalidos']++;
        } elseif ($duplicado) {
            $estado = 'possível duplicado no ficheiro';
            $motivo = 'Partilha email, telefone ou nome/data com outra linha do ficheiro.';
            $estadosRegistos['possiveis_duplicados']++;
        } elseif ($correspondencia) {
            $estado = 'possível correspondência existente';
            $motivo = 'Coincide por pelo menos um dos critérios pesquisados na base atual.';
            $estadosRegistos['correspondencias_existentes']++;
        } else {
            $estado = !empty($comparacao['ok']) ? 'potencial novo' : 'comparação indisponível';
            $motivo = !empty($comparacao['ok'])
                ? 'Sem correspondência nas regras pesquisadas; não confirma que seja um paciente novo.'
                : 'Não foi possível comparar com a base de dados.';
            $estadosRegistos['potenciais_novos']++;
        }
        if (count($revisaoRegistos) < 50) {
            $revisaoRegistos[] = [
                'linha' => $indice + 2,
                'estado' => $estado,
                'motivo' => $motivo,
            ];
        }
    }

    return [
        'mapeamento_completo' => true,
        'campos_obrigatorios_em_falta' => [],
        'total' => $total,
        'validos' => $validos,
        'invalidos' => $invalidos,
        'possiveis_duplicados_ficheiro' => $possiveisDuplicados,
        'correspondencias_email_base_dados' => (int)($comparacao['por_email'] ?? 0),
        'correspondencias_telefone_base_dados' => (int)($comparacao['por_telefone'] ?? 0),
        'correspondencias_nome_data_base_dados' => (int)($comparacao['por_nome_data'] ?? 0),
        'total_correspondencias_base_dados' => (int)($comparacao['total'] ?? 0),
        'potenciais_novos_sem_correspondencia' => $potenciaisNovos,
        'sem_email' => $semEmail,
        'comparacao_base_dados_disponivel' => !empty($comparacao['ok']),
        'amostra_revisao' => $linhasRevisao,
        'estados_registos' => $estadosRegistos,
        'revisao_registos' => $revisaoRegistos,
        'revisao_registos_limitada' => $total > count($revisaoRegistos),
    ];
}


/**
 * Compara os registos importados com pacientes existentes usando email,
 * telefone normalizado e nome+data de nascimento. Devolve apenas indicadores.
 *
 * @return array<string,mixed>
 */
function importacao_comparar_registos_pacientes(PDO $pdo, array $registos): array
{
    $emails = [];
    $telefones = [];
    $paresNomeData = [];

    foreach ($registos as $registo) {
        $email = mb_strtolower(trim((string)($registo['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[$email] = $email;
        }

        $telefone = preg_replace('/\D+/', '', (string)($registo['telemovel'] ?? '')) ?? '';
        if (strlen($telefone) >= 7 && strlen($telefone) <= 15) {
            $telefones[$telefone] = $telefone;
        }

        $nome = trim((string)($registo['nome_completo'] ?? ''));
        $nascimento = importacao_data_iso(trim((string)($registo['data_nascimento'] ?? '')));
        if ($nome !== '' && $nascimento !== null) {
            $chave = importacao_normalizar_cabecalho($nome) . '|' . $nascimento;
            $paresNomeData[$chave] = ['nome' => $nome, 'data' => $nascimento];
        }
    }

    $emailResult = importacao_emails_existentes($pdo, array_values($emails));
    if (empty($emailResult['ok'])) {
        return ['ok' => false, 'erro' => (string)($emailResult['erro'] ?? 'Falha na comparação de emails.')];
    }
    $emailsExistentes = $emailResult['emails_existentes'] ?? [];
    $telefonesExistentes = [];
    $nomesDatasExistentes = [];

    try {
        // Retira apenas separadores comuns; não tenta inferir indicativos internacionais.
        $exprTelefone = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(p.telemovel, ''), ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', '')";
        foreach (array_chunk(array_values($telefones), 1000) as $lote) {
            $marcadores = implode(',', array_fill(0, count($lote), '?'));
            $sql = "SELECT DISTINCT $exprTelefone AS telefone_normalizado
                    FROM pacientes p
                    WHERE $exprTelefone IN ($marcadores)";
            $st = $pdo->prepare($sql);
            $st->execute($lote);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $telefone) {
                $normalizado = preg_replace('/\D+/', '', (string)$telefone) ?? '';
                if ($normalizado !== '') {
                    $telefonesExistentes[$normalizado] = true;
                }
            }
        }

        // Agrupa por datas únicas para evitar repetir dezenas/centenas de
        // condições idênticas quando o ficheiro tem muitos pacientes no mesmo dia.
        $datasNascimento = array_values(array_unique(array_map(
            static fn(array $par): string => $par['data'],
            array_values($paresNomeData)
        )));
        foreach (array_chunk($datasNascimento, 500) as $loteDatas) {
            $marcadores = implode(',', array_fill(0, count($loteDatas), '?'));
            $sql = "SELECT DISTINCT LOWER(TRIM(nome_completo)) AS nome_normalizado, data_nascimento
                    FROM pacientes WHERE data_nascimento IN ($marcadores)";
            $st = $pdo->prepare($sql);
            $st->execute($loteDatas);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $registo) {
                $nome = mb_strtolower(trim((string)$registo['nome_normalizado']));
                $data = (string)$registo['data_nascimento'];
                $nomesDatasExistentes[importacao_normalizar_cabecalho($nome) . '|' . $data] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('Importação: falha ao comparar pacientes: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não foi possível comparar telefones e nomes/datas com os registos atuais.'];
    }

    $porEmail = 0;
    $porTelefone = 0;
    $porNomeData = 0;
    $totalCorrespondencias = 0;
    $indicesCorrespondencia = [];

    foreach ($registos as $indice => $registo) {
        $email = mb_strtolower(trim((string)($registo['email'] ?? '')));
        $telefone = preg_replace('/\D+/', '', (string)($registo['telemovel'] ?? '')) ?? '';
        $nome = importacao_normalizar_cabecalho(trim((string)($registo['nome_completo'] ?? '')));
        $data = importacao_data_iso(trim((string)($registo['data_nascimento'] ?? '')));

        $matchEmail = $email !== '' && isset($emailsExistentes[$email]);
        $matchTelefone = $telefone !== '' && isset($telefonesExistentes[$telefone]);
        $matchNomeData = $nome !== '' && $data !== null && isset($nomesDatasExistentes[$nome . '|' . $data]);

        $porEmail += $matchEmail ? 1 : 0;
        $porTelefone += $matchTelefone ? 1 : 0;
        $porNomeData += $matchNomeData ? 1 : 0;
        $temCorrespondencia = $matchEmail || $matchTelefone || $matchNomeData;
        if ($temCorrespondencia) {
            $totalCorrespondencias++;
            $indicesCorrespondencia[$indice] = true;
        }
    }

    return [
        'ok' => true,
        'total' => $totalCorrespondencias,
        'por_email' => $porEmail,
        'por_telefone' => $porTelefone,
        'por_nome_data' => $porNomeData,
        'indices_correspondencia' => $indicesCorrespondencia,
    ];
}


/**
 * Mascara valores identificáveis na pequena amostra apresentada ao administrador.
 * Os valores originais continuam apenas em memória para validação/mapeamento.
 */
function importacao_mascarar_valor_amostra(string $cabecalho, string $valor, array $colunasSensiveis = []): string
{
    if (trim($valor) === '') {
        return '—';
    }
    if (in_array($cabecalho, $colunasSensiveis, true)) {
        return '[conteúdo clínico ocultado]';
    }

    $campo = importacao_normalizar_cabecalho($cabecalho);
    if (preg_match('/(^|_)(nome|name|patient_name|full_name)(_|$)/', $campo)) {
        return '[nome ocultado]';
    }
    if (str_contains($campo, 'email') || str_contains($campo, 'correio') || $campo === 'mail') {
        if (filter_var($valor, FILTER_VALIDATE_EMAIL)) {
            [$local, $dominio] = explode('@', $valor, 2);
            return mb_substr($local, 0, 1) . '•••@' . $dominio;
        }
        return '[email ocultado]';
    }
    if (str_contains($campo, 'telemovel') || str_contains($campo, 'telefone') || str_contains($campo, 'mobile') || $campo === 'phone') {
        $digitos = preg_replace('/\D+/', '', $valor) ?? '';
        return strlen($digitos) >= 3 ? '••• ••• ' . substr($digitos, -3) : '[telefone ocultado]';
    }
    if (str_contains($campo, 'nascimento') || str_contains($campo, 'birth') || $campo === 'dob') {
        $data = importacao_data_iso($valor);
        return $data !== null ? '••/••/' . substr($data, 0, 4) : '[data ocultada]';
    }
    if (str_contains($campo, 'sns') || str_contains($campo, 'utente') || str_contains($campo, 'nif')
        || str_contains($campo, 'documento') || str_contains($campo, 'identificacao')
        || in_array($campo, ['cc', 'bi', 'numero_cartao_cidadao'], true)) {
        $digitos = preg_replace('/\D+/', '', $valor) ?? '';
        return strlen($digitos) >= 2 ? '••••••' . substr($digitos, -2) : '[identificador ocultado]';
    }
    if (str_contains($campo, 'morada') || str_contains($campo, 'endereco') || str_contains($campo, 'address')
        || str_contains($campo, 'postal') || str_contains($campo, 'codigo_postal')
        || str_contains($campo, 'observacao') || str_contains($campo, 'nota')) {
        return '[valor potencialmente pessoal ocultado]';
    }

    return mb_substr($valor, 0, 60);
}
