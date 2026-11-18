<?php
declare(strict_types=1);

const CATEGORIES = [
    'repas'     => 'Repas',
    'peage'     => 'Péage / stationnement',
    'carburant' => 'Carburant',
    'km'        => 'Frais kilométriques',
    'autres'    => 'Autres',
];

const PAIEMENTS = [
    'cb_perso' => 'CB perso',
    'especes'  => 'Espèces',
    'cb_pro'   => 'CB entreprise',
    'perso'    => 'Véhicule perso', // frais kilométriques
];

/** Moyens de paiement qu'on peut choisir par défaut pour un nouveau ticket */
const PAIEMENTS_DEFAUT = ['cb_perso', 'cb_pro', 'especes'];

function paiement_defaut(?array $u): string
{
    $p = (string)($u['paiement_defaut'] ?? '');
    return in_array($p, PAIEMENTS_DEFAUT, true) ? $p : 'cb_perso';
}

const STATUTS = [
    'ouvert'   => 'En cours de saisie',
    'valide'   => 'Validé',
    'paye'     => 'Payé',
    'transmis' => 'Transmis au comptable',
];

/* ------------------------------------------------------------------ */
/* Dates                                                              */
/* ------------------------------------------------------------------ */

function aujourdhui(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(cfg('timezone') ?? 'Europe/Paris')))->format('Y-m-d');
}

function paques(int $annee): DateTimeImmutable
{
    // Algorithme de Meeus/Jones/Butcher (calendrier grégorien)
    $a = $annee % 19;
    $b = intdiv($annee, 100);
    $c = $annee % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mois = intdiv($h + $l - 7 * $m + 114, 31);
    $jour = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $annee, $mois, $jour));
}

/** Jours fériés (France métropolitaine) : ['Y-m-d' => libellé] */
function feries(int $annee): array
{
    static $cache = [];
    if (isset($cache[$annee])) {
        return $cache[$annee];
    }
    $p = paques($annee);
    $liste = [
        'jour_an'         => ["$annee-01-01", "Jour de l'an"],
        'lundi_paques'    => [$p->modify('+1 day')->format('Y-m-d'), 'Lundi de Pâques'],
        'fete_travail'    => ["$annee-05-01", 'Fête du travail'],
        'victoire_1945'   => ["$annee-05-08", 'Victoire 1945'],
        'ascension'       => [$p->modify('+39 days')->format('Y-m-d'), 'Ascension'],
        'lundi_pentecote' => [$p->modify('+50 days')->format('Y-m-d'), 'Lundi de Pentecôte'],
        'fete_nationale'  => ["$annee-07-14", 'Fête nationale'],
        'assomption'      => ["$annee-08-15", 'Assomption'],
        'toussaint'       => ["$annee-11-01", 'Toussaint'],
        'armistice'       => ["$annee-11-11", 'Armistice'],
        'noel'            => ["$annee-12-25", 'Noël'],
    ];
    $travailles = cfg('feries_travailles') ?? [];
    $out = [];
    foreach ($liste as $code => [$date, $lib]) {
        if (!in_array($code, $travailles, true)) {
            $out[$date] = $lib;
        }
    }
    return $cache[$annee] = $out;
}

function mois_bornes(int $annee, int $mois): array
{
    $debut = sprintf('%04d-%02d-01', $annee, $mois);
    $nb = (int)(new DateTimeImmutable($debut))->format('t');
    return [$debut, sprintf('%04d-%02d-%02d', $annee, $mois, $nb), $nb];
}

/**
 * Premier mois géré par Tonton (« AAAA-MM », octobre 2026 par défaut).
 * Les mois précédents ont été traités hors de Tonton : ils ne s'affichent pas et sont
 * considérés comme clos ; un ticket ou un congé daté d'avant est régularisé sur le
 * premier mois ouvert.
 */
function premier_mois(): array
{
    $v = (string)(cfg('premier_mois') ?? '2026-10');
    if (!preg_match('/^(\d{4})-(\d{2})$/', $v, $p) || (int)$p[2] < 1 || (int)$p[2] > 12) {
        $p = [null, '2026', '10'];
    }
    return [(int)$p[1], (int)$p[2]];
}

/** Ce mois est-il antérieur au démarrage de Tonton ? */
function avant_tonton(int $a, int $m): bool
{
    [$pa, $pm] = premier_mois();
    return $a * 12 + $m < $pa * 12 + $pm;
}

/** Mois sélectionné via ?a=2026&m=9, sinon mois courant (jamais avant le premier mois géré) */
function mois_demande(): array
{
    $a = (int)($_GET['a'] ?? 0);
    $m = (int)($_GET['m'] ?? 0);
    if ($a < 2020 || $a > 2100 || $m < 1 || $m > 12) {
        [$a, $m] = array_map('intval', explode('-', substr(aujourdhui(), 0, 7)));
    }
    if (avant_tonton($a, $m)) {
        [$a, $m] = premier_mois();
    }
    return [$a, $m];
}

function mois_voisins(int $a, int $m): array
{
    $prev = $m === 1 ? [$a - 1, 12] : [$a, $m - 1];
    $next = $m === 12 ? [$a + 1, 1] : [$a, $m + 1];
    return [$prev, $next];
}

/* ------------------------------------------------------------------ */
/* Mois (statut)                                                      */
/* ------------------------------------------------------------------ */

