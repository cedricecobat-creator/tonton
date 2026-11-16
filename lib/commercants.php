<?php
declare(strict_types=1);

/*
 * Carnet des commerçants.
 *
 * Il s'enrichit tout seul à chaque ticket enregistré (nom tel que corrigé par la personne,
 * ville, SIRET, catégorie et nature habituelles) et sert à la saisie d'un nouveau ticket :
 *  - le SIRET lu sur le ticket est l'index le plus sûr : il charge directement le commerçant ;
 *  - sinon le nom est recherché dans le texte lu, en tolérant les erreurs de lecture ;
 *  - en tapant le nom, la liste propose les commerçants connus.
 * L'administrateur peut ajouter, corriger ou supprimer des commerçants. Une fiche modifiée
 * à la main n'est plus changée par les tickets suivants (seul le compteur avance).
 */

/** Nom réduit pour les comparaisons : majuscules, sans accents ni ponctuation */
function cle_commercant(string $nom): string
{
    $s = mb_strtoupper(trim($nom), 'UTF-8');
    $s = strtr($s, ['À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E',
        'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Í' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ó' => 'O', 'Ù' => 'U', 'Û' => 'U',
        'Ü' => 'U', 'Ú' => 'U', 'Ÿ' => 'Y', 'Ñ' => 'N', 'Œ' => 'OE', 'Æ' => 'AE']);
    $s = preg_replace('/[^A-Z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

function siret_normalise(?string $s): ?string
{
    $s = preg_replace('/\D/', '', (string)$s);
    return strlen($s) === 14 ? $s : null;
}

function commercant_get(int $id): ?array
{
    return q1('SELECT * FROM {commercants} WHERE id = ?', [$id]);
}

/** Commerçant correspondant : par SIRET d'abord, sinon même nom (et même ville, ou ville inconnue) sans autre SIRET */
function commercant_trouver(string $nom, string $ville, ?string $siret): ?array
{
    if ($siret) {
        $c = q1('SELECT * FROM {commercants} WHERE siret = ?', [$siret]);
        if ($c) {
            return $c;
        }
    }
    $cle = cle_commercant($nom);
    if ($cle === '') {
        return null;
    }
    $v = cle_commercant($ville);
    foreach (qall('SELECT * FROM {commercants} WHERE cle = ? ORDER BY nb DESC, id', [$cle]) as $c) {
        if ($siret && $c['siret'] !== null && $c['siret'] !== $siret) {
            continue; // autre établissement de la même enseigne
        }
        if ($v === '' || $c['ville'] === '' || cle_commercant($c['ville']) === $v) {
            return $c;
        }
    }
    return null;
}

/**
 * Apprend d'un ticket enregistré. $objet_saisi : nature tapée par la personne
 * (une nature remplie automatiquement n'est pas retenue comme habitude).
 */
function commercant_apprendre(array $dep, ?string $objet_saisi = null): void
{
    $nom = trim((string)$dep['lieu']);
    if ($dep['categorie'] === 'km' || cle_commercant($nom) === '') {
        return;
    }
    $ville = trim((string)$dep['ville']);
    $siret = siret_normalise($dep['siret'] ?? '');
    $objet = trim((string)$objet_saisi);
    $date = (string)$dep['date_depense'];
    $c = commercant_trouver($nom, $ville, $siret);
    if (!$c) {
        q('INSERT INTO {commercants} (nom, cle, ville, siret, categorie, objet, nb, dernier, manuel, created_at, updated_at)
           VALUES (?, ?, ?, ?, ?, ?, 1, ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [mb_substr($nom, 0, 255), cle_commercant($nom), mb_substr($ville, 0, 120), $siret, $dep['categorie'], mb_substr($objet, 0, 255), $date]);
        return;
    }
    $maj = ['nb = nb + 1', 'dernier = GREATEST(COALESCE(dernier, ?), ?)', 'updated_at = UTC_TIMESTAMP()'];
    $p = [$date, $date];
    if ($siret && $c['siret'] === null && !q1('SELECT id FROM {commercants} WHERE siret = ?', [$siret])) {
        $maj[] = 'siret = ?';
        $p[] = $siret;
    }
    if (!(int)$c['manuel']) {
        // le nom tel que la personne l'a corrigé devient la référence
        array_push($maj, 'nom = ?', 'cle = ?', 'categorie = ?');
        array_push($p, mb_substr($nom, 0, 255), cle_commercant($nom), $dep['categorie']);
        if ($ville !== '') {
            $maj[] = 'ville = ?';
            $p[] = mb_substr($ville, 0, 120);
        }
        if ($objet !== '') {
            $maj[] = 'objet = ?';
            $p[] = mb_substr($objet, 0, 255);
        }
    }
    $p[] = (int)$c['id'];
    q('UPDATE {commercants} SET ' . implode(', ', $maj) . ' WHERE id = ?', $p);
}

/** Liste transmise à la saisie d'un ticket (les plus fréquents d'abord) */
function commercants_pour_saisie(): array
{
    return array_map(fn($c) => [
        'n' => $c['nom'], 'v' => $c['ville'], 's' => $c['siret'] ?? '', 'c' => $c['categorie'] ?? '', 'o' => $c['objet'], 'k' => (int)$c['nb'],
    ], qall('SELECT nom, ville, siret, categorie, objet, nb FROM {commercants} ORDER BY nb DESC, nom LIMIT 2000'));
}

/** Premier remplissage du carnet à partir des tickets déjà enregistrés */
function commercants_initialiser(): void
{
    foreach (qall("SELECT * FROM {depenses} WHERE categorie <> 'km' AND lieu <> '' ORDER BY date_depense, id") as $d) {
        commercant_apprendre($d);
    }
}

/* ------------------------------------------------------------------ */
/* SIRET : clé de contrôle et annuaire des entreprises                */
/* ------------------------------------------------------------------ */

/**
 * Le SIRET a une clé de contrôle (algorithme de Luhn, comme les cartes bancaires) :
 * un chiffre mal lu sur le ticket est presque toujours détecté.
 * Exception : les établissements de La Poste (SIREN 356 000 000), dont la somme des chiffres est un multiple de 5.
 */
function siret_valide(string $s): bool
{
    if (!preg_match('/^\d{14}$/', $s)) {
        return false;
    }
    if (str_starts_with($s, '356000000')) {
        return array_sum(str_split($s)) % 5 === 0;
    }
    $t = 0;
    foreach (array_reverse(str_split($s)) as $i => $c) {
        $d = (int)$c * ($i % 2 ? 2 : 1);
        $t += $d > 9 ? $d - 9 : $d;
    }
    return $t % 10 === 0;
}

/** Catégorie de dépense probable d'après le code d'activité (NAF) de l'établissement */
function categorie_de_naf(?string $naf): ?string
{
    $naf = strtoupper(str_replace('.', '', (string)$naf));
    return match (true) {
        str_starts_with($naf, '56'), in_array($naf, ['1071B', '1071C', '1071D', '4724Z'], true) => 'repas',
        $naf === '4730Z' => 'carburant',
        $naf === '5221Z' => 'peage',
        default => null,
    };
}

/** SIRET cherchés sans succès (pour ne pas réinterroger l'annuaire sans cesse) */
function annuaire_cache_fichier(): string
{
    return data_dir() . '/cache/siret-inconnus.json';
}

/**
 * Interroge l'annuaire des entreprises (API publique de l'État, sans clé) pour un SIRET.
 * Seul le SIRET du commerçant est envoyé. Retourne
 *   ['ok' => true, 'commercant' => [nom, ville, siret, categorie] | null]  ou  ['ok' => false, 'erreur' => ...].
 */
function annuaire_siret(string $siret, int $delai = 5): array
{
    $url = (string)(cfg('annuaire_url') ?? 'https://recherche-entreprises.api.gouv.fr/search')
        . '?q=' . rawurlencode($siret) . '&per_page=1&page=1';
    $entetes = ['Accept: application/json', 'User-Agent: Tonton (notes de frais)'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $delai, CURLOPT_CONNECTTIMEOUT => $delai,
            CURLOPT_HTTPHEADER => $entetes, CURLOPT_FOLLOWLOCATION => false]);
        $corps = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($corps === false) {
            return ['ok' => false, 'erreur' => 'annuaire injoignable (' . $err . ')'];
        }
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => $delai, 'header' => implode("\r\n", $entetes), 'ignore_errors' => true]]);
        $corps = @file_get_contents($url, false, $ctx);
        if ($corps === false) {
            return ['ok' => false, 'erreur' => 'annuaire injoignable'];
        }
        $code = preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $mm) ? (int)$mm[1] : 0;
    }
    if ($code !== 200) {
        return ['ok' => false, 'erreur' => 'annuaire indisponible (code ' . $code . ')'];
    }
    $j = json_decode((string)$corps, true);
    if (!is_array($j) || !isset($j['results'])) {
        return ['ok' => false, 'erreur' => "réponse de l'annuaire illisible"];
    }
    foreach ($j['results'] as $ent) {
        $etabs = array_merge($ent['matching_etablissements'] ?? [], isset($ent['siege']) ? [$ent['siege']] : []);
        foreach ($etabs as $e) {
            if (($e['siret'] ?? '') !== $siret) {
                continue;
            }
            // nom : enseigne de l'établissement, sinon nom commercial, sinon nom de l'entreprise
            $nom = trim((string)(($e['liste_enseignes'][0] ?? null) ?: ($e['nom_commercial'] ?? null)
                ?: ($ent['nom_raison_sociale'] ?? null) ?: ($ent['nom_complet'] ?? '')));
            if ($nom === '') {
                continue;
            }
            return ['ok' => true, 'commercant' => [
                'nom' => mb_substr($nom, 0, 255), 'ville' => mb_substr(trim((string)($e['libelle_commune'] ?? '')), 0, 120),
                'siret' => $siret, 'categorie' => categorie_de_naf($e['activite_principale'] ?? $ent['activite_principale'] ?? null),
            ]];
        }
    }
    return ['ok' => true, 'commercant' => null];
}

