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
        $map = [6 => 'total_kms', 7 => 'peage', 8 => 'repas', 9 => 'carburant', 10 => 'autres', 11 => 'total_tableau'];
        foreach ($map as $c => $k) {
            $col = Xlsx::col($c);
            $x->set($s, $l, $c, ['f' => "SUM($col$premiere:$col$derniere)", 'v' => $f['totaux'][$k]], Xlsx::EURO_GRAS);
        }
        $l += 2;
        if ($f['regul']) {
            $l++;
            $x->set($s, $l, 1, 'RÉGULARISATIONS DES MOIS PRÉCÉDENTS', Xlsx::GRAS);
            foreach ($f['regul'] as $r) {
                $l++;
                if ($r['date']) {
                    $x->set($s, $l, 1, Xlsx::serial($r['date']), Xlsx::DATE);
                }
                $x->set($s, $l, 2, $r['libelle'], 12);
                if ($r['compte']) {
                    $x->set($s, $l, 11, $r['montant'], Xlsx::EURO);
                }
            }
            $l++;
            $x->set($s, $l, 2, 'Total des régularisations', Xlsx::GRAS);
            $x->set($s, $l, 11, $f['total_regul'], Xlsx::EURO_GRAS);
            $l += 2;
        }
        $x->set($s, $l, 2, 'Montant à régler à ' . $f['user']['prenom'] . ' :', Xlsx::GRAS);
        $x->set($s, $l, 3, $f['totaux']['total'], Xlsx::EURO_GRAS);
    }

    if ($uid !== null) {
        return $x->contenu();
    }

    // Récapitulatif pour les virements
    $s = $x->feuille('RÉCAP VIREMENTS');
    foreach ([28, 16, 16, 18, 16, 4, 18] as $i => $w) {
        $x->largeur($s, $i + 1, $w);
    }
    $x->set($s, 1, 1, 'Récapitulatif ' . MOIS_FR[$m] . ' ' . $a, Xlsx::TITRE);
    $hd = ['Associé', 'Avances perso (CB, espèces)', 'Frais km', 'Régularisations mois précédents', 'Total à régler'];
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
        $x->set($s, $l, 4, $f['total_regul'], Xlsx::EURO);
        $x->set($s, $l, 5, ['f' => "SUM(B$l:D$l)", 'v' => $f['totaux']['total']], Xlsx::EURO_GRAS);
        $x->set($s, $l, 7, $f['total_cb_pro'], Xlsx::EURO);
    }
    $l++;
    $x->set($s, $l, 1, 'TOTAL', 13);
    foreach ([2, 3, 4, 5, 7] as $c) {
        $col = Xlsx::col($c);
        $v = 0.0;
        foreach ($fiches as $f) {
            $v += [2 => $f['total_avances'], 3 => $f['totaux']['total_kms'], 4 => $f['total_regul'],
                5 => $f['totaux']['total'], 7 => $f['total_cb_pro']][$c];
        }
        $x->set($s, $l, $c, ['f' => "SUM({$col}4:$col" . ($l - 1) . ')', 'v' => round($v, 2)], Xlsx::EURO_GRAS);
    }
    $l += 2;
    $info = mois_info($a, $m);
    $x->set($s, $l, 1, 'Statut : ' . STATUTS[$info['statut']] . ($info['valide_at'] ? ' le ' . date('d/m/Y', strtotime($info['valide_at'] . ' UTC')) : ''), Xlsx::TEXTE_GRIS);
    $x->set($s, $l + 1, 1, 'Les dépenses réglées avec la CB entreprise ne sont pas à rembourser ; leurs justificatifs sont dans le ZIP du mois.', Xlsx::TEXTE_GRIS);

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
/* PDF de remboursement d'un associé : tableau + justificatifs        */
/* ------------------------------------------------------------------ */

/**
 * Lignes à rembourser à un associé pour un mois : ses tickets payés avec ses
 * fonds (CB perso, espèces) ou ses frais km, puis les régularisations comptées
 * (tickets tardifs, lignes libres). Les tickets CB entreprise n'y figurent pas.
 * Chaque ligne : date, fournisseur, libelle, categorie, paiement, montant, depense (ou null).
 */
function lignes_remboursement(array $f): array
{
    $a = (int)$f['annee'];
    $m = (int)$f['mois'];
    [$debut, $fin] = mois_bornes($a, $m);
    $out = [];
    foreach (depenses_periode($debut, $fin, ['mois' => [$a, $m], 'user' => (int)$f['user']['id']]) as $d) {
        if ($d['est_regul'] || $d['paiement'] === 'cb_pro') {
            continue; // tickets tardifs : repris ci-dessous via les régularisations
        }
        $out[] = ligne_remboursement_ticket($d);
    }
    foreach ($f['regul'] as $r) {
        if (!$r['compte']) {
            continue;
        }
        if ($r['type'] === 'ticket') {
            $l = ligne_remboursement_ticket($r['depense']);
            $l['libelle'] .= ' — régularisation ' . mois_libelle(...mois_de_date($r['depense']['date_depense']));
            $out[] = $l;
        } else {
            $out[] = ['date' => $r['date'], 'fournisseur' => '', 'libelle' => $r['libelle'], 'categorie' => 'Régularisation',
                'paiement' => '', 'montant' => (float)$r['montant'], 'depense' => null];
        }
    }
    return $out;
}