function mois_info(int $a, int $m): array
{
    $r = q1('SELECT * FROM {mois} WHERE annee = ? AND mois = ?', [$a, $m]);
    if (!$r) {
        // Pas encore enregistré : valeurs par défaut (la ligne est créée à la première action)
        return ['annee' => $a, 'mois' => $m, 'statut' => 'ouvert',
            'valide_at' => null, 'paye_at' => null, 'transmis_at' => null, 'existe' => false];
    }
    $r['existe'] = true;
    return $r;
}

function mois_set_statut(int $a, int $m, string $statut): void
{
    $info = mois_info($a, $m);
    if (!$info['existe']) {
        // prime_montant : colonne héritée du Ft repas (supprimé), laissée à 0
        q('INSERT INTO {mois} (annee, mois, statut, prime_montant) VALUES (?, ?, ?, 0)', [$a, $m, 'ouvert']);
    }
    $champ = ['valide' => 'valide_at', 'paye' => 'paye_at', 'transmis' => 'transmis_at'][$statut] ?? null;
    if ($champ) {
        q("UPDATE {mois} SET statut = ?, $champ = UTC_TIMESTAMP() WHERE annee = ? AND mois = ?", [$statut, $a, $m]);
    } else {
        q('UPDATE {mois} SET statut = ?, valide_at = NULL, paye_at = NULL, transmis_at = NULL WHERE annee = ? AND mois = ?',
            [$statut, $a, $m]);
    }
}

function mois_ouvert(int $a, int $m): bool
{
    if (avant_tonton($a, $m)) {
        return false;
    }
    return mois_info($a, $m)['statut'] === 'ouvert';
}

function mois_de_date(string $ymd): array
{
    return [(int)substr($ymd, 0, 4), (int)substr($ymd, 5, 2)];
}

/* ------------------------------------------------------------------ */
/* Utilisateurs                                                       */
/* ------------------------------------------------------------------ */

/*
 * Rôles : un seul champ « role » ∈ {admin, associe, salarie}.
 *  - admin   : administre l'application ; pas de fiche, sauf option admin_fiche
 *              (fiche salarié validée par un associé, jamais par lui-même) ;
 *  - associe : fiche mensuelle de remboursement des avances ;
 *  - salarie : fiche mensuelle RH (phase 2, module désactivable).
 */

function est_admin(?array $u): bool
{
    return ($u['role'] ?? '') === 'admin';
}

/** Associé : fiche mensuelle de remboursement des avances */
function est_associe(?array $u): bool
{
    return ($u['role'] ?? '') === 'associe';
}

/** A une fiche mensuelle RH (salarié, ou administrateur avec fiche) — seulement si le module est activé */
function est_salarie_fiche(?array $u): bool
{
    if (!$u || !module_salaries()) {
        return false;
    }
    return $u['role'] === 'salarie' || ($u['role'] === 'admin' && (int)($u['admin_fiche'] ?? 0));
}

/** Associés actifs (fiche de remboursement des avances) */
function associes(bool $actifs_seulement = true): array
{
    return qall("SELECT * FROM {users} WHERE role = 'associe'" . ($actifs_seulement ? ' AND actif = 1' : '')
        . ' ORDER BY prenom, nom');
}

/** Alias : tous les associés actifs, pour choisir un responsable ou un rembourseur */
function associes_tous(): array
{
    return associes();
}

/** Personnes ayant une fiche mensuelle RH (vide tant que le module salariés est désactivé) */
function salaries(bool $actifs_seulement = true): array
{
    if (!module_salaries()) {
        return [];
    }
    return qall("SELECT * FROM {users} WHERE (role = 'salarie' OR (role = 'admin' AND admin_fiche = 1))"
        . ($actifs_seulement ? ' AND actif = 1' : '') . ' ORDER BY prenom, nom');
}

/** Fiches RH dont l'utilisateur est responsable (validation) — personne ne valide sa propre fiche */
function employes_suivis(array $u): array
{
    if (!module_salaries()) {
        return [];
    }
    if (est_admin($u)) {
        return array_values(array_filter(salaries(), fn($e) => (int)$e['id'] !== (int)$u['id']));
    }
    if (!est_associe($u)) {
        return [];
    }
    return array_values(array_filter(salaries(), fn($e) => (int)$e['responsable_id'] === (int)$u['id']));
}

function nom_complet(array $u): string
{
    return trim($u['prenom'] . ' ' . $u['nom']);
}

/* ------------------------------------------------------------------ */
/* Dépenses                                                           */
/* ------------------------------------------------------------------ */

/**
 * Membres de l'entreprise proposés comme participants aux repas.
 * Ordre : la personne qui saisit (si elle a une fiche), puis les autres
 * associés ayant une fiche, puis les autres membres dans l'ordre défini.
 */
function membres_pour(?array $user, bool $actifs = true): array
{
    $rows = qall('SELECT m.*, u.role, u.actif AS user_actif FROM {membres} m
                  LEFT JOIN {users} u ON u.id = m.user_id'
        . ($actifs ? ' WHERE m.actif = 1' : '') . ' ORDER BY m.ordre, m.prenom');
    $moi = $user ? (int)$user['id'] : 0;
    $rang = function (array $m) use ($moi): int {
        $assoc = $m['user_id'] !== null && ($m['role'] ?? '') === 'associe';
        if ($assoc && (int)$m['user_id'] === $moi) {
            return 0;
        }
        return $assoc && (int)$m['user_actif'] ? 1 : 2;
    };
    usort($rows, fn($a, $b) => [$rang($a), (int)$a['ordre'], $a['prenom']] <=> [$rang($b), (int)$b['ordre'], $b['prenom']]);
    return $rows;
}

