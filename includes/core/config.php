<?php
declare(strict_types=1);

/* =====================================================================
   config.php - configuração, ligação à BD, sessões e funções auxiliares
   Este ficheiro é incluído no início de TODAS as páginas.
   ===================================================================== */

// As datas/horas de calendário do projeto são hora local da clínica.
// A BD guarda-as em colunas DATETIME sem fuso; o PHP tem de usar o mesmo fuso.
date_default_timezone_set('Europe/Lisbon');

// ---------- 1. Definições (muda aqui se for preciso) ----------
const DB_HOST = 'localhost';
const DB_NAME = 'clinica_dentaria';
const DB_USER = 'root';
const DB_PASS = '';

$__ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$__local = in_array($__ip, ['127.0.0.1', '::1'], true) || PHP_SAPI === 'cli-server';
define('MODO_DEV', $__local && (is_file(dirname(__DIR__) . '/.modo_dev') || is_file(__DIR__ . '/.modo_dev')));
unset($__ip, $__local);

const MAX_TENTATIVAS_EMAIL = 5;
const MAX_TENTATIVAS_IP    = 20;
const BLOQUEIO_MINUTOS     = 15;

const CLINICA_NOME        = 'Clínica Dentária';
const CLINICA_NIF         = '999 999 999';
const CLINICA_ERS         = 'ERS-000000';
const CLINICA_MORADA      = 'Rua Conselheiro Bivar, 1, 8000-255 Faro';
const CLINICA_TELEFONE    = '289 000 000';
const CLINICA_EMAIL       = 'geral@clinicadentaria.pt';
const CLINICA_DIRETOR     = 'Diretor Clínico (a configurar)';
const CLINICA_DIRETOR_OMD = 'Cédula OMD n.º (a configurar)';

$__raiz = rtrim(str_replace('\\', '/', (string)realpath(__DIR__ . '/../..')), '/');
$__doc  = rtrim(str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
if ($__doc !== '' && stripos($__raiz, $__doc) === 0) {
    define('BASE_URL', rtrim(substr($__raiz, strlen($__doc)), '/') . '/');
} else {
    define('BASE_URL', '/');
}

/**
 * Carrega segredos locais de um ficheiro .env na raiz do projeto.
 * O ficheiro está bloqueado pelo .htaccess e ignorado pelo Git. Variáveis
 * já definidas no ambiente têm precedência (produção deve usar o ambiente).
 */
function carregar_env_local(): void
{
    static $carregado = false;
    if ($carregado) { return; }
    $carregado = true;

    $ficheiro = dirname(__DIR__, 2);
    $ficheiro = rtrim(str_replace('\\', '/', (string)$ficheiro), '/') . '/.env';
    if (!is_file($ficheiro) || !is_readable($ficheiro)) { return; }

    $linhas = @file($ficheiro, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($linhas)) { return; }

    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if ($linha === '' || $linha[0] === '#') { continue; }
        $pos = strpos($linha, '=');
        if ($pos === false) { continue; }
        $chave = trim(substr($linha, 0, $pos));
        $valor = trim(substr($linha, $pos + 1));
        if (!preg_match('/^[A-Z0-9_]+$/', $chave)) { continue; }
        if (strlen($valor) >= 2) {
            $primeiro = $valor[0];
            $ultimo   = substr($valor, -1);
            if (($primeiro === '"' && $ultimo === '"') || ($primeiro === "'" && $ultimo === "'")) {
                $valor = substr($valor, 1, -1);
            }
        }
        $atual = getenv($chave);
        if ($atual !== false && trim((string)$atual) !== '') { continue; }
        putenv($chave . '=' . $valor);
        $_ENV[$chave] = $valor;
    }
}
carregar_env_local();