function ligne_remboursement_ticket(array $d): array
{
    $lib = trim((string)$d['objet']) ?: CATEGORIES[$d['categorie']];
    if ($d['categorie'] === 'km' && $d['kms']) {
        $lib .= ' — ' . nombre_fr((float)$d['kms'], 0) . ' km × ' . nombre_fr((float)$d['coef_km'], 3) . ' €/km';
    }
    return ['date' => $d['date_depense'], 'fournisseur' => lieu_complet($d), 'libelle' => $lib,
        'categorie' => (int)$d['equipe'] ? "Repas d'équipe" : CATEGORIES[$d['categorie']],
        'paiement' => PAIEMENTS[$d['paiement']] ?? '', 'montant' => (float)$d['montant'], 'depense' => $d];
}

/**
 * PDF de remboursement d'un associé : d'abord le tableau des sommes à lui
 * rembourser (sur plusieurs pages si besoin), puis une page par justificatif,
 * dans l'ordre de la colonne N° du tableau.
 */
function pdf_remboursement(array $f, ?array $lignes = null): string
{
    $pdf = new Pdf();
    $lignes ??= lignes_remboursement($f);
    $titreMois = ucfirst(MOIS_FR[(int)$f['mois']]) . ' ' . $f['annee'];
    $ent = entreprise();

    $justifs = [];
    foreach ($lignes as $i => $l) {
        $d = $l['depense'];
        if ($d && (chemin_justificatif($d['fichier']) || (int)$d['sans_justificatif'])) {
            $justifs[] = $d;
            $lignes[$i]['num'] = count($justifs);
        }
    }

    // colonnes : [titre, x, largeur, alignement]
    $cols = [['N°', 15, 8, 'C'], ['Date', 23, 19, 'L'], ['Fournisseur', 42, 46, 'L'], ['Objet', 88, 45, 'L'],
        ['Catégorie', 133, 24, 'L'], ['Paiement', 157, 18, 'L'], ['Montant', 175, 20, 'R']];
    // écrit une ligne du tableau (texte sur plusieurs lignes si besoin) et retourne sa hauteur
    $ecrire = function (array $vals, float $y) use ($pdf, $cols): float {
        $n = 1;
        foreach ($cols as $k => [, $x, $w, $al]) {
            $lns = array_slice($pdf->lignes((string)$vals[$k], $w - 2.4), 0, 4) ?: [''];
            $n = max($n, count($lns));
            foreach ($lns as $i => $ln) {
                $ln = $pdf->tronquer($ln, $w - 2.4); // mot trop long pour la colonne
                $pdf->texte($al === 'R' ? $x + $w - 1.2 : ($al === 'C' ? $x + $w / 2 : $x + 1.2), $y + $i * 3.8, $ln, $al);
            }
        }
        return 6 + ($n - 1) * 3.8;
    };
    $entete = function (bool $suite) use ($pdf, $f, $titreMois, $ent, $cols, $ecrire): float {
        $pdf->page(false);
        $pdf->police(true, 14);
        $pdf->texte(15, 20, 'Remboursement des avances — ' . $titreMois . ($suite ? ' (suite)' : ''));
        $pdf->police(false, 10);
        $pdf->texte(15, 27, 'Associé : ' . mb_strtoupper(nom_complet($f['user'])));
        $pdf->police(false, 8.5);
        $pdf->couleur(90, 90, 90);
        $pdf->texte(195, 27, trim($ent['nom'] . ($ent['siren'] ? ' · SIREN ' . $ent['siren_affiche'] : '')), 'R');
        $pdf->couleur(0, 0, 0);
        $pdf->rect(15, 36, 180, 7, [221, 229, 238]);
        $pdf->police(true, 8);
        $ecrire(array_column($cols, 0), 40.8);
        $pdf->police(false, 8.5);
        return 43;
    };

    $y = $entete(false);
    $hl = 6;
    $total = 0.0;
    if (!$lignes) {
        $pdf->texte(17, $y + 4.2, 'Aucune avance à rembourser ce mois-ci.');
        $y += $hl;
    }
    foreach ($lignes as $l) {
        if ($y + 4 * $hl > 268) {
            $y = $entete(true);
        }
        $y += $ecrire([$l['num'] ?? '', $l['date'] ? date_fr($l['date']) : '', $l['fournisseur'], $l['libelle'], $l['categorie'], $l['paiement'],
            euros($l['montant'])], $y + 4.2);
        $pdf->trait(15, $y, 195, $y, 0.1, 190);
        $total += $l['montant'];
    }
    $pdf->trait(15, $y, 195, $y, 0.4, 0);
    $y += 6;
    $pdf->police(true, 11);
    $pdf->texte(195, $y, 'Montant à rembourser : ' . euros(round($total, 2)), 'R');

    $y += 10;
    $pdf->police(false, 8.5);
    $pdf->couleur(70, 70, 70);
    $notes = [
        'Sommes avancées par l\'associé avec ses fonds personnels (CB perso, espèces) et frais kilométriques, à lui rembourser.',
        'Les dépenses réglées avec la carte de l\'entreprise ne figurent pas ici : leurs justificatifs sont fournis à part.',
        $justifs ? 'Justificatifs joints dans les pages suivantes, dans l\'ordre de la colonne N°.' : '',
    ];
    foreach (array_filter($notes) as $n) {
        foreach ($pdf->lignes($n, 180) as $ln) {
            $pdf->texte(15, $y, $ln);
            $y += 4.4;
        }
    }
    $pdf->couleur(0, 0, 0);

    foreach ($justifs as $i => $d) {
        pdf_ticket($pdf, $d, $i + 1);
    }
    return $pdf->sortie('Remboursement ' . $titreMois . ' ' . nom_complet($f['user']));
}