function membre_de_user(int $user_id): ?array
{
    return q1('SELECT * FROM {membres} WHERE user_id = ?', [$user_id]);
}

/** Associe un compte à un membre (par initiales), ou crée le membre. */
function lier_membre(int $user_id): void
{
    $u = q1('SELECT * FROM {users} WHERE id = ?', [$user_id]);
    // Un compte administrateur sans fiche (compte de gestion) n'est pas un
    // participant aux repas : il n'est relié à aucun membre de l'équipe.
    if ($u && $u['role'] === 'admin' && !(int)$u['admin_fiche']) {
        q('UPDATE {membres} SET user_id = NULL WHERE user_id = ?', [$user_id]);
        return;
    }
    if (!$u || membre_de_user($user_id)) {
        if ($u) {
            q('UPDATE {membres} SET prenom = ?, initiales = ? WHERE user_id = ?', [$u['prenom'], $u['initiales'], $user_id]);
        }
        return;
    }
    $m = q1('SELECT * FROM {membres} WHERE initiales = ? AND user_id IS NULL', [$u['initiales']]);
    if ($m) {
        q('UPDATE {membres} SET user_id = ?, actif = 1 WHERE id = ?', [$user_id, $m['id']]);
    } else {
        $ordre = (int)(q1('SELECT COALESCE(MAX(ordre), 0) + 1 o FROM {membres}')['o']);
        q('INSERT INTO {membres} (prenom, initiales, user_id, ordre, actif) VALUES (?, ?, ?, ?, 1)',
            [$u['prenom'], $u['initiales'], $user_id, $ordre]);
    }
}

/**
 * Dépenses d'une période, avec payeur et participants.
 * $filtre : ['mois' => [a, m]] dépenses comptées dans ce mois : celles datées du mois
 *           (hors tickets tardifs rattachés ailleurs) + les tickets tardifs rattachés au mois,
 *           ['user' => id] dépenses saisies par l'utilisateur,
 *           ['concerne' => id] saisies par lui OU dont il est participant
 *           (y compris les repas d'équipe pour les associés ayant une fiche).
 */
function depenses_periode(string $debut, string $fin, array $filtre = []): array
{
    $sql = 'SELECT d.*, u.prenom AS payeur_prenom, u.nom AS payeur_nom, u.initiales AS payeur_initiales
            FROM {depenses} d JOIN {users} u ON u.id = d.user_id
            WHERE ';
    if (isset($filtre['mois'])) {
        [$cond, $p] = sql_mois_depense((int)$filtre['mois'][0], (int)$filtre['mois'][1]);
        $sql .= $cond;
    } else {
        $sql .= 'd.date_depense BETWEEN ? AND ?';
        $p = [$debut, $fin];
    }
    if (isset($filtre['user'])) {
        $sql .= ' AND d.user_id = ?';
        $p[] = (int)$filtre['user'];
    }
    if (isset($filtre['concerne'])) {
        $uid = (int)$filtre['concerne'];
        $sql .= ' AND (d.user_id = ?
                  OR d.id IN (SELECT c.depense_id FROM {convives} c JOIN {membres} m ON m.id = c.membre_id WHERE m.user_id = ?))';
        array_push($p, $uid, $uid);
    }
    $sql .= ' ORDER BY d.date_depense, d.id';
    $rows = qall($sql, $p);
    if (!$rows) {
        return [];
    }
    $ids = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $parts = qall("SELECT c.depense_id, m.id, m.prenom, m.initiales, m.user_id
                   FROM {convives} c JOIN {membres} m ON m.id = c.membre_id
                   WHERE c.depense_id IN ($in) ORDER BY m.ordre, m.prenom", $ids);
    $par = [];
    foreach ($parts as $pt) {
        $par[$pt['depense_id']][] = $pt;
    }
    foreach ($rows as &$r) {
        $r['participants'] = $par[$r['id']] ?? [];
        $r['est_regul'] = $r['regul_annee'] !== null;
        $r['montant'] = (float)$r['montant'];
        $r['kms'] = $r['kms'] !== null ? (float)$r['kms'] : null;
    }
    return $rows;
}

