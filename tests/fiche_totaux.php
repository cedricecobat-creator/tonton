<?php
/*
 * Outil de test (ligne de commande uniquement) : totaux des fiches associés d'un mois.
 * Usage : TONTON_CONFIG=/chemin/tonton-config.php php tests/fiche_totaux.php 2026 9
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__) . '/lib/bootstrap.php';
date_default_timezone_set(cfg('timezone') ?? 'Europe/Paris');
$a = (int)($argv[1] ?? 2026);
$m = (int)($argv[2] ?? 9);
$out = [];
foreach (associes() as $u) {
    $f = calcul_fiche($u, $a, $m);
    $primes = [];
    foreach ($f['jours'] as $d => $j) {
        if ($j['prime']) {
            $primes[] = (int)substr($d, 8);
        }
    }
    $out[$u['prenom']] = [
        'total' => $f['totaux']['total'], 'nb_primes' => $f['nb_primes'], 'jours_primes' => $primes,
        'avances' => $f['total_avances'], 'kms' => $f['totaux']['total_kms'], 'cb_pro' => $f['total_cb_pro'],
        'definitif' => $f['definitif'],
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
