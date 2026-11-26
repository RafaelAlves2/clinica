<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/servicos/importacao.php';

$testes = [];
$testes['CSV separado por ponto e vírgula'] = static function (): bool {
    $r = importacao_analisar_csv("Nome;Email;Telefone\nAna Silva;ana@example.test;912345678");
    return !empty($r['ok']) && ($r['linhas'] ?? 0) === 1 && ($r['delimitador'] ?? '') === ';';
};
$testes['CSV separado por vírgula'] = static function (): bool {
    $r = importacao_analisar_csv("Name,Email\nAlice,a@example.test");
    return !empty($r['ok']) && ($r['linhas'] ?? 0) === 1 && ($r['delimitador'] ?? '') === ',';
};
$testes['UTF-8 com BOM'] = static function (): bool {
    $r = importacao_analisar_csv("\xEF\xBB\xBFNome;Email\nAna;ana@example.test");
    return !empty($r['ok']) && ($r['cabecalhos'][0] ?? '') === 'Nome';
};
$testes['UTF-16 LE com BOM'] = static function (): bool {
    $texto = "Nome;Email\nAna;ana@example.test";
    $conteudo = "\xFF\xFE" . mb_convert_encoding($texto, 'UTF-16LE', 'UTF-8');
    $r = importacao_analisar_csv($conteudo);
    return !empty($r['ok']) && ($r['encoding'] ?? '') === 'UTF-16 LE convertido para UTF-8';
};
$testes['Windows-1252'] = static function (): bool {
    $texto = "Nome;Observação\nJoão;Informação";
    $conteudo = iconv('UTF-8', 'Windows-1252', $texto);
    $r = importacao_analisar_csv((string)$conteudo);
    return !empty($r['ok'])
        && ($r['encoding'] ?? '') === 'Windows-1252 convertido para UTF-8'
        && ($r['amostra'][0]['Observação'] ?? '') === '[valor potencialmente pessoal ocultado]';
};
$testes['Cabeçalhos repetidos rejeitados'] = static function (): bool {
    $r = importacao_analisar_csv("Nome;Email;email\nAna;a@example.test;b@example.test");
    return empty($r['ok']) && str_contains((string)($r['erro'] ?? ''), 'repetidos');
};
$testes['Linhas irregulares sinalizadas'] = static function (): bool {
    $r = importacao_analisar_csv("Nome;Email\nAna;a@example.test;extra");
    return !empty($r['ok']) && ($r['linhas_irregulares'] ?? 0) === 1;
};
$testes['Emails repetidos sinalizados'] = static function (): bool {
    $r = importacao_analisar_csv("Nome;Email\nAna;a@example.test\nAna Silva;A@example.test");
    return !empty($r['ok']) && ($r['duplicados_email'] ?? 0) === 1;
};
$testes['Colunas clínicas detetadas'] = static function (): bool {
    $r = importacao_analisar_csv("Nome;Diagnóstico\nAna;Exemplo");
    return !empty($r['ok']) && in_array('Diagnóstico', $r['colunas_sensiveis'] ?? [], true);
};
$testes['CSV sem registos rejeitado'] = static function (): bool {
    $r = importacao_analisar_csv("Nome;Email\n");
    return empty($r['ok']);
};
$testes['Mapeamento automático de cabeçalhos portugueses'] = static function (): bool {
    $m = importacao_sugerir_mapeamento_pacientes([
        'Nome do Paciente', 'E-mail', 'Telemóvel', 'Data de Nascimento', 'Número SNS',
    ]);
    return ($m['nome_completo'] ?? '') === 'Nome do Paciente'
        && ($m['email'] ?? '') === 'E-mail'
        && ($m['telemovel'] ?? '') === 'Telemóvel'
        && ($m['data_nascimento'] ?? '') === 'Data de Nascimento'
        && ($m['numero_utente_sns'] ?? '') === 'Número SNS';
};
$testes['Mapeamento não reutiliza colunas'] = static function (): bool {
    $m = importacao_sugerir_mapeamento_pacientes(['Name', 'Full Name']);
    return ($m['nome_completo'] ?? '') === 'Full Name';
};
$testes['Datas reconhecidas e rejeição de datas inválidas'] = static function (): bool {
    return importacao_data_iso('31/12/1990') === '1990-12-31'
        && importacao_data_iso('1990-12-31') === '1990-12-31'
        && importacao_data_iso('31/02/1990') === null;
};
$testes['Validação e correspondência por email na base de dados'] = static function (): bool {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec("CREATE TABLE pacientes (id INTEGER PRIMARY KEY, utilizador_id INTEGER, nome_completo TEXT, email TEXT, telemovel TEXT, data_nascimento TEXT)");
    $pdo->exec("CREATE TABLE utilizadores (id INTEGER PRIMARY KEY, email TEXT, papel TEXT)");
    $pdo->exec("INSERT INTO pacientes (id, nome_completo, email) VALUES (1, 'Paciente Existente', 'existente@example.test')");
    $mapeamento = [
        'nome_completo' => 'Nome',
        'email' => 'E-mail',
        'telemovel' => 'Telefone',
        'data_nascimento' => 'Nascimento',
    ];
    $registos = [
        ['nome_completo' => 'Ana Silva', 'email' => 'existente@example.test', 'telemovel' => '912345678', 'data_nascimento' => '31/12/1990'],
        ['nome_completo' => '', 'email' => 'errado', 'telemovel' => '12', 'data_nascimento' => '31/02/1990'],
    ];
    $r = importacao_validar_registos_pacientes($pdo, $registos, $mapeamento);
    return ($r['validos'] ?? -1) === 1
        && ($r['invalidos'] ?? -1) === 1
        && ($r['correspondencias_email_base_dados'] ?? -1) === 1
        && !empty($r['comparacao_base_dados_disponivel']);
};
$testes['Possíveis duplicados dentro do ficheiro'] = static function (): bool {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec("CREATE TABLE pacientes (id INTEGER PRIMARY KEY, utilizador_id INTEGER, nome_completo TEXT, email TEXT, telemovel TEXT, data_nascimento TEXT)");
    $pdo->exec("CREATE TABLE utilizadores (id INTEGER PRIMARY KEY, email TEXT, papel TEXT)");
    $mapeamento = ['nome_completo' => 'Nome', 'email' => 'Email', 'telemovel' => 'Telefone'];
    $registos = [
        ['nome_completo' => 'Ana Silva', 'email' => 'ana@example.test', 'telemovel' => '912345678'],
        ['nome_completo' => 'Ana Silva', 'email' => 'ANA@example.test', 'telemovel' => '912345678'],
        ['nome_completo' => 'Bruno Costa', 'email' => 'bruno@example.test', 'telemovel' => '912345678'],
    ];
    $r = importacao_validar_registos_pacientes($pdo, $registos, $mapeamento);
    $estados = array_column($r['revisao_registos'] ?? [], 'estado');
    return ($r['possiveis_duplicados_ficheiro'] ?? -1) === 3
        && count(array_filter($estados, static fn(string $estado): bool => $estado === 'possível duplicado no ficheiro')) === 3;
};
$testes['Comparação por telefone e nome/data na base existente'] = static function (): bool {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec("CREATE TABLE pacientes (id INTEGER PRIMARY KEY, utilizador_id INTEGER, nome_completo TEXT, email TEXT, telemovel TEXT, data_nascimento TEXT)");
    $pdo->exec("CREATE TABLE utilizadores (id INTEGER PRIMARY KEY, email TEXT, papel TEXT)");
    $pdo->exec("INSERT INTO pacientes (id, nome_completo, telemovel, data_nascimento) VALUES (1, 'Paciente Telefone', '912 345 678', NULL)");
    $pdo->exec("INSERT INTO pacientes (id, nome_completo, telemovel, data_nascimento) VALUES (2, 'Paciente Maria', NULL, '1985-03-02')");
    $registos = [
        ['nome_completo' => 'Outra Pessoa', 'email' => '', 'telemovel' => '912345678', 'data_nascimento' => ''],
        ['nome_completo' => 'Paciente Maria', 'email' => '', 'telemovel' => '', 'data_nascimento' => '02/03/1985'],
    ];
    $r = importacao_comparar_registos_pacientes($pdo, $registos);
    return !empty($r['ok'])
        && ($r['por_telefone'] ?? -1) === 1
        && ($r['por_nome_data'] ?? -1) === 1
        && ($r['total'] ?? -1) === 2;
};
$testes['Datas de nascimento futuras são rejeitadas'] = static function (): bool {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec("CREATE TABLE pacientes (id INTEGER PRIMARY KEY, utilizador_id INTEGER, nome_completo TEXT, email TEXT, telemovel TEXT, data_nascimento TEXT)");
    $pdo->exec("CREATE TABLE utilizadores (id INTEGER PRIMARY KEY, email TEXT, papel TEXT)");
    $r = importacao_validar_registos_pacientes(
        $pdo,
        [['nome_completo' => 'Ana Silva', 'data_nascimento' => '31/12/2999']],
        ['nome_completo' => 'Nome', 'data_nascimento' => 'Nascimento']
    );
    return ($r['invalidos'] ?? -1) === 1;
};
$testes['Mapeamento manual altera as colunas utilizadas'] = static function (): bool {
    $r = importacao_analisar_csv(
        "Nome antigo;Correio;Contacto\nAna Silva;ana@example.test;912345678",
        ['nome_completo' => 'Nome antigo', 'email' => 'Correio', 'telemovel' => 'Contacto']
    );
    $linha = $r['registos_mapeados'][0] ?? [];
    return !empty($r['ok'])
        && ($r['modo_mapeamento'] ?? '') === 'manual'
        && ($linha['nome_completo'] ?? '') === 'Ana Silva'
        && ($linha['email'] ?? '') === 'ana@example.test'
        && ($linha['telemovel'] ?? '') === '912345678';
};
$testes['Mapeamento manual exige nome completo'] = static function (): bool {
    $r = importacao_analisar_csv("Email;Telefone\na@example.test;912345678", ['email' => 'Email']);
    return empty($r['ok']) && str_contains((string)($r['erro'] ?? ''), 'Nome completo');
};
$testes['Mapeamento manual não permite reutilizar coluna'] = static function (): bool {
    $r = importacao_analisar_csv(
        "Nome;Contacto\nAna;ana@example.test",
        ['nome_completo' => 'Nome', 'email' => 'Contacto', 'telemovel' => 'Contacto']
    );
    return empty($r['ok']) && str_contains((string)($r['erro'] ?? ''), 'não pode ser associada');
};
$testes['Mapeamento manual rejeita coluna que não existe'] = static function (): bool {
    $r = importacao_analisar_csv("Nome;Email\nAna;a@example.test", ['nome_completo' => 'Nome', 'email' => 'Email Antigo']);
    return empty($r['ok']) && str_contains((string)($r['erro'] ?? ''), 'não existe');
};
$testes['Mapeamento manual rejeita campo de destino desconhecido'] = static function (): bool {
    $r = importacao_analisar_csv("Nome;Email\nAna;a@example.test", ['nome_completo' => 'Nome', 'password_hash' => 'Email']);
    return empty($r['ok']) && str_contains((string)($r['erro'] ?? ''), 'não permitido');
};
$testes['Simulação conta potenciais novos sem criar registos'] = static function (): bool {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec("CREATE TABLE pacientes (id INTEGER PRIMARY KEY, utilizador_id INTEGER, nome_completo TEXT, email TEXT, telemovel TEXT, data_nascimento TEXT)");
    $pdo->exec("CREATE TABLE utilizadores (id INTEGER PRIMARY KEY, email TEXT, papel TEXT)");
    $pdo->exec("INSERT INTO pacientes (id, nome_completo, email) VALUES (1, 'Paciente Existente', 'existe@example.test')");
    $registos = [
        ['nome_completo' => 'Paciente Existente', 'email' => 'existe@example.test'],
        ['nome_completo' => 'Paciente Novo', 'email' => 'novo@example.test'],
    ];
    $antes = (int)$pdo->query('SELECT COUNT(*) FROM pacientes')->fetchColumn();
    $r = importacao_validar_registos_pacientes($pdo, $registos, ['nome_completo' => 'Nome', 'email' => 'Email']);
    $depois = (int)$pdo->query('SELECT COUNT(*) FROM pacientes')->fetchColumn();
    return ($r['validos'] ?? -1) === 2
        && ($r['total_correspondencias_base_dados'] ?? -1) === 1
        && ($r['potenciais_novos_sem_correspondencia'] ?? -1) === 1
        && $antes === $depois;
};
$testes['Amostra oculta nomes'] = static function (): bool {
    return importacao_mascarar_valor_amostra('Nome do Paciente', 'Rafael Alves') === '[nome ocultado]';
};
$testes['Amostra mascara email'] = static function (): bool {
    return importacao_mascarar_valor_amostra('Email', 'rafael@example.test') === 'r•••@example.test';
};
$testes['Amostra mascara telefone'] = static function (): bool {
    return importacao_mascarar_valor_amostra('Telemóvel', '912345678') === '••• ••• 678';
};
$testes['Amostra mascara data de nascimento'] = static function (): bool {
    return importacao_mascarar_valor_amostra('Data de Nascimento', '12/08/2008') === '••/••/2008';
};
$testes['Amostra oculta conteúdo clínico'] = static function (): bool {
    return importacao_mascarar_valor_amostra('Diagnóstico', 'Informação clínica confidencial', ['Diagnóstico']) === '[conteúdo clínico ocultado]';
};