function depense_get(int $id): ?array
{
    $d = q1('SELECT d.*, u.prenom AS payeur_prenom, u.nom AS payeur_nom, u.initiales AS payeur_initiales
             FROM {depenses} d JOIN {users} u ON u.id = d.user_id WHERE d.id = ?', [$id]);
    if (!$d) {
        return null;
    }
    $d['participants'] = qall('SELECT m.id, m.prenom, m.initiales, m.user_id FROM {convives} c
                               JOIN {membres} m ON m.id = c.membre_id WHERE c.depense_id = ? ORDER BY m.ordre, m.prenom', [$id]);
    $d['est_regul'] = $d['regul_annee'] !== null;
    $d['montant'] = (float)$d['montant'];
    $d['kms'] = $d['kms'] !== null ? (float)$d['kms'] : null;
    return $d;
}

/**
 * L'utilisateur est-il compté comme présent à ce repas ?
 * Repas d'équipe : cocher « Équipe » coche les associés, que l'on peut décocher (associé absent) ;
 * seuls les participants cochés comptent.
 */
function est_participant(array $dep, array $user): bool
{
    foreach ($dep['participants'] ?? [] as $p) {
        if ($p['user_id'] !== null && (int)$p['user_id'] === (int)$user['id']) {
            return true;
        }
    }
    return false;
}

/** Libellé court des participants : « Équipe », « FC + DP + invité(s) »… */
function libelle_participants(array $dep): string
{
    $parts = [];
    if ((int)($dep['equipe'] ?? 0)) {
        $parts[] = 'Équipe';
    }
    foreach ($dep['participants'] ?? [] as $p) {
        $parts[] = $p['initiales'];
    }
    if (trim((string)($dep['invites'] ?? '')) !== '') {
        $parts[] = 'invité(s)';
    }
    return implode(' + ', $parts);
}

function peut_voir_depense(array $dep, array $user): bool
{
    return est_admin($user) || (int)$dep['user_id'] === (int)$user['id'] || est_participant($dep, $user);
}

function peut_modifier_depense(array $dep, array $user): bool
{
    [$a, $m] = mois_effectif($dep);
    if (!mois_ouvert($a, $m)) {
        return false;
    }
    if (est_admin($user)) {
        return true;
    }
    // un ticket déjà remboursé ne peut plus être modifié par le salarié
    return (int)$dep['user_id'] === (int)$user['id'] && ($dep['remb_statut'] ?? null) !== 'fait';
}

/** Libellé d'objet proposé automatiquement si l'utilisateur n'en saisit pas */
function objet_auto(array $dep, array $participants_initiales): string
{
    $txt = match ($dep['categorie']) {
        'repas'     => (int)($dep['equipe'] ?? 0) ? "Repas d'équipe" : ($dep['repas_midi'] ? 'Repas midi' : 'Repas'),
        'peage'     => 'Péage / stationnement',
        'carburant' => 'Carburant',
        'km'        => 'Déplacement',
        default     => 'Achat',
    };
    if ($dep['categorie'] === 'repas') {
        if ($participants_initiales) {
            $txt .= ' ' . implode(' + ', $participants_initiales);
        }
        if (trim((string)$dep['invites']) !== '') {
            $txt .= ' + ' . $dep['invites'];
        }
    }
    if ($dep['paiement'] === 'cb_perso' || $dep['paiement'] === 'especes') {
        $txt = ($dep['paiement'] === 'especes' ? 'Avance espèces ' : 'Avance CB perso ') . mb_strtolower(mb_substr($txt, 0, 1)) . mb_substr($txt, 1);
    }
    return mb_substr($txt, 0, 255);
}

/* ------------------------------------------------------------------ */
/* Absences                                                           */
/* ------------------------------------------------------------------ */

function absences_periode(string $debut, string $fin, ?int $user_id = null, bool $avec_tardives = true): array
{
    $sql = 'SELECT * FROM {absences} WHERE date_absence BETWEEN ? AND ?' . ($avec_tardives ? '' : ' AND regul_annee IS NULL');
    $p = [$debut, $fin];
    if ($user_id) {
        $sql .= ' AND user_id = ?';
        $p[] = $user_id;
    }
    $out = [];
    foreach (qall($sql, $p) as $r) {
        $out[(int)$r['user_id']][$r['date_absence']] = $r['type'];
    }
    return $out;
}

/**
 * Ajoute (ou retire) une période de congé / formation en journées entières :
 * seuls les jours ouvrés hors fériés sont comptés.
 * Sur un mois déjà validé (clos), le jour est enregistré « en retard » : il est
 * régularisé sur le premier mois encore ouvert (voir lib/regularisation.php).
 * Retourne [jours traités, jours ignorés, jours enregistrés en régularisation].
 */
function absences_periode_maj(int $user_id, string $du, string $au, string $type, bool $ajout, string $source): array
{
    $n = 0;
    $verrouilles = 0;
    $tardifs = 0;
    for ($d = new DateTimeImmutable($du); $d->format('Y-m-d') <= $au; $d = $d->modify('+1 day')) {
        $ymd = $d->format('Y-m-d');
        if ((int)$d->format('N') >= 6 || isset(feries((int)$d->format('Y'))[$ymd])) {
            continue;
        }
        [$a, $m] = mois_de_date($ymd);
        if (!mois_ouvert($a, $m)) {
            if ($ajout) {
                if (absence_tardive($user_id, $ymd, $type, $source)) {
                    $tardifs++;
                } else {
                    $verrouilles++;
                }
            } else {
                // retrait possible seulement pour un jour tardif dont le mois de rattachement est ouvert
                $r = q1('SELECT * FROM {absences} WHERE user_id = ? AND date_absence = ? AND type = ? AND regul_annee IS NOT NULL',
                    [$user_id, $ymd, $type]);
                if ($r && mois_ouvert((int)$r['regul_annee'], (int)$r['regul_mois'])) {
                    q('DELETE FROM {absences} WHERE id = ?', [$r['id']]);
                    $n++;
                } else {
                    $verrouilles++;
                }
            }
            continue;
        }
        if ($ajout) {
            absence_set($user_id, $ymd, $type, $source);
            $n++;
        } elseif (q1('SELECT 1 x FROM {absences} WHERE user_id = ? AND date_absence = ? AND type = ?', [$user_id, $ymd, $type])) {
            absence_set($user_id, $ymd, null);
            $n++;
        }
    }
    return [$n, $verrouilles, $tardifs];
}

/** Périodes de congé et de formation d'une personne sur un mois : [[du, au, nb jours, type], …] */
function absences_periodes(int $user_id, int $a, int $m): array
{
    [$debut, $fin] = mois_bornes($a, $m);
    $abs = absences_periode($debut, $fin, $user_id, false)[$user_id] ?? [];
    $out = [];
    foreach (['conge', 'formation'] as $type) {
        foreach (periodes(array_keys(array_filter($abs, fn($t) => $t === $type))) as [$du, $au, $nb]) {
            $out[] = [$du, $au, $nb, $type];
        }
    }
    usort($out, fn($x, $y) => strcmp($x[0], $y[0]));
    return $out;
}

function absence_set(int $user_id, string $date, ?string $type, string $source = 'manuel'): void
{
    q('DELETE FROM {absences} WHERE user_id = ? AND date_absence = ?', [$user_id, $date]);
    if ($type === 'conge' || $type === 'formation') {
        q('INSERT INTO {absences} (user_id, date_absence, type, source) VALUES (?, ?, ?, ?)',
            [$user_id, $date, $type, $source]);
    }
}

/* ------------------------------------------------------------------ */
/* Fiche mensuelle (remboursement des avances des associés)           */
/* ------------------------------------------------------------------ */

/**
 * Construit la fiche mensuelle d'un associé : une ligne par jour,
 * uniquement les sommes à lui régler (avances CB perso ou espèces, frais km).
 * Le forfait repas (Ft repas) a été supprimé : il n'est pas applicable aux TNS.
 * Les jours fériés, congés et formations restent affichés à titre d'information.
 */
function calcul_fiche(array $user, int $a, int $m, bool $avec_regul = true): array
{
    [$debut, $fin, $nb] = mois_bornes($a, $m);
    $info = mois_info($a, $m);
    $uid = (int)$user['id'];
    $feries = feries($a);
    // les congés déclarés après la clôture ne modifient pas la fiche de leur mois
    $abs = absences_periode($debut, $fin, $uid, false)[$uid] ?? [];
    $definitif = $info['statut'] !== 'ouvert' || $fin < aujourdhui();

    // tickets datés du mois (les tickets tardifs sont traités en régularisation)
    $deps = array_values(array_filter(depenses_periode($debut, $fin, ['concerne' => $uid, 'mois' => [$a, $m]]),
        fn($d) => !$d['est_regul']));

    $jours = [];
    for ($j = 1; $j <= $nb; $j++) {
        $d = sprintf('%04d-%02d-%02d', $a, $m, $j);
        $jours[$d] = [
            'date' => $d, 'jour_semaine' => (int)(new DateTimeImmutable($d))->format('N'),
            'objets' => [], 'etiquettes' => [], 'tickets' => [], 'lieux' => [], 'kms' => 0.0, 'coef' => null, 'total_kms' => 0.0,
            'peage' => 0.0, 'repas' => 0.0, 'carburant' => 0.0, 'autres' => 0.0,
            'motif' => null, 'cb_pro' => [], 'total' => 0.0,
        ];
    }

    $cb_pro = [];
    foreach ($deps as $dep) {
        $d = $dep['date_depense'];
        $row = &$jours[$d];
        if ((int)$dep['user_id'] !== $uid) {
            unset($row);
            continue;
        }
        if ($dep['paiement'] === 'cb_pro') {
            $row['cb_pro'][] = $dep;
            $cb_pro[] = $dep;
            unset($row);
            continue;
        }
        // Montants à régler à l'associé
        $row['objets'][] = $dep['objet'];
        $row['tickets'][] = ['id' => (int)$dep['id'], 'objet' => $dep['objet']];
        if (trim((string)$dep['lieu']) !== '') {
            $row['lieux'][] = lieu_complet($dep);
        }
        switch ($dep['categorie']) {
            case 'km':
                $row['kms'] += (float)$dep['kms'];
                $row['coef'] = (float)$dep['coef_km'];
                $row['total_kms'] += $dep['montant'];
                break;
            case 'peage':
                $row['peage'] += $dep['montant'];
                break;
            case 'repas':
                $row['repas'] += $dep['montant'];
                break;
            case 'carburant':
                $row['carburant'] += $dep['montant'];
                break;
            default:
                $row['autres'] += $dep['montant'];
        }
        unset($row);
    }

    foreach ($jours as $d => &$row) {
        if ($row['jour_semaine'] >= 6) {
            $row['motif'] = 'weekend';
        } elseif (isset($feries[$d])) {
            $row['motif'] = 'ferie';
            array_unshift($row['objets'], $feries[$d]);
            $row['etiquettes'][] = $feries[$d];
        } elseif (isset($abs[$d])) {
            $row['motif'] = $abs[$d];
            array_unshift($row['objets'], $abs[$d] === 'conge' ? 'Congé' : 'Formation');
            $row['etiquettes'][] = $abs[$d] === 'conge' ? 'Congé' : 'Formation';
        }
        $row['total'] = round($row['total_kms'] + $row['peage'] + $row['repas'] + $row['carburant'] + $row['autres'], 2);
    }
    unset($row);

    $tot = ['kms' => 0.0, 'total_kms' => 0.0, 'peage' => 0.0, 'repas' => 0.0, 'carburant' => 0.0, 'autres' => 0.0, 'total' => 0.0];
    foreach ($jours as $row) {
        foreach ($tot as $k => $_) {
            $tot[$k] += $row[$k];
        }
    }
    $tot = array_map(fn($v) => round($v, 2), $tot);

    // Régularisations des mois précédents (tickets et congés arrivés après la clôture)
    $regul = $avec_regul ? regularisations_fiche($user, $a, $m) : [];
    $total_regul = total_regularisations($regul);
    $tot['total_tableau'] = $tot['total'];
    $tot['total'] = round($tot['total'] + $total_regul, 2);

    return [
        'user' => $user, 'annee' => $a, 'mois' => $m, 'info' => $info, 'definitif' => $definitif,
        'regul' => $regul, 'total_regul' => $total_regul,
        'jours' => $jours, 'totaux' => $tot,
        'total_avances' => round($tot['total_tableau'] - $tot['total_kms'], 2),
        'cb_pro' => $cb_pro, 'total_cb_pro' => round(array_sum(array_column($cb_pro, 'montant')), 2),
        'coef_km' => (float)$user['coef_km'],
    ];
}

/** Tous les justificatifs du mois, classés par payeur puis date (pour le PDF du comptable) */
function justificatifs_mois(int $a, int $m, ?int $user_id = null): array
{
    [$debut, $fin] = mois_bornes($a, $m);
    $deps = depenses_periode($debut, $fin, ['mois' => [$a, $m]] + ($user_id ? ['user' => $user_id] : []));
    usort($deps, fn($x, $y) => [$x['payeur_prenom'], $x['date_depense'], $x['id']] <=> [$y['payeur_prenom'], $y['date_depense'], $y['id']]);
    return $deps;
}

/* ------------------------------------------------------------------ */
/* Fichiers justificatifs                                             */
/* ------------------------------------------------------------------ */

/** Dossier des données (justificatifs, sessions, verrous), hors du site */
function data_dir(): string
{
    return rtrim((string)(cfg('data_dir') ?? dirname(APP_ROOT) . '/tonton-data'), "/\\");
}

function chemin_justificatif(?string $rel): ?string
{
    if (!$rel || !preg_match('#^justificatifs/\d{4}/\d{2}/[a-f0-9]{32}\.jpg$#', $rel)) {
        return null;
    }
    $p = data_dir() . '/' . $rel;
    return is_file($p) ? $p : null;
}

/** Miniature (créée à la volée et mise en cache à côté de l'original) */
function chemin_miniature(?string $rel): ?string
{
    $orig = chemin_justificatif($rel);
    if (!$orig) {
        return null;
    }
    $mini = substr($orig, 0, -4) . '_t.jpg';
    if (is_file($mini)) {
        return $mini;
    }
    if (!function_exists('imagecreatefromjpeg')) {
        return $orig;
    }
    $img = @imagecreatefromjpeg($orig);
    if (!$img) {
        return $orig;
    }
    $w = imagesx($img);
    $hgt = imagesy($img);
    $r = 240 / max($w, $hgt);
    if ($r >= 1) {
        return $orig;
    }
    $dst = imagecreatetruecolor((int)round($w * $r), (int)round($hgt * $r));
    imagecopyresampled($dst, $img, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $hgt);
    imagejpeg($dst, $mini, 75);
    return $mini;
}

/**
 * Enregistre une image téléversée en JPEG (redressée, réduite à 2000 px).
 * Retourne le chemin relatif, ou lève une exception avec un message lisible.
 */
function stocker_justificatif(string $tmp, string $date): string
{
    $info = @getimagesize($tmp);
    if (!$info) {
        throw new RuntimeException("Le fichier n'est pas une image lisible. Prenez le ticket en photo ou joignez un PDF.");
    }
    $sous = 'justificatifs/' . substr($date, 0, 4) . '/' . substr($date, 5, 2);
    $dir = data_dir() . '/' . $sous;
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        throw new RuntimeException("Impossible de créer le dossier de stockage.");
    }
    $nom = bin2hex(random_bytes(16)) . '.jpg';
    $dest = $dir . '/' . $nom;

    if (!function_exists('imagecreatetruecolor')) {
        if ($info[2] !== IMAGETYPE_JPEG) {
            throw new RuntimeException("Format d'image non pris en charge sur ce serveur.");
        }
        if (!move_uploaded_file($tmp, $dest) && !copy($tmp, $dest)) {
            throw new RuntimeException("Échec de l'enregistrement du fichier.");
        }
        return $sous . '/' . $nom;
    }

    $img = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        IMAGETYPE_GIF  => @imagecreatefromgif($tmp),
        default        => false,
    };
    if (!$img) {
        throw new RuntimeException("Format d'image non pris en charge (utilisez JPEG, PNG ou PDF).");
    }
    // Orientation EXIF (photos de téléphone envoyées sans traitement préalable)
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $o = (int)($exif['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle) {
            $img = imagerotate($img, $angle, 0);
        }
    }
    $w = imagesx($img);
    $hgt = imagesy($img);
    $max = 2000;
    if (max($w, $hgt) > $max) {
        $r = $max / max($w, $hgt);
        $nw = (int)round($w * $r);
        $nh = (int)round($hgt * $r);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $hgt);
        $img = $dst;
    } elseif ($info[2] !== IMAGETYPE_JPEG) {
        // fond blanc pour les PNG transparents
        $dst = imagecreatetruecolor($w, $hgt);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopy($dst, $img, 0, 0, 0, 0, $w, $hgt);
        $img = $dst;
    }
    if (!imagejpeg($img, $dest, 82)) {
        throw new RuntimeException("Échec de l'enregistrement du fichier.");
    }
    return $sous . '/' . $nom;
}

