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