$testes['Revisão individual classifica registos sem expor dados pessoais'] = static function (): bool {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec("CREATE TABLE pacientes (id INTEGER PRIMARY KEY, utilizador_id INTEGER, nome_completo TEXT, email TEXT, telemovel TEXT, data_nascimento TEXT)");
    $pdo->exec("CREATE TABLE utilizadores (id INTEGER PRIMARY KEY, email TEXT, papel TEXT)");
    $pdo->exec("INSERT INTO pacientes (id, nome_completo, email) VALUES (1, 'Paciente Existente', 'existente@example.test')");
    $registos = [
        ['nome_completo' => 'Paciente Existente', 'email' => 'existente@example.test'],
        ['nome_completo' => 'Paciente Novo', 'email' => 'novo-unico@example.test'],
        ['nome_completo' => '', 'email' => 'invalido@example.test'],
        ['nome_completo' => 'Pessoa Repetida A', 'email' => 'repetido@example.test'],
        ['nome_completo' => 'Pessoa Repetida B', 'email' => 'repetido@example.test'],
    ];
    $r = importacao_validar_registos_pacientes($pdo, $registos, ['nome_completo' => 'Nome', 'email' => 'Email']);
    $linhas = $r['revisao_registos'] ?? [];
    $texto = json_encode($linhas, JSON_UNESCAPED_UNICODE);
    return count($linhas) === 5
        && ($linhas[0]['estado'] ?? '') === 'possível correspondência existente'
        && ($linhas[1]['estado'] ?? '') === 'potencial novo'
        && ($linhas[2]['estado'] ?? '') === 'inválido'
        && ($linhas[3]['estado'] ?? '') === 'possível duplicado no ficheiro'
        && ($linhas[4]['estado'] ?? '') === 'possível duplicado no ficheiro'
        && ($r['estados_registos']['invalidos'] ?? -1) === 1
        && ($r['estados_registos']['possiveis_duplicados'] ?? -1) === 2
        && !str_contains((string)$texto, 'existente@example.test')
        && !str_contains((string)$texto, 'Paciente Existente');
};

$falhas = 0;
foreach ($testes as $nome => $teste) {
    try {
        $ok = $teste();
    } catch (Throwable $e) {
        $ok = false;
        echo "EXCEÇÃO: " . $e->getMessage() . PHP_EOL;
    }
    echo ($ok ? '[OK] ' : '[FALHOU] ') . $nome . PHP_EOL;
    if (!$ok) {
        $falhas++;
    }
}
echo count($testes) . ' testes; ' . $falhas . ' falhas.' . PHP_EOL;
exit($falhas === 0 ? 0 : 1);