function supprimer_justificatif(?string $rel): void
{
    $p = chemin_justificatif($rel);
    if ($p) {
        @unlink($p);
        @unlink(substr($p, 0, -4) . '_t.jpg');
        @unlink(substr($p, 0, -4) . '_orig.jpg');
    }
}

/** Photo d'origine (avant recadrage) d'un justificatif, si elle a été conservée */
function chemin_original(?string $rel): ?string
{
    $p = chemin_justificatif($rel);
    if (!$p) {
        return null;
    }
    $o = substr($p, 0, -4) . '_orig.jpg';
    return is_file($o) ? $o : null;
}

/**
 * Conserve la photo d'origine à côté du justificatif recadré (même nom + « _orig »).
 * Un échec ne bloque pas l'enregistrement du ticket.
 */
function enregistrer_original(string $tmp, string $rel_justificatif, string $date): void
{
    $dest = chemin_justificatif($rel_justificatif);
    if (!$dest) {
        return;
    }
    try {
        $rel = stocker_justificatif($tmp, $date);
        $p = data_dir() . '/' . $rel;
        if (!@rename($p, substr($dest, 0, -4) . '_orig.jpg')) {
            @unlink($p);
        }
    } catch (Throwable $e) {
        // tant pis : le justificatif recadré suffit
    }
}

