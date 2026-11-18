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
 *  - les congés / formations déclarés en retard (pour information) ;
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

/**
 * Lignes de régularisation de la fiche d'une personne pour un mois.
 * Chaque ligne : type (ticket | absence | libre), date, libelle, montant,
 * actif, compte (compté dans le total), et selon le type depense / id.
 */
function regularisations_fiche(array $user, int $a, int $m): array
{
    $uid = (int)$user['id'];
    $lignes = [];

    // 1. Tickets tardifs rattachés à ce mois
    $tardifs = qall('SELECT id FROM {depenses} WHERE regul_annee = ? AND regul_mois = ? ORDER BY date_depense, id', [$a, $m]);
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
    }

    // 3. Lignes libres saisies par l'administrateur
    foreach (qall('SELECT * FROM {regul_libres} WHERE user_id = ? AND annee = ? AND mois = ? ORDER BY id', [$uid, $a, $m]) as $l) {
        $lignes[] = [
            'type' => 'libre', 'date' => $l['date_ref'], 'id' => (int)$l['id'],
            'libelle' => $l['libelle'], 'montant' => (float)$l['montant'], 'actif' => true, 'compte' => true,
        ];
    }

    usort($lignes, fn($x, $y) => ($x['date'] ?? '9999') <=> ($y['date'] ?? '9999'));
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
