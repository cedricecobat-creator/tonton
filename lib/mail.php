<?php
declare(strict_types=1);

/** Adresse de l'application (pour les liens dans les e-mails) */
function base_url(): string
{
    $conf = trim((string)(cfg('url_app') ?? ''));
    if ($conf !== '') {
        return rtrim($conf, '/') . '/';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

/**
 * Envoie un e-mail texte (fonction mail() de l'hébergement OVH).
 * Ne bloque jamais l'application : un échec est seulement noté dans le journal.
 */
function envoyer_mail(string $a, string $sujet, string $corps): bool
{
    if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $exp = (string)(cfg('email_expediteur') ?? '');
    if (!filter_var($exp, FILTER_VALIDATE_EMAIL)) {
        $exp = 'noreply@' . preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }
    $nom = app_nom();
    $entetes = [
        'From: =?UTF-8?B?' . base64_encode($nom) . "?= <$exp>",
        "Reply-To: $exp",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: Tonton',
    ];
    $ok = false;
    try {
        $ok = @mail($a, '=?UTF-8?B?' . base64_encode($sujet) . '?=', $corps . "\n\n-- \n" . $nom . "\n" . base_url(),
            implode("\r\n", $entetes), '-f' . $exp);
    } catch (Throwable $e) {
        $ok = false;
    }
    journal($ok ? 'mail_envoye' : 'mail_echec', "$a : $sujet");
    return $ok;
}

/** Prévient l'associé chargé du remboursement d'une demande « dès que possible » */
function notifier_demande_remboursement(array $dep): void
{
    $emp = q1('SELECT * FROM {users} WHERE id = ?', [(int)$dep['user_id']]);
    $remb = $dep['remb_par'] ? q1('SELECT * FROM {users} WHERE id = ? AND actif = 1', [(int)$dep['remb_par']]) : null;
    if (!$emp || !$remb) {
        return;
    }
    $corps = "Bonjour {$remb['prenom']},\n\n"
        . nom_complet($emp) . " demande le remboursement d'une avance de frais dès que possible :\n\n"
        . '  Date : ' . date_fr($dep['date_depense']) . "\n"
        . '  Commerçant : ' . lieu_complet($dep) . "\n"
        . '  Nature : ' . $dep['objet'] . "\n"
        . '  Montant : ' . str_replace(["\u{202F}", "\u{00A0}"], ' ', euros($dep['montant'])) . ' (' . PAIEMENTS[$dep['paiement']] . ")\n"
        . (trim((string)$dep['description']) !== '' ? '  Description : ' . $dep['description'] . "\n" : '')
        . "\nUne fois le virement fait, indiquez-le dans l'application :\n"
        . base_url() . 'index.php?p=suivi' . "\n";
    envoyer_mail($remb['email'], 'Remboursement à faire : ' . nom_complet($emp) . ' — '
        . str_replace(["\u{202F}", "\u{00A0}"], ' ', euros($dep['montant'])), $corps);
}

function notifier_remboursement_fait(array $dep, array $par): void
{
    $emp = q1('SELECT * FROM {users} WHERE id = ?', [(int)$dep['user_id']]);
    if (!$emp) {
        return;
    }
    $corps = "Bonjour {$emp['prenom']},\n\n" . nom_complet($par) . ' a effectué le remboursement de votre avance du '
        . date_fr($dep['date_depense']) . ' (' . lieu_complet($dep) . ', '
        . str_replace(["\u{202F}", "\u{00A0}"], ' ', euros($dep['montant'])) . ').'
        . (trim((string)$dep['remb_note']) !== '' ? "\n\nNote : " . $dep['remb_note'] : '') . "\n";
    envoyer_mail($emp['email'], 'Remboursement effectué : ' . str_replace(["\u{202F}", "\u{00A0}"], ' ', euros($dep['montant'])), $corps);
}

function notifier_fiche_a_valider(array $emp, int $a, int $m): void
{
    $resp = $emp['responsable_id'] ? q1('SELECT * FROM {users} WHERE id = ? AND actif = 1', [(int)$emp['responsable_id']]) : null;
    if (!$resp) {
        return;
    }
    $corps = "Bonjour {$resp['prenom']},\n\n" . nom_complet($emp) . ' a terminé et validé sa fiche mensuelle RH de '
        . mois_libelle($a, $m) . ".\nElle attend votre validation :\n"
        . base_url() . 'index.php?' . http_build_query(['p' => 'fiche_rh', 'u' => $emp['id'], 'a' => $a, 'm' => $m]) . "\n";
    envoyer_mail($resp['email'], 'Fiche RH à valider : ' . nom_complet($emp) . ' — ' . mois_libelle($a, $m), $corps);
}