/* ------------------------------------------------------------------ */
/* Informations comptables                                            */
/* ------------------------------------------------------------------ */

/** Identité de l'entreprise à montrer au commerçant pour obtenir une facture */
function entreprise(): array
{
    $siren = preg_replace('/\D/', '', (string)(cfg('siren') ?? ''));
    $tva = '';
    if (strlen($siren) === 9) {
        $cle = (12 + 3 * ((int)$siren % 97)) % 97;
        $tva = 'FR' . sprintf('%02d', $cle) . $siren;
    }
    return [
        'nom'     => (string)(cfg('societe') ?? ''),
        'siren'   => $siren,
        'siren_affiche' => $siren ? trim(chunk_split($siren, 3, ' ')) : '',
        'tva'     => $tva,
        'adresse' => (string)(cfg('adresse') ?? ''),
        'adresse_fe' => (string)(cfg('adresse_facturation_electronique') ?? ''),
    ];
}

function seuil_facture(): float
{
    return (float)(cfg('seuil_facture_ht') ?? 150);
}

/** Taux de TVA retenu pour estimer le HT quand la TVA n'est pas connue */
function taux_tva_estime(string $categorie): float
{
    return $categorie === 'repas' ? 0.10 : 0.20;
}

/** [montant HT, estimé ?] */
function montant_ht(array $d): array
{
    if ($d['categorie'] === 'km') {
        return [(float)$d['montant'], false];
    }
    if ($d['montant_tva'] !== null && $d['montant_tva'] !== '') {
        return [round((float)$d['montant'] - (float)$d['montant_tva'], 2), false];
    }
    return [round((float)$d['montant'] / (1 + taux_tva_estime($d['categorie'])), 2), true];
}