if (MODO_DEV) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('CLINICA_SESS');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // O Payment Element da Stripe é usado no checkout e na gestão de cartões. As restantes páginas
    // mantêm a CSP restritiva, sem autorizar scripts/frames de terceiros.
    $__paginaAtual = basename((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
    $__checkoutStripe = in_array($__paginaAtual, ['checkout.php', 'cartoes.php'], true);

    if ($__checkoutStripe) {
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")');
        $csp = "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; "
             . "img-src 'self' data: blob: https:; "
             . "font-src 'self' https://fonts.gstatic.com data:; "
             . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
             . "script-src 'self' 'unsafe-inline' https://js.stripe.com; "
             . "connect-src 'self' https://api.stripe.com https://m.stripe.network https://m.stripe.com; "
             . "frame-src 'self' https://js.stripe.com https://hooks.stripe.com https://*.stripe.com https://m.stripe.network https://m.stripe.com https://www.google.com https://maps.google.com https://*.google.com https://www.google.pt https://maps.google.pt https://*.google.pt;";
    } else {
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        $csp = "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; "
             . "img-src 'self' data: blob: https:; "
             . "font-src 'self' https://fonts.gstatic.com data:; "
             . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
             . "script-src 'self' 'unsafe-inline'; "
             . "connect-src 'self'; "
             . "frame-src 'self' https://www.google.com https://maps.google.com https://*.google.com https://www.google.pt https://maps.google.pt https://*.google.pt;";
    }
    unset($__paginaAtual, $__checkoutStripe);
    header('Content-Security-Policy: ' . $csp);
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function fuso_clinica(): DateTimeZone
{
    return new DateTimeZone('Europe/Lisbon');
}

/**
 * Offset atual (ou de um instante concreto) da clínica, no formato MySQL.
 * Permite fixar a sessão da BD sem depender das tabelas de timezone do MySQL
 * nem do nome localizado devolvido por @@system_time_zone.
 */
function offset_clinica(?DateTimeImmutable $momento = null): string
{
    $m = $momento ?? new DateTimeImmutable('now', fuso_clinica());
    return $m->setTimezone(fuso_clinica())->format('P');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $offset = offset_clinica();
            if (preg_match('/^[+-]\d{2}:\d{2}$/', $offset) === 1) {
                // NOW() passa a devolver sempre a hora local da clínica,
                // independentemente do fuso configurado no sistema operativo.
                $pdo->exec("SET time_zone = '{$offset}'");
            }
        } catch (PDOException $e) {
            error_log('Erro de ligação à BD: ' . $e->getMessage());
            http_response_code(500);
            exit(MODO_DEV
                ? 'Erro ao ligar à base de dados: ' . htmlspecialchars($e->getMessage())
                : 'De momento não é possível aceder ao serviço. Tenta mais tarde.');
        }
    }
    return $pdo;
}

