<?php
/** @var string $contenu @var string $titre @var ?array $user @var array $flash */
$nomApp = app_nom();
$p = $_GET['p'] ?? 'accueil';
$qs = isset($a, $m) ? ['a' => $a, 'm' => $m] : [];

/** Icônes de navigation (traits simples, couleur du texte) */
$ico = function (string $nom): string {
    $d = [
        'liste'   => '<path d="M8 6h12M8 12h12M8 18h12"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'fiche'   => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'gestion' => '<path d="M4 12l5 5L20 6"/>',
        'compte'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
        'siren'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 10h6M7 14h4"/><circle cx="16.5" cy="12" r="1.5"/>',
        'suivi'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c1-3.5 3.6-5.5 6.5-5.5s5.5 2 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 010 7M18 14.5c2 .8 3.2 2.8 3.5 5.5"/>',
    ][$nom] ?? '';
    return '<svg class="ico" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
};

// Navigation propre à chaque rôle : [page, libellé, icône, pages actives]
$haut = [];
$basG = [];
$basD = [];
if ($user) {
    if (est_admin($user)) {
        $haut = [
            ['accueil', 'Tickets', 'liste', ['accueil', 'depense']],
            ['gestion', 'Gestion', 'gestion', ['gestion']],
            ['fiche', 'Fiches', 'fiche', ['fiche']],
            ['absences', 'Congés', 'fiche', ['absences']],
            ['utilisateurs', 'Utilisateurs', 'compte', ['utilisateurs', 'utilisateur']],
        ];
        if (module_salaries()) {
            $haut[] = ['suivi', 'Salariés', 'suivi', ['suivi', 'fiche_rh']];
        }
        if (est_salarie_fiche($user)) {
            $haut[] = ['fiche_rh', 'Ma fiche RH', 'fiche', []];
        }
        $basG = [$haut[0], $haut[1]];
        $basD = [$haut[2], ['compte', 'Admin', 'compte', ['compte', 'journal', 'diagnostic', 'utilisateurs', 'utilisateur', 'absences']]];
    } elseif (est_associe($user)) {
        $haut = [
            ['accueil', 'Mon mois', 'liste', ['accueil', 'depense']],
            ['fiche', 'Ma fiche', 'fiche', ['fiche']],
        ];
        if (module_salaries()) {
            $haut[] = ['suivi', 'Salariés', 'suivi', ['suivi', 'fiche_rh']];
        }
        $basG = [$haut[0], $haut[1]];
        $basD = [module_salaries() ? $haut[2] : ['siren', 'SIREN', 'siren', ['siren']], ['compte', 'Compte', 'compte', ['compte']]];
    } else {
        $haut = [
            ['accueil', 'Mon mois', 'liste', ['accueil', 'depense']],
            ['fiche_rh', 'Ma fiche RH', 'fiche', ['fiche_rh']],
        ];
        $basG = [$haut[0], $haut[1]];
        $basD = [['siren', 'SIREN', 'siren', ['siren']], ['compte', 'Compte', 'compte', ['compte']]];
    }
}
$lien = function (array $it, bool $avecIcone) use ($p, $qs, $ico): string {
    [$page, $lib, $icone, $actives] = $it;
    $params = in_array($page, ['accueil', 'fiche', 'gestion', 'absences', 'suivi', 'fiche_rh'], true) ? $qs : [];
    $actif = in_array($p, $actives, true) || $p === $page;
    return '<a href="' . h(url($page, $params)) . '" class="' . ($actif ? 'actif' : '') . '"' . ($actif ? ' aria-current="page"' : '') . '>'
        . ($avecIcone ? $ico($icone) : '') . '<span>' . h($lib) . '</span></a>';
};
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#ffffff">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Tonton">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<title><?= h(($titre ? $titre . ' · ' : '') . $nomApp) ?></title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" type="image/svg+xml" href="favicon-tonton.svg">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-180.png">
<link rel="stylesheet" href="assets/app.css?v=<?= APP_VERSION ?>">
<script src="assets/app.js?v=<?= APP_VERSION ?>" defer></script>
</head>
<body class="<?= $user ? 'avec-nav role-' . h($user['role']) : 'page-login' ?>">
<?php if ($user): ?>
<header class="barre">
  <a class="barre-titre" href="<?= h(url('accueil', $qs)) ?>">
    <img src="icone-tonton.svg" alt="" width="32" height="32"><span><?= h($nomApp) ?></span>
  </a>
  <nav class="barre-liens" aria-label="Navigation principale">
    <?php foreach ($haut as $it): ?><?= $lien($it, false) ?><?php endforeach; ?>
    <a href="<?= h(url('compte')) ?>" class="barre-compte <?= in_array($p, ['compte', 'journal', 'diagnostic'], true) ? 'actif' : '' ?>" title="Mon compte"><?= h($user['initiales']) ?></a>
  </nav>
</header>
<?php endif; ?>

<main class="contenu">
  <?php foreach ($flash as [$type, $msg]): ?>
    <div class="alerte alerte-<?= h($type) ?>" role="status"><?= h($msg) ?></div>
  <?php endforeach; ?>
  <?= $contenu ?>
</main>

<?php if ($user): ?>
<nav class="nav-bas" aria-label="Navigation">
  <?php foreach ($basG as $it): ?><?= $lien($it, true) ?><?php endforeach; ?>
  <a href="<?= h(url('depense')) ?>" class="nav-ajout" aria-label="Ajouter un ticket"><span>+</span></a>
  <?php foreach ($basD as $it): ?><?= $lien($it, true) ?><?php endforeach; ?>
</nav>
<?php endif; ?>
</body>
</html>