function commercant_format_saisie(array $c): array
{
    return ['n' => $c['nom'], 'v' => $c['ville'], 's' => $c['siret'] ?? '', 'c' => $c['categorie'] ?? '', 'o' => $c['objet'], 'k' => (int)$c['nb']];
}

/**
 * Commerçant correspondant à un SIRET, pour la saisie : carnet d'abord, sinon annuaire des entreprises
 * (le résultat est alors ajouté au carnet). Retourne [commerçant au format saisie | null, source, erreur | null].
 */
function commercant_pour_siret(string $siret): array
{
    $c = q1('SELECT * FROM {commercants} WHERE siret = ?', [$siret]);
    if ($c) {
        return [commercant_format_saisie($c), 'carnet', null];
    }
    // SIRET déjà cherché sans succès il y a moins de 7 jours : pas de nouvel appel
    $f = annuaire_cache_fichier();
    $inconnus = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    if (isset($inconnus[$siret]) && $inconnus[$siret] > time() - 7 * 86400) {
        return [null, 'annuaire', null];
    }
    $r = annuaire_siret($siret);
    if (!$r['ok']) {
        return [null, 'annuaire', $r['erreur']];
    }
    if (!$r['commercant']) {
        $inconnus = array_filter($inconnus, fn($t) => $t > time() - 7 * 86400);
        $inconnus[$siret] = time();
        @mkdir(dirname($f), 0700, true);
        @file_put_contents($f, json_encode($inconnus), LOCK_EX);
        return [null, 'annuaire', null];
    }
    $a = $r['commercant'];
    q("INSERT INTO {commercants} (nom, cle, ville, siret, categorie, objet, nb, dernier, manuel, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, '', 0, NULL, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        [$a['nom'], cle_commercant($a['nom']), $a['ville'], $siret, $a['categorie']]);
    return [commercant_format_saisie(commercant_get((int)db()->lastInsertId())), 'annuaire', null];
}