/** Ticket de 150 € HT ou plus sans facture électronique demandée */
function alerte_facture(array $d): bool
{
    return $d['categorie'] !== 'km' && !(int)($d['facture_demandee'] ?? 0) && montant_ht($d)[0] >= seuil_facture();
}

/** « COMMERÇANT - VILLE », comme sur le tableau mensuel */
function lieu_complet(array $d): string
{
    $l = trim((string)$d['lieu']);
    $v = trim((string)($d['ville'] ?? ''));
    if ($v !== '' && mb_stripos($l, $v) === false) {
        $l = $l === '' ? $v : "$l - $v";
    }
    return $l;
}


/* ------------------------------------------------------------------ */
/* Remboursements des salariés                                        */
/* ------------------------------------------------------------------ */

const MODES_REMB = [
    'paie'     => 'Avec le salaire',
    'immediat' => 'Dès que possible',
];

/** Dépenses à rembourser à un salarié (avance perso ou espèces) */
function est_avance(array $d): bool
{
    return in_array($d['paiement'], ['cb_perso', 'especes'], true);
}

function remboursements_a_faire(?array $u): array
{
    $sql = "SELECT d.*, e.prenom AS payeur_prenom, e.nom AS payeur_nom, e.initiales AS payeur_initiales, e.email AS payeur_email
            FROM {depenses} d JOIN {users} e ON e.id = d.user_id
            WHERE d.remb_mode = 'immediat' AND d.remb_statut = 'a_faire'";
    $p = [];
    if ($u && !est_admin($u)) {
        $sql .= ' AND d.remb_par = ?';
        $p[] = (int)$u['id'];
    }
    return qall($sql . ' ORDER BY d.date_depense, d.id', $p);
}

/* ------------------------------------------------------------------ */
/* Fiche mensuelle RH (salariés)                                      */
/* ------------------------------------------------------------------ */

function fiche_rh_info(int $uid, int $a, int $m): array
{
    $r = q1('SELECT * FROM {fiches_rh} WHERE user_id = ? AND annee = ? AND mois = ?', [$uid, $a, $m]);
    return $r ?: ['user_id' => $uid, 'annee' => $a, 'mois' => $m, 'champ_libre' => '', 'prime_qualite' => 0,
        'prime_contributions' => 0, 'commentaire_resp' => '', 'valide_collab_at' => null, 'valide_resp_at' => null,
        'valide_resp_par' => null, 'existe' => false];
}