function e(?string $texto): string
{
    return htmlspecialchars((string)$texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirecionar(string $url): never
{
    if (preg_match('/[\r\n\0]/', $url)) {
        http_response_code(500);
        exit('Redirecionamento inválido.');
    }
    if (!preg_match('~^(https?:)?//|^/~', $url)) {
        $url = BASE_URL . $url;
    }
    header('Location: ' . $url);
    exit;
}

function ip_cliente(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_validar(): void
{
    $enviado  = $_POST['csrf'] ?? '';
    $esperado = $_SESSION['csrf'] ?? '';
    // Sem token na sessão não há validação possível: rejeitar sempre.
    // (Antes, sessão sem token + POST sem token passava por hash_equals('', '').)
    if (!is_string($enviado) || $enviado === ''
        || !is_string($esperado) || $esperado === ''
        || !hash_equals($esperado, $enviado)) {
        http_response_code(403);
        exit('Pedido inválido (token de segurança em falta ou expirado). Volta atrás e tenta outra vez.');
    }
}

function flash_definir(string $tipo, string $mensagem): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensagem' => $mensagem];
}

function flash_obter(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function utilizador_atual(): ?array
{
    return $_SESSION['utilizador'] ?? null;
}

/**
 * Guarda a página protegida que o visitante tentou abrir, para o devolver
 * lá depois do login (ex.: escolher dentista -> login -> continuar marcação).
 * Só aceita GET, caminhos .php internos e query strings simples.
 */
function guardar_destino_apos_login(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || !empty($_SESSION['url_apos_login'])) {
        return;
    }

    $uri     = (string)($_SERVER['REQUEST_URI'] ?? '');
    $caminho = parse_url($uri, PHP_URL_PATH);
    $query   = parse_url($uri, PHP_URL_QUERY);

    if (!is_string($caminho) || $caminho === '' || str_contains($caminho, '..')) {
        return;
    }

    $base = rtrim(BASE_URL, '/');
    if ($base !== '' && str_starts_with($caminho, $base . '/')) {
        $caminho = substr($caminho, strlen($base) + 1);
    } elseif ($base === '') {
        $caminho = ltrim($caminho, '/');
    } else {
        return;
    }

    if (!preg_match('~^[A-Za-z0-9_\-/]+\.php$~', $caminho)) {
        return;
    }
    if (in_array($caminho, ['login.php', 'logout.php', 'registo.php'], true)) {
        return;
    }

    $destino = $caminho;
    if (is_string($query) && $query !== '') {
        if (!preg_match('~^[A-Za-z0-9_\-=&%.+]+$~', $query)) {
            return;
        }
        $destino .= '?' . $query;
    }

    $_SESSION['url_apos_login'] = $destino;
}

function consumir_destino_apos_login(): ?string
{
    $destino = $_SESSION['url_apos_login'] ?? null;
    unset($_SESSION['url_apos_login']);
    return is_string($destino) && $destino !== '' ? $destino : null;
}

function exigir_login(array $papeisPermitidos = []): array
{
    $u = utilizador_atual();
    if ($u === null) {
        guardar_destino_apos_login();
        flash_definir('info', 'Inicia sessão para continuares.');
        redirecionar('login.php');
    }

    static $cache = [];
    $id = (int)($u['id'] ?? 0);
    if ($id <= 0) {
        unset($_SESSION['utilizador']);
        redirecionar('login.php');
    }
    if (!array_key_exists($id, $cache)) {
        try {
            $st = db()->prepare('SELECT id, nome_completo, papel, tema, ativo FROM utilizadores WHERE id = ?');
            $st->execute([$id]);
            $cache[$id] = $st->fetch() ?: null;
        } catch (Throwable $e) {
            error_log('Erro ao revalidar utilizador: ' . $e->getMessage());
            $cache[$id] = null;
        }
    }
    $atual = $cache[$id];
    if (!$atual || (int)$atual['ativo'] !== 1) {
        unset($_SESSION['utilizador']);
        flash_definir('info', 'A tua sessão terminou. Inicia sessão novamente.');
        redirecionar('login.php');
    }
    $_SESSION['utilizador']['papel'] = $atual['papel'];
    $_SESSION['utilizador']['nome_completo'] = $atual['nome_completo'];
    if (isset($atual['tema'])) {
        $_SESSION['utilizador']['tema'] = $atual['tema'];
    }
    $u = $_SESSION['utilizador'];

    if ($papeisPermitidos !== [] && !in_array($u['papel'], $papeisPermitidos, true)) {
        http_response_code(403);
        exit('Não tens permissão para aceder a esta página.');
    }
    return $u;
}

function nome_dia(int $dia): string
{
    $nomes = [1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira', 4 => 'Quinta-feira',
              5 => 'Sexta-feira', 6 => 'Sábado', 7 => 'Domingo'];
    return $nomes[$dia] ?? '';
}

function formatar_preco($valor): string
{
    return number_format((float)$valor, 2, ',', ' ') . ' €';
}

function formatar_duracao(int $minutos): string
{
    $h = intdiv($minutos, 60);
    $m = $minutos % 60;
    if ($h > 0 && $m > 0) { return $h . ' h ' . $m . ' min'; }
    if ($h > 0)           { return $h . ' h'; }
    return $m . ' min';
}

function iniciais(string $nome): string
{
    $partes = preg_split('/\s+/', trim($nome));
    $a = mb_substr($partes[0], 0, 1);
    $b = count($partes) > 1 ? mb_substr(end($partes), 0, 1) : '';
    return mb_strtoupper($a . $b);
}

function avatar_dentista(array $d): string
{
    $nome = (string)($d['nome_completo'] ?? '');
    $foto = trim((string)($d['fotografia'] ?? ''));
    if ($foto !== '') {
        $mostrar = true;
        if (!preg_match('~^https?://~i', $foto)) {
            $root  = realpath(__DIR__ . '/../..');
            $abs   = $root ? realpath($root . '/' . ltrim(str_replace('\\', '/', $foto), '/')) : false;
            $absN  = $abs  !== false ? str_replace('\\', '/', $abs)  : '';
            $rootN = $root !== false ? str_replace('\\', '/', $root) : '';
            $mostrar = ($abs !== false && $root !== false
                        && str_starts_with($absN . '/', $rootN . '/')
                        && is_file($abs));
        }
        if ($mostrar) {
            return '<img class="avatar" src="' . e($foto) . '" alt="Fotografia de ' . e($nome) . '">';
        }
    }
    return '<div class="avatar" aria-hidden="true">' . e(iniciais($nome)) . '</div>';
}

function registar_admin(
    string $acao,
    ?string $entidade = null,
    ?int $entidadeId = null,
    ?string $detalhes = null,
    array|string|null $antes = null,
    array|string|null $depois = null,
    ?string $motivo = null
): void
{
    $u = utilizador_atual();
    $json = static function ($v): ?string {
        if ($v === null || $v === '') return null;
        if (is_string($v)) return mb_substr($v, 0, 65000);
        $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $j === false ? null : mb_substr($j, 0, 65000);
    };
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    try {
        db()->prepare(
            'INSERT INTO registo_admin (utilizador_id, acao, entidade, entidade_id, detalhes, ip, motivo, antes_json, depois_json, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $u['id'] ?? null,
            $acao,
            $entidade,
            $entidadeId,
            $detalhes,
            ip_cliente(),
            $motivo !== null && $motivo !== '' ? mb_substr($motivo, 0, 255) : null,
            $json($antes),
            $json($depois),
            $ua !== '' ? $ua : null,
        ]);
    } catch (Throwable $e) {
        error_log('registar_admin: ' . $e->getMessage());
        try {
            db()->prepare(
                'INSERT INTO registo_admin (utilizador_id, acao, entidade, entidade_id, detalhes, ip)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$u['id'] ?? null, $acao, $entidade, $entidadeId, $detalhes, ip_cliente()]);
        } catch (Throwable $e2) {
            error_log('registar_admin fallback: ' . $e2->getMessage());
        }
    }
}

function config_valor_texto(PDO $pdo, string $chave, string $padrao = ''): string
{
    static $cache = [];
    if (!isset($cache[$chave])) {
        try {
            $st = $pdo->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
            $st->execute([$chave]);
            $v = $st->fetchColumn();
            $cache[$chave] = ($v !== false) ? (string)$v : $padrao;
        } catch (Throwable $e) {
            error_log('config_valor_texto(' . $chave . '): ' . $e->getMessage());
            $cache[$chave] = $padrao;
        }
    }
    return $cache[$chave];
}
if (!function_exists('config_valor')) {
    function config_valor(PDO $pdo, string $chave, int $padrao): int
    {
        static $cache = [];
        if (!isset($cache[$chave])) {
            try {
                $st = $pdo->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
                $st->execute([$chave]);
                $v = $st->fetchColumn();
                $cache[$chave] = ($v !== false && is_numeric((string)$v)) ? (int)$v : $padrao;
            } catch (Throwable $e) {
                error_log('config_valor(' . $chave . '): ' . $e->getMessage());
                $cache[$chave] = $padrao;
            }
        }
        return $cache[$chave];
    }
}
function clinica_dado(PDO $pdo, string $chaveConfig, string $fallback): string
{
    if (function_exists('config_valor_texto')) {
        $v = config_valor_texto($pdo, $chaveConfig, '');
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
    }
    return $fallback;
}