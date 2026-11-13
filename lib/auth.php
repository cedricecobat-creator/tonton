<?php
declare(strict_types=1);

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $u = q1('SELECT * FROM {users} WHERE id = ? AND actif = 1', [(int)$_SESSION['uid']]);
            // Déconnexion si le mot de passe a changé depuis l'ouverture de la session
            if ($u && hash_equals((string)($_SESSION['pwv'] ?? ''), substr(hash('sha256', $u['password_hash']), 0, 16))
                && compte_autorise($u)) {
                $user = $u;
            }
        }
    }
    return $user;
}

/** Les comptes salariés ne peuvent pas se connecter tant que le module salariés est désactivé */
function compte_autorise(array $u): bool
{
    return isset(ROLES[$u['role']]) && ($u['role'] !== 'salarie' || module_salaries());
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        if (is_ajax()) {
            json_out(['ok' => false, 'error' => 'Votre session a expiré, reconnectez-vous.'], 401);
        }
        redirect(url('login'));
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit("Accès réservé à l'administrateur.");
    }
    return $u;
}

/** Retourne null si OK, sinon un message d'erreur. */
function try_login(string $email, string $password): ?string
{
    $email = mb_strtolower(trim($email));
    $u = q1('SELECT * FROM {users} WHERE email = ?', [$email]);
    $now = time();

    if ($u && $u['locked_until'] !== null && strtotime($u['locked_until'] . ' UTC') > $now) {
        return 'Trop de tentatives. Réessayez dans quelques minutes.';
    }
    if (!$u || !(int)$u['actif'] || !password_verify($password, $u['password_hash'])) {
        if ($u) {
            $fails = (int)$u['failed_logins'] + 1;
            $lock = $fails >= 5 ? gmdate('Y-m-d H:i:s', $now + 15 * 60) : null;
            q('UPDATE {users} SET failed_logins = ?, locked_until = ? WHERE id = ?', [$lock ? 0 : $fails, $lock, $u['id']]);
        }
        usleep(700000);
        return 'Adresse e-mail ou mot de passe incorrect.';
    }

    if (!compte_autorise($u)) {
        return "L'espace salariés de Tonton n'est pas encore ouvert.";
    }

    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        $u['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        q('UPDATE {users} SET password_hash = ? WHERE id = ?', [$u['password_hash'], $u['id']]);
    }
    q('UPDATE {users} SET failed_logins = 0, locked_until = NULL, last_login = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['pwv'] = substr(hash('sha256', $u['password_hash']), 0, 16);
    return null;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function check_password_rules(string $pw): ?string
{
    if (mb_strlen($pw) < 10) {
        return 'Le mot de passe doit contenir au moins 10 caractères.';
    }
    return null;
}
