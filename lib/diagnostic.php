<?php
declare(strict_types=1);

/**
 * Vérifications de l'hébergement, affichées à l'administrateur.
 * Chaque ligne : [libellé, état ('ok' | 'attention' | 'erreur'), détail].
 */
function diagnostic(): array
{
    $c = [];
    $ok = fn(bool $b) => $b ? 'ok' : 'erreur';

    $c[] = ['Version de PHP', $ok(PHP_VERSION_ID >= 80100), PHP_VERSION . ' (8.1 minimum)'];
    foreach ([
        'pdo_mysql' => 'Connexion à la base MySQL',
        'gd'        => 'Traitement des photos',
        'zip'       => 'Export ZIP et Excel',
        'mbstring'  => 'Textes accentués',
        'iconv'     => 'Conversion des caractères (PDF)',
        'exif'      => 'Orientation des photos',
        'zlib'      => 'Compression des PDF',
    ] as $ext => $role) {
        $present = extension_loaded($ext);
        $c[] = ["Extension $ext", $present ? 'ok' : ($ext === 'exif' ? 'attention' : 'erreur'), $role . ($present ? '' : ' : extension absente')];
    }
    $c[] = ['Fonction mail()', function_exists('mail') ? 'ok' : 'attention', 'Envoi des e-mails'];

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $c[] = ['Connexion sécurisée (HTTPS)', $https ? 'ok' : 'attention', $https ? 'Active' : 'Non détectée (normal en test local)'];

    // Configuration et données hors du dossier du site
    $cfgFile = realpath(config_path()) ?: config_path();
    $root = realpath(APP_ROOT) ?: APP_ROOT;
    $dans = fn(string $p) => str_starts_with(str_replace('\\', '/', $p), str_replace('\\', '/', $root) . '/');
    $c[] = ['Fichier de configuration', $dans($cfgFile) ? 'erreur' : 'ok',
        ($dans($cfgFile) ? 'DANGER : il est dans le dossier du site. ' : 'Hors du dossier du site : ') . basename($cfgFile)];
    $data = data_dir();
    $dataReal = realpath($data) ?: $data;
    $c[] = ['Dossier des données', $dans($dataReal) ? 'erreur' : 'ok',
        ($dans($dataReal) ? 'DANGER : il est dans le dossier du site (écrasé par la synchronisation). ' : 'Hors du dossier du site : ') . basename($dataReal)];
    $ecrit = is_dir($data) && is_writable($data);
    $c[] = ['Écriture dans le dossier des données', $ok($ecrit), $ecrit ? 'Possible' : 'Impossible : vérifiez le dossier et ses droits'];
    if ($ecrit) {
        $test = $data . '/.test-' . bin2hex(random_bytes(4));
        $w = @file_put_contents($test, 'x') === 1;
        @unlink($test);
        $c[] = ['Test d\'écriture d\'un fichier', $ok($w), $w ? 'Réussi' : 'Échec'];
    }
    $libre = @disk_free_space($data);
    if ($libre !== false) {
        $c[] = ['Espace disque libre', $libre > 200 * 1024 * 1024 ? 'ok' : 'attention', round($libre / 1024 / 1024 / 1024, 1) . ' Go'];
    }

    // Base de données
    try {
        $v = q1('SELECT VERSION() v')['v'];
        $c[] = ['Base de données', 'ok', 'Connectée (' . $v . '), préfixe des tables « ' . t('') . ' »'];
        $c[] = ['Version de la structure', schema_version_installee() >= SCHEMA_VERSION ? 'ok' : 'attention',
            schema_version_installee() . ' / ' . SCHEMA_VERSION];
    } catch (Throwable $e) {
        $c[] = ['Base de données', 'erreur', 'Connexion impossible'];
    }

    // Limites d'envoi de fichiers
    $up = ini_get('upload_max_filesize');
    $post = ini_get('post_max_size');
    $c[] = ['Taille maximale d\'un envoi', taille_octets((string)$up) >= 8 * 1024 * 1024 ? 'ok' : 'attention', "upload_max_filesize $up, post_max_size $post"];
    $c[] = ['Mémoire disponible', taille_octets((string)ini_get('memory_limit')) >= 128 * 1024 * 1024 || ini_get('memory_limit') === '-1' ? 'ok' : 'attention',
        (string)ini_get('memory_limit')];

    // Fichiers publics qui ne doivent pas exister
    foreach (['config.php', 'tonton-config.php', 'data'] as $f) {
        if (file_exists(APP_ROOT . '/' . $f)) {
            $c[] = ["« $f » dans le dossier du site", 'erreur', 'À supprimer du dossier du site (risque de publication)'];
        }
    }

    $c[] = ['Module salariés', module_salaries() ? 'attention' : 'ok', module_salaries() ? 'Activé' : 'Désactivé (phase 1)'];
    $c[] = ['Expéditeur des e-mails', cfg('email_expediteur') ? 'ok' : 'attention', (string)(cfg('email_expediteur') ?? 'non renseigné')];
    $c[] = ['Version de Tonton', 'ok', APP_VERSION];
    return $c;
}

function taille_octets(string $v): int
{
    $v = trim($v);
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}
