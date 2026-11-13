<?php
declare(strict_types=1);
/*
 * Installation de Tonton (une seule fois).
 * Protégée par la « clé d'installation » du fichier de configuration, et
 * bloquée définitivement dès qu'un compte existe dans la base.
 * Ce fichier peut rester sur le serveur : il ne fait plus rien une fois l'installation faite.
 */
require __DIR__ . '/lib/bootstrap.php';

security_headers();
header('X-Robots-Tag: noindex, nofollow');

$erreur = null;
$etat = 'formulaire';
$detail = '';

if (cfg() === null) {
    $etat = 'pas_de_config';
} else {
    $dir = data_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $etat = 'pas_de_data';
    } else {
        try {
            db();
            $deja = is_file($dir . '/installed.lock')
                || (table_existe_install() && (int)q1('SELECT COUNT(*) n FROM {users}')['n'] > 0);
            if ($deja) {
                $etat = 'deja_installe';
            }
        } catch (Throwable $e) {
            $etat = 'erreur_bdd';
            $detail = $e->getMessage();
        }
    }
}
if ($etat !== 'pas_de_config' && $etat !== 'pas_de_data') {
    start_session();
}

function table_existe_install(): bool
{
    return (bool)q1('SELECT 1 t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [t('users')]);
}

if ($etat === 'formulaire' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cle = (string)cfg('cle_installation');
    $prenom = trim($_POST['prenom'] ?? '');
    $nom = trim($_POST['nom'] ?? '');
    $ini = mb_strtoupper(trim($_POST['initiales'] ?? ''));
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    if (mb_strlen($cle) < 12 || !hash_equals($cle, (string)($_POST['cle'] ?? ''))) {
        usleep(800000);
        $erreur = "Clé d'installation incorrecte (voir « cle_installation » dans tonton-config.php, 12 caractères minimum).";
    } elseif ($prenom === '' || $nom === '' || $ini === '' || mb_strlen($ini) > 6 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreur = 'Renseignez prénom, nom, initiales (6 caractères maximum) et une adresse e-mail valide.';
    } elseif ($msg = check_password_rules($pw)) {
        $erreur = $msg;
    } elseif ($pw !== ($_POST['password2'] ?? '')) {
        $erreur = 'Les deux mots de passe ne correspondent pas.';
    } else {
        try {
            maj_schema();
            q("INSERT INTO {users} (prenom, nom, initiales, email, password_hash, role, coef_km, created_at)
               VALUES (?, ?, ?, ?, ?, 'admin', ?, UTC_TIMESTAMP())",
                [$prenom, $nom, $ini, $email, password_hash($pw, PASSWORD_DEFAULT), (float)(cfg('coef_km_defaut') ?? 0.382)]);
            lier_membre((int)db()->lastInsertId());
            file_put_contents(data_dir() . '/installed.lock', date('c'));
            journal('installation', 'Version ' . APP_VERSION);
            $etat = 'termine';
        } catch (Throwable $e) {
            $erreur = "Erreur pendant l'installation : " . $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Installation · Tonton</title>
<link rel="icon" type="image/svg+xml" href="favicon-tonton.svg">
<link rel="stylesheet" href="assets/app.css?v=<?= APP_VERSION ?>">
</head>
<body class="page-login">
<main class="contenu">
<div class="login-box">
  <img class="login-logo" src="icone-tonton.svg" alt="Logo Tonton" width="90" height="90">
  <h1 class="login-titre">Installation</h1>
  <p class="login-sous-titre">de <strong>Tonton</strong></p>
  <?php if ($etat === 'pas_de_config'): ?>
    <p class="alerte alerte-erreur">Fichier de configuration introuvable. Il doit s'appeler <code>tonton-config.php</code> et se trouver <strong>à côté</strong> du dossier du site (pas dedans). Voir le README.</p>
  <?php elseif ($etat === 'pas_de_data'): ?>
    <p class="alerte alerte-erreur">Le dossier des données n'existe pas ou n'est pas accessible en écriture. Vérifiez « data_dir » dans <code>tonton-config.php</code>.</p>
  <?php elseif ($etat === 'erreur_bdd'): ?>
    <p class="alerte alerte-erreur">Connexion à la base de données impossible. Vérifiez les accès dans <code>tonton-config.php</code>.</p>
    <p class="muted petit"><?= h($detail) ?></p>
  <?php elseif ($etat === 'deja_installe'): ?>
    <p class="alerte">Tonton est déjà installé.</p>
    <p><a class="btn btn-primaire btn-plein" href="index.php">Aller à l'application</a></p>
  <?php elseif ($etat === 'termine'): ?>
    <p class="alerte alerte-ok">Installation terminée. Vous pouvez vous connecter avec votre compte administrateur.</p>
    <p><a class="btn btn-primaire btn-plein" href="index.php">Se connecter</a></p>
  <?php else: ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <p class="muted petit">Création des tables et du compte administrateur.</p>
      <?php if ($erreur): ?><p class="alerte alerte-erreur"><?= h($erreur) ?></p><?php endif; ?>
      <label>Clé d'installation <span class="muted petit">(dans tonton-config.php)</span><input type="password" name="cle" required autocomplete="off"></label>
      <div class="grille-2">
        <label>Prénom<input name="prenom" required value="<?= h($_POST['prenom'] ?? '') ?>"></label>
        <label>Nom<input name="nom" required value="<?= h($_POST['nom'] ?? '') ?>"></label>
      </div>
      <label>Initiales<input name="initiales" required maxlength="6" value="<?= h($_POST['initiales'] ?? '') ?>"></label>
      <label>E-mail<input type="email" name="email" required autocomplete="username" value="<?= h($_POST['email'] ?? '') ?>"></label>
      <label>Mot de passe (10 caractères minimum)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
      <label>Confirmer le mot de passe<input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>
      <button class="btn btn-primaire btn-plein">Installer</button>
    </form>
  <?php endif; ?>
</div>
</main>
</body>
</html>
