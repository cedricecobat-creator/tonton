<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.2.1');

/**
 * Emplacement du fichier de configuration.
 *
 * La configuration (accès à la base, etc.) ne doit JAMAIS se trouver dans le
 * dossier du site : celui-ci est synchronisé depuis un dépôt GitHub public.
 * Ordre de recherche :
 *   1. variable d'environnement TONTON_CONFIG (tests en local, ou SetEnv) ;
 *   2. « tonton-config.php » dans le dossier PARENT du site
 *      (sur OVH : à la racine de l'espace FTP, à côté du dossier « tonton »).
 */
function config_path(): string
{
    $env = getenv('TONTON_CONFIG');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return dirname(APP_ROOT) . '/tonton-config.php';
}

function cfg(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = config_path();
        if (!is_file($file)) {
            return null;
        }
        $cfg = require $file;
        if (!is_array($cfg)) {
            return null;
        }
    }
    if ($key === null) {
        return $cfg;
    }
    $val = $cfg;
    foreach (explode('.', $key) as $k) {
        if (!is_array($val) || !array_key_exists($k, $val)) {
            return null;
        }
        $val = $val[$k];
    }
    return $val;
}

/** Nom de l'application */
function app_nom(): string
{
    return 'Tonton';
}

/** Le module salariés (phase 2) est-il activé ? */
function module_salaries(): bool
{
    return (bool)(cfg('module_salaries') ?? false);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg('db');
        $dsn = 'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

/** Nom de table avec préfixe : t('users') => tonton_users */
function t(string $name): string
{
    return (cfg('db.prefix') ?? 'tonton_') . $name;
}

function q(string $sql, array $params = []): PDOStatement
{
    // {table} est remplacé par le nom préfixé
    $sql = preg_replace_callback('/\{(\w+)\}/', fn($m) => t($m[1]), $sql);
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q1(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function qall(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function euros($v, bool $vide_si_zero = false): string
{
    $v = (float)$v;
    if ($vide_si_zero && abs($v) < 0.005) {
        return '';
    }
    return number_format($v, 2, ',', "\u{202F}") . "\u{00A0}€";
}

function nombre_fr($v, int $dec = 2): string
{
    return number_format((float)$v, $dec, ',', "\u{202F}");
}

/** "0,382" ou "0.382" => 0.382 */
function parse_nombre($s): float
{
    $s = str_replace(["\u{202F}", "\u{00A0}", ' ', '€'], '', (string)$s);
    return (float)str_replace(',', '.', $s);
}

/** "12,50" ou "12.50" => 12.5 */
function parse_montant($s): float
{
    return round(parse_nombre($s), 2);
}

const MOIS_FR = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const JOURS_FR = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
const JOURS_COURTS = [1 => 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];

function mois_libelle(int $annee, int $mois): string
{
    return MOIS_FR[$mois] . ' ' . $annee;
}

function date_fr(string $ymd, bool $avec_jour = false): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    if (!$d) {
        return $ymd;
    }
    $s = $d->format('d/m/Y');
    return $avec_jour ? JOURS_COURTS[(int)$d->format('N')] . ' ' . $s : $s;
}

function url(string $page, array $params = []): string
{
    $params = ['p' => $page] + $params;
    return 'index.php?' . http_build_query($params);
}

/**
 * Page où revenir après un ticket : la fiche mensuelle si on vient de la fiche
 * (paramètres retour=fiche et ru=personne), sinon la liste des tickets du mois.
 */
function url_retour(int $a, int $m): string
{
    if (($_REQUEST['retour'] ?? '') === 'fiche') {
        $ru = (int)($_REQUEST['ru'] ?? 0);
        return url('fiche', ['a' => $a, 'm' => $m] + ($ru > 0 ? ['u' => $ru] : []));
    }
    return url('accueil', ['a' => $a, 'm' => $m]);
}

/** Champs cachés qui transmettent la page de retour aux formulaires */
function champs_retour(): string
{
    if (($_REQUEST['retour'] ?? '') !== 'fiche') {
        return '';
    }
    return '<input type="hidden" name="retour" value="fiche"><input type="hidden" name="ru" value="' . (int)($_REQUEST['ru'] ?? 0) . '">';
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function flash(?string $msg = null, string $type = 'ok')
{
    if ($msg !== null) {
        $_SESSION['flash'][] = [$type, $msg];
        return null;
    }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Session expirée : rechargez la page et recommencez.');
    }
}

function is_ajax(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function journal(string $action, string $detail = ''): void
{
    try {
        q('INSERT INTO {journal} (user_id, action, detail, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())',
            [current_user()['id'] ?? null, $action, mb_substr($detail, 0, 1000)]);
    } catch (Throwable $e) {
        // le journal ne doit jamais bloquer l'application
    }
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('tonton_session');
    $dossier = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30, // 30 jours : on reste connecté sur son téléphone
        'path'     => $dossier . '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // Sessions stockées dans le dossier de données (hors du site) : sur un
    // hébergement mutualisé, le dossier commun est vidé trop souvent.
    $dir = data_dir() . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-eval' 'wasm-unsafe-eval'; "
        . "worker-src 'self' blob:; img-src 'self' blob: data:; style-src 'self' 'unsafe-inline'; "
        . "connect-src 'self' blob: data:; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
}

require __DIR__ . '/auth.php';
require __DIR__ . '/metier.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/schema.php';
