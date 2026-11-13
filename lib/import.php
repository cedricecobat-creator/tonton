<?php
declare(strict_types=1);

/**
 * Import des congés et formations (fichier CSV envoyé chaque mois par Odoo).
 *
 * Format attendu : une ligne d'en-tête puis une ligne par absence, séparateur ; ou ,
 *   personne ; date_debut ; date_fin ; type ; duree
 * - personne : e-mail, initiales ou « Prénom Nom »
 * - date_debut / date_fin : JJ/MM/AAAA ou AAAA-MM-JJ (date_fin facultative)
 * - type : contient « congé » ou « formation »
 * - duree (facultatif) : nombre de jours ; une ligne de moins d'une journée
 *   (ou contenant « demi ») est ignorée, car une demi-journée ne supprime pas la prime.
 * Les noms de colonnes sont reconnus avec souplesse (employé, du, au, début, fin…).
 */
function importer_absences(string $contenu): array
{
    $res = ['ajoutes' => 0, 'ignores' => 0, 'erreurs' => []];

    // Encodage : Excel enregistre souvent en Windows-1252
    if (!mb_check_encoding($contenu, 'UTF-8')) {
        $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
    }
    $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu);
    $lignes = preg_split('/\r\n|\r|\n/', trim($contenu));
    if (!$lignes || trim($lignes[0]) === '') {
        $res['erreurs'][] = 'Fichier vide.';
        return $res;
    }
    $sep = substr_count($lignes[0], ';') >= substr_count($lignes[0], ',') ? ';' : ',';
    $entete = array_map(fn($c) => normaliser(trim($c)), str_getcsv(array_shift($lignes), $sep));

    // Recherche par ordre de priorité des noms : égalité exacte d'abord, puis inclusion
    $col = function (array $noms) use ($entete): ?int {
        foreach ($noms as $n) {
            $i = array_search($n, $entete, true);
            if ($i !== false) {
                return (int)$i;
            }
        }
        foreach ($noms as $n) {
            if (mb_strlen($n) < 4) {
                continue;
            }
            foreach ($entete as $i => $c) {
                if (str_contains($c, $n)) {
                    return (int)$i;
                }
            }
        }
        return null;
    };
    $iP = $col(['personne', 'employe', 'salarie', 'associe', 'email', 'nom', 'initiales']);
    $iD = $col(['date_debut', 'date debut', 'date de debut', 'debut', 'du', 'date']);
    $iF = $col(['date_fin', 'date fin', 'date de fin', 'fin', 'au']);
    $iT = $col(['type', 'motif', 'nature']);
    $iN = $col(['duree', 'nombre de jours', 'jours']);
    if ($iP === null || $iD === null) {
        $res['erreurs'][] = 'Colonnes non reconnues : il faut au moins « personne » et « date_debut ».';
        return $res;
    }
    if ($iF === $iD) {
        $iF = null;
    }

    $users = qall('SELECT * FROM {users} WHERE actif = 1');
    $trouver = function (string $v) use ($users): ?array {
        $v = normaliser($v);
        foreach ($users as $u) {
            $cands = [normaliser($u['email']), normaliser($u['initiales']),
                normaliser($u['prenom'] . ' ' . $u['nom']), normaliser($u['nom'] . ' ' . $u['prenom'])];
            if (in_array($v, $cands, true)) {
                return $u;
            }
        }
        return null;
    };

    foreach ($lignes as $n => $ligne) {
        if (trim($ligne) === '') {
            continue;
        }
        $c = str_getcsv($ligne, $sep);
        $num = $n + 2;
        $u = $trouver((string)($c[$iP] ?? ''));
        if (!$u) {
            $res['erreurs'][] = "Ligne $num : personne « " . trim((string)($c[$iP] ?? '')) . ' » inconnue.';
            continue;
        }
        $type_txt = normaliser((string)($iT !== null ? ($c[$iT] ?? '') : 'conge'));
        $type = str_contains($type_txt, 'form') ? 'formation' : 'conge';
        $duree = $iN !== null ? (float)str_replace(',', '.', (string)($c[$iN] ?? '')) : null;
        if (str_contains($type_txt, 'demi') || ($duree !== null && $duree > 0 && $duree < 1)) {
            $res['ignores']++;
            continue;
        }
        $d1 = lire_date((string)($c[$iD] ?? ''));
        $d2 = $iF !== null ? lire_date((string)($c[$iF] ?? '')) : null;
        if (!$d1) {
            $res['erreurs'][] = "Ligne $num : date illisible.";
            continue;
        }
        $d2 = $d2 && $d2 >= $d1 ? $d2 : $d1;
        if ((int)$d1->diff($d2)->days > 92) {
            $res['erreurs'][] = "Ligne $num : période trop longue, vérifiez les dates.";
            continue;
        }
        for ($d = $d1; $d <= $d2; $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            [$a, $m] = mois_de_date($ymd);
            if ((int)$d->format('N') >= 6 || isset(feries($a)[$ymd]) || !mois_ouvert($a, $m)) {
                $res['ignores']++;
                continue;
            }
            absence_set((int)$u['id'], $ymd, $type, 'import');
            $res['ajoutes']++;
        }
    }
    return $res;
}

function normaliser(string $s): string
{
    $s = mb_strtolower(trim($s, " \t\"'"));
    $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', '-' => ' ', '_' => '_']);
    return preg_replace('/\s+/', ' ', $s);
}

function lire_date(string $s): ?DateTimeImmutable
{
    $s = trim($s);
    $s = preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?.*$/', '', $s); // retire l'heure éventuelle
    foreach (['!d/m/Y', '!d/m/y', '!Y-m-d', '!d.m.Y', '!d-m-Y'] as $f) {
        $d = DateTimeImmutable::createFromFormat($f, $s);
        if ($d && $d->format(substr($f, 1)) === $s) {
            return $d;
        }
    }
    return null;
}
