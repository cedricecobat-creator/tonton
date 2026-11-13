<?php
declare(strict_types=1);

const COLONNES_FICHE = [
    // clé, en-tête, largeur Excel, largeur PDF (mm)
    ['date', 'Date', 15, 22],
    ['objet', 'Objet', 44, 70],
    ['lieu', 'Lieu', 30, 50],
    ['kms', 'Kms', 7, 12],
    ['coef', 'Coef', 7, 12],
    ['total_kms', 'Total frais Kms', 11, 18],
    ['peage', 'Péage Stationnement', 14, 21],
    ['repas', 'Repas', 10, 18],
    ['carburant', 'Avance carburant', 11, 19],
    ['autres', 'Autres', 10, 18],
    ['total', 'Total', 11, 20],
];

function fiches_du_mois(int $a, int $m, ?int $uid = null): array
{
    $out = [];
    foreach (associes() as $as) {
        if ($uid === null || (int)$as['id'] === $uid) {
            $out[] = calcul_fiche($as, $a, $m);
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Excel : même présentation que le tableau mensuel actuel            */
/* ------------------------------------------------------------------ */

function export_xlsx(int $a, int $m, ?int $uid = null): string
{
    $x = new Xlsx();
    $fiches = fiches_du_mois($a, $m, $uid);
    $moisMaj = mb_strtoupper(MOIS_FR[$m]);

    foreach ($fiches as $f) {
        $s = $x->feuille(mb_strtoupper($f['user']['prenom']));
        foreach (COLONNES_FICHE as $i => $c) {
            $x->largeur($s, $i + 1, $c[2]);
        }
        $x->set($s, 1, 1, 'INFORMATIONS :', Xlsx::GRAS);
        $x->set($s, 1, 7, 'Mois :', Xlsx::GRAS);
        $x->set($s, 1, 8, $moisMaj . ' ' . $a, Xlsx::GRAS);
        $x->set($s, 2, 1, 'Nom', Xlsx::GRAS);
        $x->set($s, 2, 2, mb_strtoupper(nom_complet($f['user'])), Xlsx::GRAS);
        $x->set($s, 2, 7, 'Barème :', Xlsx::TEXTE_GRIS);
        $x->set($s, 2, 8, nombre_fr($f['coef_km'], 3) . ' €/km', Xlsx::TEXTE_GRIS);

        $l = 4;
        foreach (COLONNES_FICHE as $i => $c) {
            $x->set($s, $l, $i + 1, $c[1], Xlsx::ENTETE);
        }
        $x->hauteur($s, $l, 30);
        $premiere = $l + 1;
        foreach ($f['jours'] as $d => $j) {
            $l++;
            $we = $j['jour_semaine'] >= 6 || $j['motif'] === 'ferie';
            $txt = $we ? Xlsx::GRISE : 12;
            $x->set($s, $l, 1, Xlsx::serial($d), $we ? Xlsx::DATE_GRISE : Xlsx::DATE);
            $x->set($s, $l, 2, implode(' / ', $j['objets']), $txt);
            $x->set($s, $l, 3, implode(' / ', array_unique($j['lieux'])), $txt);
            $x->set($s, $l, 4, $j['kms'] ?: '', Xlsx::KM);
            $x->set($s, $l, 5, $j['coef'] ?? '', Xlsx::COEF);
            $x->set($s, $l, 6, $j['total_kms'] ?: '', Xlsx::EURO);
            $x->set($s, $l, 7, $j['peage'] ?: '', Xlsx::EURO);
            $x->set($s, $l, 8, $j['repas'] ?: '', Xlsx::EURO);
            $x->set($s, $l, 9, $j['carburant'] ?: '', Xlsx::EURO);
            $x->set($s, $l, 10, $j['autres'] ?: '', Xlsx::EURO);
            $x->set($s, $l, 11, ['f' => "SUM(F$l:J$l)", 'v' => $j['total']], Xlsx::EURO);
        }
        $derniere = $l;
        $l += 1;
        $x->set($s, $l, 1, 'TOTAL', 13);
        $x->set($s, $l, 2, '', 13);
        $x->set($s, $l, 3, '', 13);
        $x->set($s, $l, 4, $f['totaux']['kms'] ? ['f' => "SUM(D$premiere:D$derniere)", 'v' => $f['totaux']['kms']] : '', 13);
        $x->set($s, $l, 5, $f['coef_km'], 13);
        $map = [6 => 'total_kms', 7 => 'peage', 8 => 'repas', 9 => 'carburant', 10 => 'autres', 11 => 'total'];
        foreach ($map as $c => $k) {
            $col = Xlsx::col($c);
            $x->set($s, $l, $c, ['f' => "SUM($col$premiere:$col$derniere)", 'v' => $f['totaux'][$k]], Xlsx::EURO_GRAS);
        }
        $l += 2;
        $x->set($s, $l, 2, 'Primes repas : ' . $f['nb_primes'] . ' jour(s) × ' . euros($f['prime_montant']) . ' = ' . euros($f['total_primes']), Xlsx::NORMAL);
        $l++;
        $x->set($s, $l, 2, 'Montant à régler à ' . $f['user']['prenom'] . ' :', Xlsx::GRAS);
        $x->set($s, $l, 3, $f['totaux']['total'], Xlsx::EURO_GRAS);
    }

    if ($uid !== null) {
        return $x->contenu();
    }

    // Récapitulatif pour les virements
    $s = $x->feuille('RÉCAP VIREMENTS');
    foreach ([28, 16, 16, 16, 16, 4, 18] as $i => $w) {
        $x->largeur($s, $i + 1, $w);
    }
    $x->set($s, 1, 1, 'Récapitulatif ' . MOIS_FR[$m] . ' ' . $a, Xlsx::TITRE);
    $hd = ['Associé', 'Avances perso (CB, espèces)', 'Frais km', 'Primes repas', 'Total à régler'];
    foreach ($hd as $i => $t) {
        $x->set($s, 3, $i + 1, $t, Xlsx::ENTETE);
    }
    $x->set($s, 3, 7, 'Tickets CB entreprise (info)', Xlsx::ENTETE);
    $x->hauteur($s, 3, 30);
    $l = 3;
    foreach ($fiches as $f) {
        $l++;
        $x->set($s, $l, 1, nom_complet($f['user']), 12);
        $x->set($s, $l, 2, $f['total_avances'], Xlsx::EURO);
        $x->set($s, $l, 3, $f['totaux']['total_kms'], Xlsx::EURO);
        $x->set($s, $l, 4, $f['total_primes'], Xlsx::EURO);
        $x->set($s, $l, 5, ['f' => "SUM(B$l:D$l)", 'v' => $f['totaux']['total']], Xlsx::EURO_GRAS);
        $x->set($s, $l, 7, $f['total_cb_pro'], Xlsx::EURO);
    }
    $l++;
    $x->set($s, $l, 1, 'TOTAL', 13);
    foreach ([2, 3, 4, 5, 7] as $c) {
        $col = Xlsx::col($c);
        $v = 0.0;
        foreach ($fiches as $f) {
            $v += [2 => $f['total_avances'], 3 => $f['totaux']['total_kms'], 4 => $f['total_primes'],
                5 => $f['totaux']['total'], 7 => $f['total_cb_pro']][$c];
        }
        $x->set($s, $l, $c, ['f' => "SUM({$col}4:$col" . ($l - 1) . ')', 'v' => round($v, 2)], Xlsx::EURO_GRAS);
    }
    $l += 2;
    $info = mois_info($a, $m);
    $x->set($s, $l, 1, 'Statut : ' . STATUTS[$info['statut']] . ($info['valide_at'] ? ' le ' . date('d/m/Y', strtotime($info['valide_at'] . ' UTC')) : ''), Xlsx::TEXTE_GRIS);
    $x->set($s, $l + 1, 1, 'Les dépenses réglées avec la CB entreprise ne sont pas à rembourser ; leurs justificatifs figurent dans le PDF du mois.', Xlsx::TEXTE_GRIS);

    // Récapitulatif salariés (pour la paie)
    $frh = fiches_rh_du_mois($a, $m);
    if ($frh) {
        $s = $x->feuille('RÉCAP EMPLOYÉS (PAIE)');
        foreach ([26, 16, 16, 14, 12, 10, 12, 12, 20] as $i => $w) {
            $x->largeur($s, $i + 1, $w);
        }
        $x->set($s, 1, 1, 'Salariés ' . MOIS_FR[$m] . ' ' . $a . ' — éléments pour la paie', Xlsx::TITRE);
        $hd = ['Salarié', 'Frais à rembourser avec le salaire', 'Remboursés à part (dès que possible)', 'Heures sup', 'Congés (jours)',
            'Indic tech HT', 'Prime qualité', 'Prime contributions', 'Fiche validée'];
        foreach ($hd as $i => $t) {
            $x->set($s, 3, $i + 1, $t, Xlsx::ENTETE);
        }
        $x->hauteur($s, 3, 42);
        $l = 3;
        foreach ($frh as $f) {
            $l++;
            $i = $f['info'];
            $x->set($s, $l, 1, nom_complet($f['user']), 12);
            $x->set($s, $l, 2, $f['totaux']['paie'], Xlsx::EURO);
            $x->set($s, $l, 3, $f['totaux']['attente'] + $f['totaux']['rembourse'], Xlsx::EURO);
            $x->set($s, $l, 4, $f['total_heures'], Xlsx::KM);
            $x->set($s, $l, 5, array_sum(array_column($f['conges'], 2)), Xlsx::KM);
            $x->set($s, $l, 6, $f['total_indic'], Xlsx::EURO);
            $x->set($s, $l, 7, (int)$i['prime_qualite'] ? 'Oui' : 'Non', 12);
            $x->set($s, $l, 8, (int)$i['prime_contributions'] ? 'Oui' : 'Non', 12);
            $x->set($s, $l, 9, $i['valide_resp_at'] ? 'Oui (' . ($f['valide_par']['prenom'] ?? '') . ')' : 'Non', 12);
        }
    }

    return $x->contenu();
}

/* ------------------------------------------------------------------ */
/* PDF : récapitulatif + fiches + tous les justificatifs du mois      */
/* ------------------------------------------------------------------ */

function export_pdf(int $a, int $m, ?int $uid = null): string
{
    $pdf = new Pdf();
    $fiches = fiches_du_mois($a, $m, $uid);
    $fiches_rh = fiches_rh_du_mois($a, $m, $uid);
    $info = mois_info($a, $m);
    $titreMois = ucfirst(MOIS_FR[$m]) . ' ' . $a;
    $societe = (string)(cfg('societe') ?? '');
    $justifs = tickets_a_joindre($a, $m, $uid);
    $total_pages_justif = count($justifs);

    $statut = STATUTS[$info['statut']] . ($info['valide_at'] ? ' le ' . date('d/m/Y', strtotime($info['valide_at'] . ' UTC')) : '');

    // 1. Récapitulatif
    $pdf->page(false);
    $pdf->police(true, 16);
    $pdf->texte(15, 22, 'Notes de frais ecoBAT — ' . $titreMois);
    $pdf->police(false, 10);
    $pdf->couleur(90, 90, 90);
    $pdf->texte(15, 29, trim($societe . ' · ' . $statut, ' ·'));
    $pdf->couleur(0, 0, 0);

    $y = 42;
    $cols = [[15, 'Associé', 'L'], [105, 'Avances CB perso', 'R'], [130, 'Frais km', 'R'], [158, 'Primes repas', 'R'], [195, 'À régler', 'R']];
    $pdf->rect(13, $y - 5, 184, 7.5, [221, 229, 238]);
    $pdf->police(true, 9);
    foreach ($cols as [$x, $t, $al]) {
        $pdf->texte($x, $y, $t, $al);
    }
    $tot = [0.0, 0.0, 0.0, 0.0];
    $pdf->police(false, 10);
    foreach ($fiches as $f) {
        $y += 8;
        $vals = [$f['total_avances'], $f['totaux']['total_kms'], $f['total_primes'], $f['totaux']['total']];
        $pdf->texte(15, $y, nom_complet($f['user']));
        foreach ($vals as $i => $v) {
            $tot[$i] += $v;
            if ($i === 3) {
                $pdf->police(true, 10);
            }
            $pdf->texte($cols[$i + 1][0], $y, euros($v), 'R');
            $pdf->police(false, 10);
        }
        $pdf->police(false, 8);
        $pdf->couleur(110, 110, 110);
        $pdf->texte(15, $y + 4, $f['nb_primes'] . ' prime(s) repas × ' . euros($f['prime_montant'])
            . ' · ' . count($f['cb_pro']) . ' ticket(s) CB entreprise : ' . euros($f['total_cb_pro']) . ' (non remboursé)');
        $pdf->couleur(0, 0, 0);
        $pdf->police(false, 10);
        $y += 3;
        $pdf->trait(13, $y + 2, 197, $y + 2, 0.1, 190);
    }
    if (count($fiches) > 1) {
        $y += 9;
        $pdf->police(true, 10);
        $pdf->texte(15, $y, 'Total');
        foreach ($tot as $i => $v) {
            $pdf->texte($cols[$i + 1][0], $y, euros($v), 'R');
        }
        $pdf->trait(13, $y - 5.5, 197, $y - 5.5, 0.4, 0);
    }

    if ($fiches_rh) {
        $y += 14;
        $pdf->police(true, 11);
        $pdf->texte(15, $y, 'Salariés — éléments pour la paie');
        $y += 8;
        $ce = [[15, 'Salarié', 'L'], [100, 'Frais avec salaire', 'R'], [128, 'Heures sup', 'R'], [150, 'Congés', 'R'], [195, 'Fiche', 'R']];
        $pdf->rect(13, $y - 5, 184, 7.5, [221, 229, 238]);
        $pdf->police(true, 9);
        foreach ($ce as [$x, $t, $al]) {
            $pdf->texte($x, $y, $t, $al);
        }
        $pdf->police(false, 10);
        foreach ($fiches_rh as $f) {
            $y += 8;
            $i = $f['info'];
            $pdf->texte(15, $y, nom_complet($f['user']));
            $pdf->texte(100, $y, euros($f['totaux']['paie']), 'R');
            $pdf->texte(128, $y, nombre_fr($f['total_heures']) . ' h', 'R');
            $pdf->texte(150, $y, array_sum(array_column($f['conges'], 2)) . ' j', 'R');
            $pdf->texte(195, $y, $i['valide_resp_at'] ? 'validée' : ($i['valide_collab_at'] ? 'à valider' : 'en cours'), 'R');
            $primes = array_filter([(int)$i['prime_qualite'] ? 'prime qualité' : '', (int)$i['prime_contributions'] ? 'prime contributions' : '']);
            $pdf->police(false, 8);
            $pdf->couleur(110, 110, 110);
            $pdf->texte(15, $y + 4, ($primes ? implode(' + ', $primes) : 'aucune prime cochée')
                . ($f['totaux']['attente'] + $f['totaux']['rembourse'] > 0 ? ' · remboursé à part : ' . euros($f['totaux']['attente'] + $f['totaux']['rembourse']) : ''));
            $pdf->couleur(0, 0, 0);
            $pdf->police(false, 10);
            $y += 3;
            $pdf->trait(13, $y + 2, 197, $y + 2, 0.1, 190);
        }
    }

    $y += 16;
    $pdf->police(false, 9);
    $pdf->couleur(70, 70, 70);
    $nbPagesFiches = count($fiches) + 2 * count($fiches_rh);
    $notes = [
        'Le montant à régler comprend les avances faites avec une carte personnelle, les frais kilométriques et les primes repas.',
        'Prime repas : versée chaque jour ouvré sans ticket de repas du midi, hors jours fériés, congés et formations d\'une journée.',
        'Les dépenses réglées avec la carte de l\'entreprise ne sont pas remboursées ; leurs justificatifs sont joints pour la comptabilité.',
        'Justificatifs joints : ' . count($justifs) . ($justifs ? ' (pages ' . (2 + $nbPagesFiches) . ' à ' . (1 + $nbPagesFiches + $total_pages_justif) . ')' : '') . '.',
    ];
    foreach ($notes as $n) {
        foreach ($pdf->lignes($n, 180) as $ln) {
            $pdf->texte(15, $y, $ln);
            $y += 4.6;
        }
        $y += 1.5;
    }
    $pdf->couleur(0, 0, 0);

    // 2. Fiches mensuelles (une page paysage par associé)
    foreach ($fiches as $f) {
        pdf_fiche($pdf, $f, $titreMois);
    }
    foreach ($fiches_rh as $f) {
        pdf_fiche_rh($pdf, $f);
    }

    // 3. Justificatifs : une page par ticket (texte lisible par les logiciels comptables + photo)
    foreach ($justifs as $i => $d) {
        pdf_ticket($pdf, $d, $i + 1);
    }

    return $pdf->sortie('Notes de frais ' . $titreMois);
}

function pdf_fiche(Pdf $pdf, array $f, string $titreMois): void
{
    $pdf->page(true);
    $pdf->police(true, 12);
    $pdf->texte(10, 13, 'INFORMATIONS :');
    $pdf->texte(287, 13, 'Mois : ' . mb_strtoupper($titreMois), 'R');
    $pdf->police(false, 10);
    $pdf->texte(10, 19, 'Nom : ' . mb_strtoupper(nom_complet($f['user'])));
    $pdf->couleur(110, 110, 110);
    $pdf->police(false, 8);
    $pdf->texte(287, 19, 'Barème kilométrique : ' . nombre_fr($f['coef_km'], 3) . ' €/km', 'R');
    $pdf->couleur(0, 0, 0);

    // en-têtes
    $x = 10;
    $y = 24;
    $hEntete = 9;
    $pdf->rect(10, $y, 277, $hEntete, [221, 229, 238]);
    $pdf->police(true, 7);
    $pos = [];
    foreach (COLONNES_FICHE as [$k, $t, , $w]) {
        $pos[$k] = [$x, $w];
        $lignes = $pdf->lignes($t, $w - 2);
        $ly = $y + ($hEntete - count($lignes) * 3) / 2 + 2.4;
        foreach ($lignes as $ln) {
            $pdf->texte($x + $w / 2, $ly, $ln, 'C');
            $ly += 3;
        }
        $x += $w;
    }
    $y += $hEntete;
    $hl = min(4.6, 150 / count($f['jours']));
    $pdf->police(false, 7);
    foreach ($f['jours'] as $d => $j) {
        $gris = $j['jour_semaine'] >= 6 || $j['motif'] === 'ferie';
        if ($gris) {
            $pdf->rect(10, $y, 277, $hl, [241, 241, 241]);
        }
        $ty = $y + $hl - 1.3;
        $pdf->texte($pos['date'][0] + 1, $ty, date_fr($d, true));
        $pdf->texte($pos['objet'][0] + 1, $ty, $pdf->tronquer(implode(' / ', $j['objets']), $pos['objet'][1] - 2));
        $pdf->texte($pos['lieu'][0] + 1, $ty, $pdf->tronquer(implode(' / ', array_unique($j['lieux'])), $pos['lieu'][1] - 2));
        $num = ['kms' => $j['kms'] ? nombre_fr($j['kms'], 0) : '', 'coef' => $j['coef'] ? nombre_fr($j['coef'], 3) : ''];
        foreach (['total_kms', 'peage', 'repas', 'carburant', 'autres', 'total'] as $k) {
            $num[$k] = euros($j[$k], true);
        }
        foreach ($num as $k => $v) {
            $pdf->texte($pos[$k][0] + $pos[$k][1] - 1.2, $ty, $v, 'R');
        }
        $y += $hl;
        $pdf->trait(10, $y, 287, $y, 0.1, 205);
    }
    // traits verticaux
    foreach ($pos as [$px, $pw]) {
        $pdf->trait($px, 24, $px, $y, 0.1, 190);
    }
    $pdf->trait(287, 24, 287, $y, 0.1, 190);

    // total
    $pdf->trait(10, $y, 287, $y, 0.4, 0);
    $y += 5.2;
    $pdf->police(true, 8);
    $pdf->texte(11, $y, 'TOTAL');
    $t = $f['totaux'];
    $pdf->texte($pos['kms'][0] + $pos['kms'][1] - 1.2, $y, $t['kms'] ? nombre_fr($t['kms'], 0) : '', 'R');
    foreach (['total_kms', 'peage', 'repas', 'carburant', 'autres', 'total'] as $k) {
        $pdf->texte($pos[$k][0] + $pos[$k][1] - 1.2, $y, euros($t[$k], true), 'R');
    }
    $pdf->trait(10, $y + 2, 287, $y + 2, 0.4, 0);

    $y += 9;
    $pdf->police(false, 8.5);
    $pdf->texte(11, $y, 'Primes repas : ' . $f['nb_primes'] . ' jour(s) × ' . euros($f['prime_montant']) . ' = ' . euros($f['total_primes'])
        . '     ·     Avances CB perso : ' . euros($f['total_avances']) . '     ·     Frais km : ' . euros($t['total_kms']));
    $pdf->police(true, 11);
    $pdf->texte(287, $y + 1, 'Montant à régler : ' . euros($t['total']), 'R');
}


/** Tickets à joindre : ceux qui ont une photo, et ceux saisis sans ticket */
function tickets_a_joindre(int $a, int $m, ?int $uid = null): array
{
    return array_values(array_filter(justificatifs_mois($a, $m, $uid),
        fn($d) => chemin_justificatif($d['fichier']) || (int)$d['sans_justificatif']));
}

/**
 * Une page A4 par ticket. Le haut de page est du vrai texte (et non une image)
 * pour que Pennylane ou tout logiciel comptable en lise directement
 * le fournisseur, la date et les montants.
 */
function pdf_ticket(Pdf $pdf, array $d, int $num): void
{
    $pdf->page(false);
    $ent = entreprise();
    [$ht, $estime] = montant_ht($d);
    $sans = (int)$d['sans_justificatif'];
    $fournisseur = trim((string)$d['lieu']) ?: '—';

    // Ligne de synthèse, en premier dans le texte du PDF
    $pdf->police(true, 13);
    $pdf->texte(15, 18, $sans ? 'JUSTIFICATIF DE DÉPENSE SANS TICKET ORIGINAL' : 'JUSTIFICATIF DE DÉPENSE');
    $pdf->police(false, 8.5);
    $pdf->couleur(90, 90, 90);
    $pdf->texte(195, 18, trim($ent['nom'] . ($ent['siren'] ? ' · SIREN ' . $ent['siren_affiche'] : '')), 'R');
    $pdf->couleur(0, 0, 0);
    $pdf->police(true, 10.5);
    $pdf->texte(15, 26, $pdf->tronquer('Fournisseur : ' . $fournisseur . ' — Date : ' . date_fr($d['date_depense'])
        . ' — Total TTC : ' . nombre_fr($d['montant']) . ' EUR', 180));

    $parts = array_map(fn($p) => $p['prenom'] . ' (' . $p['initiales'] . ')', $d['participants']);
    if ((int)$d['equipe']) {
        array_unshift($parts, "toute l'équipe");
    }
    $nature = trim((string)$d['objet']);
    $champs = [
        ['Fournisseur', $fournisseur],
        ['Ville', (string)$d['ville']],
        ['SIRET fournisseur', $d['siret'] ? trim(chunk_split(substr($d['siret'], 0, 9), 3, ' ')) . ' ' . substr($d['siret'], 9) : 'non renseigné'],
        ['Date', date_fr($d['date_depense'])],
        ['Nature de la dépense', $nature],
        ['Catégorie', ((int)$d['equipe'] ? "Repas d'équipe" : CATEGORIES[$d['categorie']])
            . ($d['categorie'] === 'repas' ? ($d['repas_midi'] ? ' (midi)' : ' (hors midi)') : '')],
        ['Montant TTC', nombre_fr($d['montant']) . ' EUR'],
        ['Montant TVA', $d['montant_tva'] !== null ? nombre_fr((float)$d['montant_tva']) . ' EUR' : 'non renseigné'],
        ['Montant HT', ($estime ? 'environ ' : '') . nombre_fr($ht) . ' EUR' . ($estime ? ' (estimé, TVA ' . (int)(taux_tva_estime($d['categorie']) * 100) . ' %)' : '')],
        ['Moyen de paiement', PAIEMENTS[$d['paiement']]],
        ['Payé par', $d['payeur_prenom'] . ' ' . $d['payeur_nom']],
    ];
    if ($d['categorie'] === 'repas') {
        $champs[] = ['Participants', $parts ? implode(', ', $parts) : '—'];
        if (trim((string)$d['invites']) !== '') {
            $champs[] = ['Invités', (string)$d['invites']];
        }
    }
    $champs[] = ['Facture électronique demandée', (int)$d['facture_demandee'] ? 'Oui' : 'Non'];

    $y = 36;
    $pdf->trait(15, $y - 4, 195, $y - 4, 0.2, 200);
    foreach ($champs as [$lib, $val]) {
        $pdf->police(false, 8.5);
        $pdf->couleur(100, 100, 100);
        $pdf->texte(15, $y, $lib);
        $pdf->couleur(0, 0, 0);
        $pdf->police(in_array($lib, ['Fournisseur', 'Montant TTC'], true), 9.5);
        $pdf->texte(68, $y, $pdf->tronquer((string)$val, 127));
        $y += 5;
    }
    if (trim((string)$d['description']) !== '') {
        $pdf->police(false, 8.5);
        $pdf->couleur(100, 100, 100);
        $pdf->texte(15, $y, 'Description');
        $pdf->couleur(0, 0, 0);
        $pdf->police(false, 9.5);
        foreach (array_slice($pdf->lignes((string)$d['description'], 127), 0, 6) as $ln) {
            $pdf->texte(68, $y, $ln);
            $y += 4.6;
        }
        $y += 0.4;
    }
    if (alerte_facture($d)) {
        $y += 2;
        $pdf->rect(15, $y - 4.2, 180, 6.5, [255, 244, 224]);
        $pdf->police(true, 9);
        $pdf->couleur(154, 91, 0);
        $pdf->texte(17, $y, 'ATTENTION : montant de ' . nombre_fr(seuil_facture(), 0) . ' EUR HT ou plus sans facture électronique demandée.');
        $pdf->couleur(0, 0, 0);
        $y += 5;
    }
    if ($sans) {
        $y += 2;
        $pdf->rect(15, $y - 4.2, 180, 11, [253, 236, 234]);
        $pdf->police(true, 9);
        $pdf->couleur(138, 28, 18);
        $pdf->texte(17, $y, 'Ticket original perdu ou illisible : justificatif établi à partir de la déclaration de '
            . $d['payeur_prenom'] . ' ' . $d['payeur_nom'] . '.');
        $pdf->police(false, 8.5);
        $pdf->texte(17, $y + 4.6, 'Saisie le ' . date('d/m/Y', strtotime($d['created_at'] . ' UTC')) . '. Sans ticket original, la TVA n\'est en principe pas récupérable.');
        $pdf->couleur(0, 0, 0);
        $y += 10;
    }
    $pdf->trait(15, $y, 195, $y, 0.2, 200);

    $img = chemin_justificatif($d['fichier']);
    if ($img) {
        $pdf->image($img, 15, $y + 4, 180, 287 - $y - 12);
    }
    $pdf->police(false, 7);
    $pdf->couleur(130, 130, 130);
    $pdf->texte(105, 291, 'Justificatif n° ' . $num . ' — ticket #' . $d['id'] . ' — généré par Tonton (ecoBAT)', 'C');
    $pdf->couleur(0, 0, 0);
}

function nom_fichier(string $s): string
{
    $s = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], ' ', $s);
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    $s = $t !== false ? $t : $s;
    $s = preg_replace('/[^A-Za-z0-9 ,.\'()_+-]/', '', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** PDF d'un seul ticket */
function pdf_un_ticket(array $d, int $num = 1): string
{
    $pdf = new Pdf();
    pdf_ticket($pdf, $d, $num);
    return $pdf->sortie('Justificatif ' . date_fr($d['date_depense']) . ' ' . $d['lieu']);
}

/** PDF de la fiche mensuelle seule */
function pdf_fiche_seule(array $f): string
{
    $pdf = new Pdf();
    $titre = ucfirst(MOIS_FR[$f['mois']]) . ' ' . $f['annee'];
    pdf_fiche($pdf, $f, $titre);
    return $pdf->sortie('Fiche ' . $titre . ' ' . nom_complet($f['user']));
}

/**
 * ZIP du mois pour Pennylane : un dossier par personne avec sa fiche
 * (Excel + PDF) et un PDF par ticket. Retourne le chemin du fichier temporaire.
 */
function export_zip(int $a, int $m, ?int $uid = null): string
{
    $mm = sprintf('%02d-%02d', $m, $a % 100);
    $racine = "$mm Notes de frais";
    $tmp = tempnam(sys_get_temp_dir(), 'ndf');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    // Personnes concernées : associés avec fiche + toute personne ayant payé un ticket
    $tickets = tickets_a_joindre($a, $m, $uid);
    $personnes = [];
    foreach (fiches_du_mois($a, $m, $uid) as $f) {
        $personnes[(int)$f['user']['id']] = ['user' => $f['user'], 'fiche' => $f, 'rh' => null, 'tickets' => []];
    }
    foreach (fiches_rh_du_mois($a, $m, $uid) as $f) {
        $personnes[(int)$f['user']['id']] = ['user' => $f['user'], 'fiche' => null, 'rh' => $f, 'tickets' => []];
    }
    foreach ($tickets as $d) {
        $pid = (int)$d['user_id'];
        if (!isset($personnes[$pid])) {
            $personnes[$pid] = ['user' => q1('SELECT * FROM {users} WHERE id = ?', [$pid]), 'fiche' => null, 'rh' => null, 'tickets' => []];
        }
        $personnes[$pid]['tickets'][] = $d;
    }

    foreach ($personnes as $p) {
        $nom = nom_fichier(mb_strtoupper(nom_complet($p['user'])));
        $dossier = "$racine/$nom";
        $zip->addEmptyDir($dossier);
        if ($p['fiche']) {
            $zip->addFromString("$dossier/Fiche $mm $nom.pdf", pdf_fiche_seule($p['fiche']));
            $zip->addFromString("$dossier/Fiche $mm $nom.xlsx", export_xlsx($a, $m, (int)$p['user']['id']));
        }
        if ($p['rh']) {
            $zip->addFromString("$dossier/Fiche RH $mm $nom.pdf", pdf_fiche_rh_seule($p['rh']));
        }
        $deja = [];
        foreach ($p['tickets'] as $i => $d) {
            $base = nom_fichier($d['date_depense'] . ' ' . trim($d['lieu'] . ' ' . $d['ville']) . ' ' . nombre_fr($d['montant']) . ' EUR'
                . ([ 'cb_pro' => ' CB entreprise', 'cb_perso' => ' CB perso', 'especes' => ' Especes'][$d['paiement']] ?? ''));
            $nomf = $base;
            for ($k = 2; isset($deja[$nomf]); $k++) {
                $nomf = "$base ($k)";
            }
            $deja[$nomf] = true;
            $zip->addFromString("$dossier/Tickets/$nomf.pdf", pdf_un_ticket($d, $i + 1));
        }
    }
    if ($uid === null) {
        $zip->addFromString("$racine/Tableau $mm PAYE EQUIPE.xlsx", export_xlsx($a, $m));
    }
    $zip->close();
    return $tmp;
}

/* ------------------------------------------------------------------ */
/* Fiche mensuelle RH (salariés), au format de la fiche papier        */
/* ------------------------------------------------------------------ */

function fiches_rh_du_mois(int $a, int $m, ?int $uid = null): array
{
    $out = [];
    foreach (salaries() as $e) {
        if ($uid === null || (int)$e['id'] === $uid) {
            $out[] = calcul_fiche_rh($e, $a, $m);
        }
    }
    return $out;
}

function pdf_pied(Pdf $pdf): void
{
    $coord = (string)(cfg('coordonnees') ?? '');
    if ($coord === '') {
        return;
    }
    $pdf->police(false, 7.5);
    $pdf->couleur(80, 80, 80);
    $y = 284;
    foreach (preg_split('/\R/', $coord) as $l) {
        $pdf->texte(105, $y, $l, 'C');
        $y += 3.6;
    }
    $pdf->couleur(0, 0, 0);
}

/** Bandeau de section gris, titre à droite comme sur la fiche papier */
function pdf_bandeau(Pdf $pdf, float &$y, string $titre, string $sous = ''): void
{
    $h = $sous ? 10 : 7;
    $pdf->rect(15, $y, 180, $h, [232, 232, 232]);
    $pdf->police(true, 10);
    $pdf->texte(193, $y + 5, $titre, 'R');
    if ($sous) {
        $pdf->police(false, 7.5);
        $pdf->texte(193, $y + 8.6, $sous, 'R');
    }
    $y += $h + 1;
}

/**
 * Tableau simple : $cols = [[titre, largeur, alignement], …], $lignes = [[cellules], …]
 * Ajoute des lignes vides jusqu'à $min_lignes, et une ligne de total si fournie.
 */
function pdf_table(Pdf $pdf, float &$y, array $cols, array $lignes, int $min_lignes = 0, ?array $total = null): void
{
    $x0 = 15;
    $hl = 5.6;
    // en-tête
    $pdf->police(true, 8);
    $x = $x0;
    foreach ($cols as [$t, $w]) {
        $pdf->texte($x + $w / 2, $y + 4, $t, 'C');
        $x += $w;
    }
    $haut = $y;
    $y += 6;
    $pdf->trait($x0, $haut, 195, $haut, 0.2, 60);
    $pdf->trait($x0, $y, 195, $y, 0.2, 60);
    $pdf->police(false, 8.5);
    $n = max(count($lignes), $min_lignes);
    for ($i = 0; $i < $n; $i++) {
        $x = $x0;
        foreach ($cols as $k => [$t, $w, $al]) {
            $v = (string)($lignes[$i][$k] ?? '');
            if ($v !== '') {
                $v = $pdf->tronquer($v, $w - 2.4);
                $pdf->texte($al === 'R' ? $x + $w - 1.2 : ($al === 'C' ? $x + $w / 2 : $x + 1.2), $y + 4, $v, $al);
            }
            $x += $w;
        }
        $y += $hl;
        $pdf->trait($x0, $y, 195, $y, 0.1, 120);
    }
    // traits verticaux
    $x = $x0;
    foreach ($cols as [, $w]) {
        $pdf->trait($x, $haut, $x, $y, 0.15, 60);
        $x += $w;
    }
    $pdf->trait(195, $haut, 195, $y, 0.15, 60);
    if ($total) {
        [$lib, $val] = $total;
        $wl = 180 - $cols[count($cols) - 1][1];
        $pdf->rect($x0, $y, $wl, $hl, [232, 232, 232]);
        $pdf->police(false, 8.5);
        $pdf->texte($x0 + $wl - 1.5, $y + 4, $lib, 'R');
        $pdf->police(true, 8.5);
        $pdf->texte(193.8, $y + 4, $val, 'R');
        $y += $hl;
        $pdf->trait($x0, $y, 195, $y, 0.15, 60);
        $pdf->trait(195, $y - $hl, 195, $y, 0.15, 60);
        $pdf->trait($x0 + $wl, $y - $hl, $x0 + $wl, $y, 0.15, 60);
        $pdf->trait($x0, $y - $hl, $x0, $y, 0.15, 60);
    }
    $y += 2;
}

function pdf_fiche_rh(Pdf $pdf, array $f): void
{
    $u = $f['user'];
    $i = $f['info'];
    $ent = entreprise();
    $fmt = fn($ts) => $ts ? date('d/m/Y à H:i', strtotime($ts . ' UTC')) : '';
    $eur = fn($v) => $v === null || $v === '' ? '' : nombre_fr((float)$v) . ' €';

    // ---------- Page 1
    $pdf->page(false);
    $pdf->police(true, 16);
    $pdf->texte(15, 20, $ent['nom'] ?: 'Entreprise');
    $pdf->police(true, 14);
    $pdf->texte(195, 18, 'FICHE MENSUELLE RH', 'R');
    $pdf->police(false, 8);
    $resp = $f['responsable'] ? $f['responsable']['prenom'] : 'votre responsable';
    $pdf->texte(195, 24, 'À remplir par le collaborateur, et à remettre au plus tard', 'R');
    $pdf->texte(195, 28, 'le dernier jour du mois à ' . $resp . ' pour validation.', 'R');

    $pdf->police(false, 10);
    $pdf->texte(15, 40, 'NOM : ');
    $pdf->police(true, 10);
    $pdf->texte(28, 40, mb_strtoupper($u['nom']));
    $pdf->police(false, 10);
    $pdf->texte(15, 47, 'PRÉNOM : ');
    $pdf->police(true, 10);
    $pdf->texte(33, 47, $u['prenom']);
    $pdf->trait(110, 34, 195, 34, 0.3, 60);
    $pdf->trait(110, 51, 195, 51, 0.3, 60);
    $pdf->trait(110, 34, 110, 51, 0.3, 60);
    $pdf->trait(195, 34, 195, 51, 0.3, 60);
    $pdf->police(false, 9);
    $pdf->texte(152.5, 39, 'MOIS & ANNÉE :', 'C');
    $pdf->police(true, 11);
    $pdf->texte(152.5, 47, mb_strtoupper(MOIS_FR[$f['mois']]) . ' ' . $f['annee'], 'C');

    $y = 57;
    pdf_bandeau($pdf, $y, 'NOTE DE FRAIS', '(Remboursement collaborateur)');
    $lignes = [];
    foreach ($f['frais'] as $d) {
        $mode = ($d['remb_mode'] ?? '') === 'immediat'
            ? (($d['remb_statut'] ?? '') === 'fait' ? 'remboursé ' . date('d/m', strtotime($d['remb_le'] . ' UTC')) : 'dès que possible')
            : 'avec le salaire';
        $lignes[] = [date_fr($d['date_depense']), lieu_complet($d) . ' — ' . $d['objet'] . ' (' . PAIEMENTS[$d['paiement']] . ')', $mode, nombre_fr($d['montant']) . ' €'];
    }
    pdf_table($pdf, $y, [['DATE', 22, 'L'], ['DESCRIPTION (NOM ET TYPE DE FRAIS)', 104, 'L'], ['REMBOURSEMENT', 30, 'C'], ['MONTANT (TTC)', 24, 'R']],
        $lignes, 4, ['TOTAL', nombre_fr($f['totaux']['frais']) . ' €']);
    $y += 2;
    $pdf->police(false, 7);
    $pdf->texte(15, $y, 'À rembourser avec le salaire : ' . nombre_fr($f['totaux']['paie']) . ' €'
        . ($f['totaux']['attente'] > 0 ? ' · en attente de virement : ' . nombre_fr($f['totaux']['attente']) . ' €' : '')
        . ($f['totaux']['rembourse'] > 0 ? ' · déjà remboursé : ' . nombre_fr($f['totaux']['rembourse']) . ' €' : '')
        . ' · Seuls les frais avancés (carte perso, espèces) figurent ici ; les justificatifs sont joints.');
    $y += 7;

    pdf_bandeau($pdf, $y, 'HEURES SUPPLÉMENTAIRES');
    $lignes = array_map(fn($r) => [date_fr($r['date_hs']), $r['motif'], nombre_fr($r['heures'])], $f['heures_sup']);
    pdf_table($pdf, $y, [['DATE', 22, 'L'], ['CHANTIERS / MOTIF', 134, 'L'], ["NBRE D'HEURES", 24, 'R']],
        $lignes, 4, $f['heures_sup'] ? ['TOTAL', nombre_fr($f['total_heures']) . ' h'] : null);
    $y += 3;

    pdf_bandeau($pdf, $y, 'CONGÉS PAYÉS');
    $periodes = array_merge(array_map(fn($p) => $p + [3 => 'Congés payés'], $f['conges']), array_map(fn($p) => $p + [3 => 'Formation'], $f['formations']));
    $lignes = array_map(fn($p) => ['DU ' . date_fr($p[0]) . '  AU ' . date_fr($p[1]) . '  (inclus)', $p[3], $p[2] . ' j'], $periodes);
    pdf_table($pdf, $y, [['PÉRIODE', 116, 'L'], ['TYPE', 40, 'L'], ['JOURS', 24, 'R']], $lignes, 4);
    pdf_pied($pdf);

    // ---------- Page 2
    $pdf->page(false);
    $y = 15;
    pdf_bandeau($pdf, $y, 'INDIC TECH');
    $lignes = array_map(fn($r) => [$r['client'], $r['prestation'], $eur($r['montant_ht'])], $f['indic_tech']);
    pdf_table($pdf, $y, [['CLIENTS', 78, 'L'], ['PRESTATIONS', 78, 'L'], ['MONTANT (HT)', 24, 'R']],
        $lignes, 4, ['TOTAL', nombre_fr($f['total_indic']) . ' €']);
    $y += 3;

    pdf_bandeau($pdf, $y, 'CONTRIBUTIONS');
    $lignes = array_map(fn($r) => [$r['client'], (int)$r['panneaux'] ? 'X' : '', (int)$r['avis_google'] ? 'X' : '',
        (int)$r['autre'] ? $r['autre_texte'] : ''], $f['contributions']);
    pdf_table($pdf, $y, [['CLIENTS', 82, 'L'], ['PANNEAUX', 22, 'C'], ['AVIS GOOGLE', 24, 'C'], ['AUTRE', 52, 'L']], $lignes, 6);
    $y += 3;

    pdf_bandeau($pdf, $y, 'CHAMPS LIBRE', '(Challenge ponctuel / Autres…)');
    $pdf->police(false, 9);
    $haut = $y;
    $txt = trim((string)($i['champ_libre'] ?? ''));
    $ly = $y + 5;
    foreach (array_slice($txt !== '' ? $pdf->lignes($txt, 174) : [], 0, 7) as $ln) {
        $pdf->texte(18, $ly, $ln);
        $ly += 4.4;
    }
    $y = max($y + 24, $ly + 2);
    foreach ([[15, $haut, 195, $haut], [15, $y, 195, $y], [15, $haut, 15, $y], [195, $haut, 195, $y]] as $t) {
        $pdf->trait($t[0], $t[1], $t[2], $t[3], 0.2, 60);
    }
    $y += 5;

    // Cadres de validation
    $h = 38;
    $pdf->rect(15, $y, 88, $h, [232, 232, 232]);
    foreach ([[15, $y, 103, $y], [15, $y + $h, 195, $y + $h], [15, $y, 15, $y + $h], [103, $y, 103, $y + $h], [103, $y, 195, $y], [195, $y, 195, $y + $h]] as $t) {
        $pdf->trait($t[0], $t[1], $t[2], $t[3], 0.3, 40);
    }
    $pdf->police(true, 9.5);
    $pdf->texte(18, $y + 6, 'CADRE DIRECTION');
    $pdf->texte(106, $y + 6, 'CADRE COLLABORATEUR');
    $pdf->police(false, 9);
    $pdf->texte(18, $y + 12, ((int)$i['prime_qualite'] ? '[X]' : '[  ]') . ' PRIME QUALITÉ');
    $pdf->texte(18, $y + 17, ((int)$i['prime_contributions'] ? '[X]' : '[  ]') . ' PRIME CONTRIBUTIONS');
    $pdf->police(false, 8);
    if ($i['valide_resp_at']) {
        $pdf->texte(18, $y + 24, 'Validé par ' . nom_complet($f['valide_par'] ?? ['prenom' => '', 'nom' => '']));
        $pdf->texte(18, $y + 28, 'le ' . $fmt($i['valide_resp_at']) . ' (validation électronique)');
    } else {
        $pdf->texte(18, $y + 24, 'En attente de validation du responsable');
    }
    if (trim((string)($i['commentaire_resp'] ?? '')) !== '') {
        $pdf->texte(18, $y + 33, $pdf->tronquer('Commentaire : ' . $i['commentaire_resp'], 82));
    }
    if ($i['valide_collab_at']) {
        $pdf->police(false, 9);
        $pdf->texte(106, $y + 13, 'DATE : ' . date('d/m/Y', strtotime($i['valide_collab_at'] . ' UTC')));
        $pdf->police(false, 8);
        $pdf->texte(106, $y + 20, 'Validé par ' . nom_complet($u));
        $pdf->texte(106, $y + 24, 'le ' . $fmt($i['valide_collab_at']) . ' (validation électronique)');
    } else {
        $pdf->police(false, 8);
        $pdf->texte(106, $y + 13, 'Non encore validée par le collaborateur');
    }
    pdf_pied($pdf);
}

function pdf_fiche_rh_seule(array $f): string
{
    $pdf = new Pdf();
    pdf_fiche_rh($pdf, $f);
    return $pdf->sortie('Fiche RH ' . ucfirst(MOIS_FR[$f['mois']]) . ' ' . $f['annee'] . ' ' . nom_complet($f['user']));
}
