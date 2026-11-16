<?php
declare(strict_types=1);

/*
 * Régularisations des mois clos.
 *
 * Un mois est clos dès qu'il est validé : sa fiche ne bouge plus (elle a été payée
 * et transmise). Tout ce qui le concerne et arrive ensuite — ticket oublié, congé
 * ou formation déclaré en retard — garde sa vraie date mais est « rattaché » au
 * premier mois encore ouvert (colonnes regul_annee / regul_mois).
 *
 * Sur la fiche du mois de rattachement, une section « Régularisations » reprend :
 *  - les tickets tardifs payés par la personne (remboursés ce mois-là) ;
 *  - pour chaque jour concerné où la prime repas avait été versée, une ligne de
 *    déduction (une seule par jour et par personne), que l'admin peut décocher ;
 *  - les lignes libres saisies par l'admin.
 */

/** Premier mois ouvert à partir du mois de cette date (le mois lui-même s'il est ouvert) */
function mois_rattachement(string $ymd): array
{
    [$a, $m] = mois_de_date($ymd);
    for ($i = 0; $i < 120; $i++) {
        if (mois_ouvert($a, $m)) {
            return [$a, $m];
        }
        [, [$a, $m]] = mois_voisins($a, $m);
    }
    return [$a, $m];
}

/** Mois dans lequel un ticket est compté (rattachement, sinon mois de sa date) */
function mois_effectif(array $dep): array
{
    if (($dep['regul_annee'] ?? null) !== null) {
        return [(int)$dep['regul_annee'], (int)$dep['regul_mois']];
    }
    return mois_de_date($dep['date_depense']);
}

/** Condition SQL « ce ticket compte dans le mois » (alias d) et ses paramètres */
function sql_mois_depense(int $a, int $m): array
{
    [$debut, $fin] = mois_bornes($a, $m);
    return ['((d.regul_annee IS NULL AND d.date_depense BETWEEN ? AND ?) OR (d.regul_annee = ? AND d.regul_mois = ?))',
        [$debut, $fin, $a, $m]];
}

function mois_index(int $a, int $m): int
{
    return $a * 12 + $m;
}

/** La prime repas a-t-elle été versée à cet associé ce jour-là, sur la fiche de son mois ? Retourne le montant (0 sinon). */
function prime_versee(array $user, string $ymd): float
{
    static $cache = [];
    [$a, $m] = mois_de_date($ymd);
    $cle = $user['id'] . '-' . $a . '-' . $m;
    if (!isset($cache[$cle])) {
        $cache[$cle] = calcul_fiche($user, $a, $m, false);
    }
    return (float)($cache[$cle]['jours'][$ymd]['prime'] ?? 0);
}

/**
 * Éléments tardifs qui concernent la prime d'un associé un jour donné :
 * repas du midi auxquels il a participé, et ses congés / formations.
 * Retourne [[mois_index de rattachement, description], …]
 */