function fiche_rh_maj(int $uid, int $a, int $m, array $champs): void
{
    if (!q1('SELECT 1 x FROM {fiches_rh} WHERE user_id = ? AND annee = ? AND mois = ?', [$uid, $a, $m])) {
        q('INSERT INTO {fiches_rh} (user_id, annee, mois) VALUES (?, ?, ?)', [$uid, $a, $m]);
    }
    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($champs)));
    q("UPDATE {fiches_rh} SET $set WHERE user_id = ? AND annee = ? AND mois = ?", array_merge(array_values($champs), [$uid, $a, $m]));
}

/** Regroupe des jours en périodes consécutives (jours ouvrés) : [[du, au, nb], …] */
function periodes(array $jours): array
{
    sort($jours);
    $out = [];
    foreach ($jours as $d) {
        if ($out) {
            $dernier = &$out[count($out) - 1];
            // jour ouvré suivant (saute week-ends et fériés)
            $suivant = (new DateTimeImmutable($dernier[1]))->modify('+1 day');
            while ((int)$suivant->format('N') >= 6 || isset(feries((int)$suivant->format('Y'))[$suivant->format('Y-m-d')])) {
                $suivant = $suivant->modify('+1 day');
            }
            if ($suivant->format('Y-m-d') === $d) {
                $dernier[1] = $d;
                $dernier[2]++;
                unset($dernier);
                continue;
            }
            unset($dernier);
        }
        $out[] = [$d, $d, 1];
    }
    return $out;
}

/**
 * Données complètes de la fiche RH d'un salarié pour un mois.
 */
function calcul_fiche_rh(array $user, int $a, int $m): array
{
    [$debut, $fin] = mois_bornes($a, $m);
    $uid = (int)$user['id'];
    $info = fiche_rh_info($uid, $a, $m);
    $deps = depenses_periode($debut, $fin, ['user' => $uid, 'mois' => [$a, $m]]);
    $frais = array_values(array_filter($deps, 'est_avance'));
    $abs = absences_periode($debut, $fin, $uid)[$uid] ?? [];
    $conges = array_keys(array_filter($abs, fn($t) => $t === 'conge'));
    $formations = array_keys(array_filter($abs, fn($t) => $t === 'formation'));
    $hs = qall('SELECT * FROM {heures_sup} WHERE user_id = ? AND date_hs BETWEEN ? AND ? ORDER BY date_hs, id', [$uid, $debut, $fin]);
    $it = qall('SELECT * FROM {indic_tech} WHERE user_id = ? AND annee = ? AND mois = ? ORDER BY id', [$uid, $a, $m]);
    $ct = qall('SELECT * FROM {contributions} WHERE user_id = ? AND annee = ? AND mois = ? ORDER BY id', [$uid, $a, $m]);
    $resp = $user['responsable_id'] ? q1('SELECT * FROM {users} WHERE id = ?', [(int)$user['responsable_id']]) : null;
    $remb = $user['rembourseur_id'] ? q1('SELECT * FROM {users} WHERE id = ?', [(int)$user['rembourseur_id']]) : null;
    $validePar = $info['valide_resp_par'] ? q1('SELECT * FROM {users} WHERE id = ?', [(int)$info['valide_resp_par']]) : null;

    $tot = ['frais' => 0.0, 'paie' => 0.0, 'attente' => 0.0, 'rembourse' => 0.0];
    foreach ($frais as $d) {
        $tot['frais'] += $d['montant'];
        if (($d['remb_mode'] ?? 'paie') === 'immediat') {
            $tot[$d['remb_statut'] === 'fait' ? 'rembourse' : 'attente'] += $d['montant'];
        } else {
            $tot['paie'] += $d['montant'];
        }
    }
    return [
        'user' => $user, 'annee' => $a, 'mois' => $m, 'info' => $info, 'mois_info' => mois_info($a, $m),
        'frais' => $frais, 'cb_pro' => array_values(array_filter($deps, fn($d) => $d['paiement'] === 'cb_pro')),
        'totaux' => array_map(fn($v) => round($v, 2), $tot),
        'heures_sup' => $hs, 'total_heures' => round(array_sum(array_map(fn($h) => (float)$h['heures'], $hs)), 2),
        'conges' => periodes($conges), 'formations' => periodes($formations),
        'indic_tech' => $it, 'total_indic' => round(array_sum(array_map(fn($r) => (float)$r['montant_ht'], $it)), 2),
        'contributions' => $ct, 'responsable' => $resp, 'rembourseur' => $remb, 'valide_par' => $validePar,
    ];
}

/** Qui peut modifier quoi sur une fiche RH */
function droits_fiche_rh(array $employe, array $info, array $u): array
{
    $ouvert = mois_ouvert((int)$info['annee'], (int)$info['mois']);
    $estEmploye = (int)$u['id'] === (int)$employe['id'];
    // Le responsable désigné, ou le gestionnaire pour les fiches des autres :
    // personne ne valide sa propre fiche
    $estResp = !$estEmploye && (est_admin($u) || (est_associe($u) && (int)$employe['responsable_id'] === (int)$u['id']));
    $valideResp = $info['valide_resp_at'] !== null;
    $valideCollab = $info['valide_collab_at'] !== null;
    return [
        'voir'      => $estEmploye || $estResp,
        'saisir'    => $ouvert && !$valideResp && (($estEmploye && !$valideCollab) || $estResp),
        'collab'    => $ouvert && $estEmploye && !$valideCollab,
        'resp'      => $ouvert && $estResp && !$valideResp,
        'rouvrir'   => $ouvert && $estResp && ($valideResp || $valideCollab),
        'est_resp'  => $estResp,
        'est_employe' => $estEmploye,
    ];
}