/** PDF d'une personne (bouton des fiches) : remboursement pour un associé, fiche RH + tickets pour un salarié */
function export_pdf(int $a, int $m, int $uid): string
{
    foreach (fiches_du_mois($a, $m, $uid) as $f) {
        return pdf_remboursement($f);
    }
    $pdf = new Pdf();
    foreach (fiches_rh_du_mois($a, $m, $uid) as $f) {
        pdf_fiche_rh($pdf, $f);
    }
    foreach (tickets_a_joindre($a, $m, $uid) as $i => $d) {
        pdf_ticket($pdf, $d, $i + 1);
    }
    return $pdf->sortie('Notes de frais ' . mois_libelle($a, $m));
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
    if (!empty($d['est_regul'])) {
        $y += 2;
        $pdf->rect(15, $y - 4.2, 180, 6.5, [241, 234, 254]);
        $pdf->police(true, 9);
        $pdf->couleur(91, 33, 182);
        $pdf->texte(17, $y, 'Ticket de ' . mois_libelle(...mois_de_date($d['date_depense'])) . ' saisi après la clôture : régularisé sur '
            . mois_libelle((int)$d['regul_annee'], (int)$d['regul_mois']) . '.');
        $pdf->couleur(0, 0, 0);
        $y += 5;
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

/**
 * ZIP du mois pour Pennylane, tous les fichiers à plat dans un seul dossier :
 *  - pour chaque associé qui a des avances à rembourser, un PDF « Remboursement »
 *    (tableau des sommes à rembourser, puis les justificatifs correspondants) ;
 *    rien n'est généré pour un associé sans avance ;
 *  - un PDF par ticket qui n'est pas dans un remboursement (CB entreprise surtout) ;
 *  - pour les salariés (phase 2), la fiche RH en PDF.
 * Retourne le chemin du fichier temporaire.
 */
function export_zip(int $a, int $m, ?int $uid = null): string
{
    $mm = sprintf('%02d-%02d', $m, $a % 100);
    $racine = "$mm Notes de frais";
    $tmp = tempnam(sys_get_temp_dir(), 'ndf');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addEmptyDir($racine);
    $deja = [];
    $ajouter = function (string $base, string $contenu) use ($zip, $racine, &$deja): void {
        $nomf = $base;
        for ($k = 2; isset($deja[$nomf]); $k++) {
            $nomf = "$base ($k)";
        }
        $deja[$nomf] = true;
        $zip->addFromString("$racine/$nomf.pdf", $contenu);
    };

    // 1. Remboursements des associés (seulement s'il y a quelque chose à rembourser)
    $dans_remboursement = [];
    foreach (fiches_du_mois($a, $m, $uid) as $f) {
        $lignes = lignes_remboursement($f);
        if (!$lignes) {
            continue;
        }
        foreach ($lignes as $l) {
            if ($l['depense']) {
                $dans_remboursement[(int)$l['depense']['id']] = true;
            }
        }
        $ajouter(nom_fichier("Remboursement $mm " . mb_strtoupper(nom_complet($f['user']))), pdf_remboursement($f, $lignes));
    }

    // 2. Fiches RH des salariés
    foreach (fiches_rh_du_mois($a, $m, $uid) as $f) {
        $ajouter(nom_fichier("Fiche RH $mm " . mb_strtoupper(nom_complet($f['user']))), pdf_fiche_rh_seule($f));
    }

    // 3. Un PDF par ticket hors remboursement (CB entreprise, tickets des salariés…)
    $n = 0;
    foreach (tickets_a_joindre($a, $m, $uid) as $d) {
        if (isset($dans_remboursement[(int)$d['id']])) {
            continue;
        }
        $base = nom_fichier($d['date_depense'] . ' ' . trim($d['lieu'] . ' ' . $d['ville']) . ' ' . nombre_fr($d['montant']) . ' EUR'
            . ([ 'cb_pro' => ' CB entreprise', 'cb_perso' => ' CB perso', 'especes' => ' Especes'][$d['paiement']] ?? '')
            . ' ' . mb_strtoupper($d['payeur_prenom'])
            . (!empty($d['est_regul']) ? ' - regularisation' : ''));
        $ajouter($base, pdf_un_ticket($d, ++$n));
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