function elements_tardifs_jour(array $user, string $ymd): array
{
    $uid = (int)$user['id'];
    $out = [];
    $deps = qall("SELECT id FROM {depenses} WHERE date_depense = ? AND regul_annee IS NOT NULL
                  AND categorie = 'repas' AND repas_midi = 1", [$ymd]);
    foreach ($deps as $r) {
        $d = depense_get((int)$r['id']);
        if ($d && est_participant($d, $user)) {
            $lib = ((int)$d['equipe'] ? "repas d'équipe" : 'repas du midi')
                . ((int)$d['user_id'] === $uid ? '' : ' réglé par ' . $d['payeur_prenom']) . (avant_tonton(...mois_de_date($ymd)) ? ' saisi dans Tonton' : ' saisi après la clôture');
            $out[] = [mois_index((int)$d['regul_annee'], (int)$d['regul_mois']), $lib, (int)$d['id']];
        }
    }
    $abs = q1('SELECT * FROM {absences} WHERE user_id = ? AND date_absence = ? AND regul_annee IS NOT NULL', [$uid, $ymd]);
    if ($abs) {
        $out[] = [mois_index((int)$abs['regul_annee'], (int)$abs['regul_mois']),
            ($abs['type'] === 'conge' ? 'congé déclaré' : 'formation déclarée') . (avant_tonton(...mois_de_date($ymd)) ? ' dans Tonton' : ' après la clôture'), null];
    }
    return $out;
}

function regul_choix_actif(int $uid, string $ymd): bool
{
    $r = q1('SELECT actif FROM {regul_choix} WHERE user_id = ? AND date_ref = ?', [$uid, $ymd]);
    return $r ? (bool)(int)$r['actif'] : true;
}

/**
 * Lignes de régularisation de la fiche d'une personne pour un mois.
 * Chaque ligne : type (ticket | prime | absence | libre), date, libelle, montant,
 * actif, compte (compté dans le total), et selon le type depense / id / date_ref.
 */
function regularisations_fiche(array $user, int $a, int $m): array
{
    $uid = (int)$user['id'];
    $lignes = [];

    // 1. Tickets tardifs rattachés à ce mois
    $tardifs = qall('SELECT id FROM {depenses} WHERE regul_annee = ? AND regul_mois = ? ORDER BY date_depense, id', [$a, $m]);
    $jours_prime = [];
    foreach ($tardifs as $r) {
        $d = depense_get((int)$r['id']);
        if (!$d) {
            continue;
        }
        if ((int)$d['user_id'] === $uid) {
            $rembourse = est_avance($d) || $d['categorie'] === 'km';
            $lignes[] = [
                'type' => 'ticket', 'date' => $d['date_depense'], 'depense' => $d,
                'libelle' => 'Ticket du ' . date_fr($d['date_depense']) . ' : ' . ($d['objet'] ?: CATEGORIES[$d['categorie']])
                    . (lieu_complet($d) !== '' ? ' — ' . lieu_complet($d) : '')
                    . ($rembourse ? '' : ' (CB entreprise, non remboursé)'),
                'montant' => $rembourse ? (float)$d['montant'] : 0.0, 'actif' => true, 'compte' => $rembourse,
            ];
        }
        if ($d['categorie'] === 'repas' && (int)$d['repas_midi'] && est_participant($d, $user)) {
            $jours_prime[$d['date_depense']] = true;
        }
    }

    // 2. Congés / formations déclarés en retard, rattachés à ce mois
    foreach (qall('SELECT * FROM {absences} WHERE user_id = ? AND regul_annee = ? AND regul_mois = ? ORDER BY date_absence',
        [$uid, $a, $m]) as $abs) {
        $lignes[] = [
            'type' => 'absence', 'date' => $abs['date_absence'], 'id' => (int)$abs['id'],
            'libelle' => ($abs['type'] === 'conge' ? 'Congé du ' : 'Formation du ') . date_fr($abs['date_absence'], true)
                . ($abs['type'] === 'conge' ? ' déclaré' : ' déclarée')
                . (avant_tonton(...mois_de_date($abs['date_absence'])) ? ' (mois antérieur à Tonton)' : ' après la clôture'),
            'montant' => 0.0, 'actif' => true, 'compte' => false,
        ];
        $jours_prime[$abs['date_absence']] = true;
    }

    // 3. Déduction de la prime repas déjà versée (associés seulement, une fois par jour)
    if (est_associe($user)) {
        $ici = mois_index($a, $m);
        foreach (array_keys($jours_prime) as $ymd) {
            $elements = elements_tardifs_jour($user, $ymd);
            $premier = min(array_map(fn($e) => $e[0], $elements) ?: [$ici]);
            if ($premier < $ici) {
                continue; // déjà régularisé sur un mois précédent
            }
            $prime = prime_versee($user, $ymd);
            $motifs = implode(', ', array_unique(array_map(fn($e) => $e[1], array_filter($elements, fn($e) => $e[0] === $ici))));
            if ($prime > 0) {
                $actif = regul_choix_actif($uid, $ymd);
                $lignes[] = [
                    'type' => 'prime', 'date' => $ymd, 'date_ref' => $ymd,
                    'libelle' => 'Prime repas du ' . date_fr($ymd, true)
                        . (avant_tonton(...mois_de_date($ymd)) ? ' présumée versée hors Tonton (' : ' déjà versée (') . $motifs . ')',
                    'montant' => -$prime, 'actif' => $actif, 'compte' => $actif,
                ];
            } else {
                $lignes[] = [
                    'type' => 'info', 'date' => $ymd,
                    'libelle' => 'Pas de prime repas versée le ' . date_fr($ymd, true) . ' : rien à déduire',
                    'montant' => 0.0, 'actif' => true, 'compte' => false,
                ];
            }
        }
    }

    // 4. Lignes libres saisies par l'administrateur
    foreach (qall('SELECT * FROM {regul_libres} WHERE user_id = ? AND annee = ? AND mois = ? ORDER BY id', [$uid, $a, $m]) as $l) {
        $lignes[] = [
            'type' => 'libre', 'date' => $l['date_ref'], 'id' => (int)$l['id'],
            'libelle' => $l['libelle'], 'montant' => (float)$l['montant'], 'actif' => true, 'compte' => true,
        ];
    }

    usort($lignes, fn($x, $y) => [($x['date'] ?? '9999'), $x['type'] === 'prime' ? 1 : 0] <=> [($y['date'] ?? '9999'), $y['type'] === 'prime' ? 1 : 0]);
    return $lignes;
}

function total_regularisations(array $lignes): float
{
    $t = 0.0;
    foreach ($lignes as $l) {
        if ($l['compte']) {
            $t += $l['montant'];
        }
    }
    return round($t, 2);
}

/**
 * Tickets et congés datés d'avant le premier mois géré qui ne sont pas en régularisation :
 * saisis avant que le démarrage soit fixé, ils ont été traités hors de Tonton
 * et ne comptent nulle part (ils restent en base, sans être modifiables).
 */
function nb_hors_tonton(): int
{
    [$pa, $pm] = premier_mois();
    $debut = sprintf('%04d-%02d-01', $pa, $pm);
    return (int)q1('SELECT COUNT(*) n FROM {depenses} WHERE regul_annee IS NULL AND date_depense < ?', [$debut])['n']
        + (int)q1('SELECT COUNT(*) n FROM {absences} WHERE regul_annee IS NULL AND date_absence < ?', [$debut])['n'];
}

/** Enregistre un congé / une formation tardif(ve) sur un mois clos, rattaché(e) au premier mois ouvert */
function absence_tardive(int $user_id, string $ymd, string $type, string $source): bool
{
    if (q1('SELECT 1 x FROM {absences} WHERE user_id = ? AND date_absence = ?', [$user_id, $ymd])) {
        return false; // un jour déjà déclaré n'est pas modifié
    }
    [$ra, $rm] = mois_rattachement($ymd);
    q('INSERT INTO {absences} (user_id, date_absence, type, source, regul_annee, regul_mois) VALUES (?, ?, ?, ?, ?, ?)',
        [$user_id, $ymd, $type, $source, $ra, $rm]);
    return true;
}
