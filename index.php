<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

if (cfg() === null || !is_file(data_dir() . '/installed.lock')) {
    redirect('install.php');
}
date_default_timezone_set(cfg('timezone') ?? 'Europe/Paris');
// Mise à niveau automatique de la base après dépôt d'une nouvelle version
if (schema_version_installee() < SCHEMA_VERSION) {
    maj_schema();
}
security_headers();
start_session();

function render(string $view, array $vars = [], string $titre = ''): never
{
    extract($vars);
    $user = current_user();
    $flash = flash();
    ob_start();
    require APP_ROOT . '/views/' . $view . '.php';
    $contenu = ob_get_clean();
    require APP_ROOT . '/views/layout.php';
    exit;
}

/** Les pages de la phase 2 (salariés) sont fermées tant que le module est désactivé */
function require_module_salaries(): void
{
    if (!module_salaries()) {
        flash("L'espace salariés n'est pas encore ouvert.", 'attention');
        redirect(url('accueil'));
    }
}

function post_only(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Méthode non autorisée.');
    }
    csrf_check();
}

$page = preg_replace('/[^a-z_]/', '', (string)($_GET['p'] ?? 'accueil'));

switch ($page) {

/* ------------------------------------------------------------------ */
case 'login':
    if (current_user()) {
        redirect(url('accueil'));
    }
    $erreur = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $erreur = try_login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($erreur === null) {
            journal('connexion');
            redirect(url('accueil'));
        }
    }
    render('login', ['erreur' => $erreur], 'Connexion');

case 'logout':
    post_only();
    logout();
    redirect(url('login'));

/* ------------------------------------------------------------------ */
case 'accueil':
    $u = require_login();
    [$a, $m] = mois_demande();
    $fiche = est_associe($u) ? calcul_fiche($u, $a, $m) : null;
    $frh = est_salarie_fiche($u) ? calcul_fiche_rh($u, $a, $m) : null;
    $nb_remb = module_salaries() && !est_admin($u) ? count(remboursements_a_faire($u)) : 0;
    $nb_suivis = est_admin($u) ? 0 : count(employes_suivis($u));
    [$debut, $fin] = mois_bornes($a, $m);
    // L'administrateur voit tous les tickets du mois ; les autres, les leurs
    // et ceux des repas auxquels ils ont participé.
    $deps = est_admin($u) ? depenses_periode($debut, $fin) : depenses_periode($debut, $fin, ['concerne' => $u['id']]);
    $soumis = q1('SELECT soumis_at FROM {soumissions} WHERE user_id = ? AND annee = ? AND mois = ?', [$u['id'], $a, $m]);
    render('accueil', compact('a', 'm', 'fiche', 'frh', 'deps', 'soumis', 'nb_remb', 'nb_suivis'), 'Mon mois');

case 'terminer':
    post_only();
    $u = require_login();
    if (!est_associe($u) && !est_salarie_fiche($u)) {
        redirect(url('accueil'));
    }
    $a = (int)$_POST['a'];
    $m = (int)$_POST['m'];
    if (!empty($_POST['annuler'])) {
        q('DELETE FROM {soumissions} WHERE user_id = ? AND annee = ? AND mois = ?', [$u['id'], $a, $m]);
        flash('Vous pouvez reprendre la saisie du mois.');
    } else {
        q('DELETE FROM {soumissions} WHERE user_id = ? AND annee = ? AND mois = ?', [$u['id'], $a, $m]);
        q('INSERT INTO {soumissions} (user_id, annee, mois, soumis_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$u['id'], $a, $m]);
        journal('mois_termine', "$m/$a");
        flash("Merci ! L'administrateur sait que votre saisie de " . mois_libelle($a, $m) . ' est terminée.');
    }
    redirect(url('accueil', ['a' => $a, 'm' => $m]));

/* ------------------------------------------------------------------ */
case 'depense':
    $u = require_login();
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $dep = depense_get($id);
        if (!$dep || !peut_voir_depense($dep, $u)) {
            http_response_code(404);
            exit('Dépense introuvable.');
        }
        $modifiable = peut_modifier_depense($dep, $u);
    } else {
        $date = (string)($_GET['date'] ?? aujourdhui());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = aujourdhui();
        }
        $dep = ['id' => 0, 'user_id' => $u['id'], 'date_depense' => $date, 'categorie' => 'repas', 'repas_midi' => 1,
            'paiement' => 'cb_perso', 'montant' => null, 'kms' => null, 'coef_km' => $u['coef_km'], 'objet' => '',
            'lieu' => '', 'ville' => '', 'description' => '', 'siret' => '', 'montant_tva' => null,
            'facture_demandee' => 0, 'sans_justificatif' => 0, 'invites' => '', 'fichier' => null, 'equipe' => 0,
            'remb_mode' => null, 'remb_statut' => null,
            'participants' => ($mb = membre_de_user((int)$u['id'])) ? [$mb] : []];
        $modifiable = true;
    }
    $membres = membres_pour($u);
    $payeurDep = (int)$dep['user_id'] === (int)$u['id'] ? $u : (q1('SELECT * FROM {users} WHERE id = ?', [(int)$dep['user_id']]) ?? $u);
    $tous = is_admin() ? array_merge(associes(), salaries(), [$u]) : [];
    render('depense', compact('dep', 'modifiable', 'membres', 'tous', 'payeurDep'), $id ? 'Dépense' : 'Nouveau ticket');

case 'depense_save':
    post_only();
    $u = require_login();
    $id = (int)($_POST['id'] ?? 0);
    $err = function (string $msg, array $extra = []) {
        if (is_ajax()) {
            json_out(['ok' => false, 'error' => $msg] + $extra);
        }
        flash($msg, 'erreur');
        redirect($_SERVER['HTTP_REFERER'] ?? url('accueil'));
    };

    $ancien = null;
    if ($id) {
        $ancien = depense_get($id);
        if (!$ancien || !peut_modifier_depense($ancien, $u)) {
            $err('Cette dépense ne peut plus être modifiée (mois validé).');
        }
    }

    $date = (string)($_POST['date_depense'] ?? '');
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        $err('Date invalide.');
    }
    if ($date > aujourdhui()) {
        $err('La date est dans le futur.');
    }
    [$da, $dm] = mois_de_date($date);
    if (!mois_ouvert($da, $dm)) {
        $err('Le mois de ' . mois_libelle($da, $dm) . " est déjà validé : contactez l'administrateur.");
    }

    $cat = (string)($_POST['categorie'] ?? '');
    if (!isset(CATEGORIES[$cat])) {
        $err('Choisissez une catégorie.');
    }

    // Payeur : soi-même, ou au choix pour l'administrateur
    $payeur_id = (int)$u['id'];
    if (is_admin() && !empty($_POST['payeur_id'])) {
        $payeur_id = (int)$_POST['payeur_id'];
    } elseif ($ancien) {
        $payeur_id = (int)$ancien['user_id'];
    }
    $payeur = q1('SELECT * FROM {users} WHERE id = ? AND actif = 1', [$payeur_id]);
    if (!$payeur || ($payeur['role'] === 'salarie' && !module_salaries())) {
        $err('Payeur inconnu.');
    }

    $kms = null;
    $coef = null;
    if ($cat === 'km') {
        $paiement = 'perso';
        $kms = round(parse_nombre($_POST['kms'] ?? 0), 1);
        if ($kms <= 0) {
            $err('Indiquez le nombre de kilomètres.');
        }
        $coef = $ancien && $ancien['categorie'] === 'km' ? (float)$ancien['coef_km'] : (float)$payeur['coef_km'];
        $montant = round($kms * $coef, 2);
    } else {
        $paiement = (string)($_POST['paiement'] ?? '');
        if (!in_array($paiement, ['cb_perso', 'especes', 'cb_pro'], true)) {
            $err('Indiquez le moyen de paiement : CB entreprise, CB perso ou espèces.');
        }
        $montant = parse_montant($_POST['montant'] ?? 0);
        if ($montant <= 0 || $montant > 100000) {
            $err('Montant invalide.');
        }
    }

    $repas_midi = $cat === 'repas' && !empty($_POST['repas_midi']) ? 1 : 0;
    $invites = '';
    $equipe = 0;
    $parts = [];
    if ($cat === 'repas') {
        $equipe = !empty($_POST['equipe']) ? 1 : 0;
        $valides = array_map('intval', array_column(membres_pour(null), 'id'));
        if ($ancien) {
            // un membre désactivé depuis reste valable sur un ancien ticket
            $valides = array_merge($valides, array_map('intval', array_column($ancien['participants'], 'id')));
        }
        foreach ((array)($_POST['participants'] ?? []) as $pid) {
            if (in_array((int)$pid, $valides, true)) {
                $parts[] = (int)$pid;
            }
        }
        $parts = array_values(array_unique($parts));
        if (!empty($_POST['avec_invites'])) {
            $invites = mb_substr(trim((string)($_POST['invites'] ?? '')), 0, 255);
            if ($invites === '') {
                $err("Indiquez le nom de l'invité (ou décochez « Invité »).");
            }
        }
        if (!$parts && !$equipe && $invites === '') {
            $err('Indiquez qui a participé au repas.');
        }
    }

    $lieu = mb_substr(trim((string)($_POST[$cat === 'km' && isset($_POST['lieu_km']) ? 'lieu_km' : 'lieu'] ?? '')), 0, 255);
    $ville = $cat === 'km' ? '' : mb_substr(trim((string)($_POST['ville'] ?? '')), 0, 120);
    $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 2000);
    $objet = mb_substr(trim((string)($_POST['objet'] ?? '')), 0, 255);
    $siret = preg_replace('/\D/', '', (string)($_POST['siret'] ?? ''));
    if ($siret !== '' && strlen($siret) !== 14) {
        $err('Le SIRET du commerçant doit comporter 14 chiffres (ou laissez le champ vide).');
    }
    $montant_tva = trim((string)($_POST['montant_tva'] ?? '')) === '' || $cat === 'km' ? null : parse_montant($_POST['montant_tva']);
    if ($montant_tva !== null && ($montant_tva < 0 || $montant_tva >= $montant)) {
        $err('Le montant de TVA doit être inférieur au montant TTC.');
    }
    $facture_demandee = $cat !== 'km' && !empty($_POST['facture_demandee']) ? 1 : 0;
    $sans_justificatif = $cat !== 'km' && !empty($_POST['sans_justificatif']) ? 1 : 0;
    if ($cat !== 'km') {
        if ($lieu === '') {
            $err('Indiquez le nom du commerçant.');
        }
        if ($ville === '') {
            $err('Indiquez la ville.');
        }
    }
    if ($sans_justificatif && $description === '') {
        $err('Ticket sans photo : expliquez dans la description pourquoi (ticket perdu, illisible…) et ce qui a été acheté.');
    }
    if ($objet === '') {
        $ini = $parts ? array_column(qall('SELECT initiales FROM {membres} WHERE id IN (' . implode(',', $parts) . ') ORDER BY ordre, prenom'), 'initiales') : [];
        $objet = objet_auto(['categorie' => $cat, 'repas_midi' => $repas_midi, 'invites' => $invites, 'paiement' => $paiement, 'equipe' => $equipe], $ini);
    }

    // Justificatif
    $fichier = $ancien['fichier'] ?? null;
    $nouveau_fichier = null;
    $up = $_FILES['photo'] ?? null;
    if ((!$up || $up['error'] === UPLOAD_ERR_NO_FILE) && isset($_FILES['photo2'])) {
        $up = $_FILES['photo2'];
    }
    if ($up && $up['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($up['error'] !== UPLOAD_ERR_OK) {
            $err($up['error'] === UPLOAD_ERR_INI_SIZE || $up['error'] === UPLOAD_ERR_FORM_SIZE
                ? 'Photo trop lourde.' : "Échec de l'envoi de la photo, réessayez.");
        }
        try {
            $nouveau_fichier = stocker_justificatif($up['tmp_name'], $date);
        } catch (RuntimeException $e) {
            $err($e->getMessage());
        }
        // Photo d'origine, envoyée quand le ticket a été recadré sur le téléphone
        $orig = $_FILES['photo_originale'] ?? null;
        if ($orig && $orig['error'] === UPLOAD_ERR_OK) {
            enregistrer_original($orig['tmp_name'], $nouveau_fichier, $date);
        }
    }
    if ($cat !== 'km' && !$sans_justificatif && !$fichier && !$nouveau_fichier) {
        $err('Ajoutez la photo du ticket : elle est transmise au comptable. Si le ticket est perdu ou illisible, cochez « Pas de ticket ».');
    }

    // Remboursement d'un employé : avec le salaire ou dès que possible
    $remb_mode = null;
    $remb_par = null;
    $remb_statut = null;
    if (est_salarie_fiche($payeur) && in_array($paiement, ['cb_perso', 'especes'], true)) {
        $remb_mode = (string)($_POST['remb_mode'] ?? '');
        if (!isset(MODES_REMB[$remb_mode])) {
            $err('Indiquez quand vous souhaitez être remboursé : avec le salaire ou dès que possible.');
        }
        $remb_par = $payeur['rembourseur_id'] ? (int)$payeur['rembourseur_id'] : null;
        if ($remb_mode === 'immediat') {
            $remb_statut = ($ancien && ($ancien['remb_mode'] ?? '') === 'immediat') ? ($ancien['remb_statut'] ?: 'a_faire') : 'a_faire';
        }
    }

    // Alerte : 150 € HT ou plus sans facture électronique demandée
    $verif = ['categorie' => $cat, 'montant' => $montant, 'montant_tva' => $montant_tva, 'facture_demandee' => $facture_demandee];
    if (alerte_facture($verif) && empty($_POST['confirm_150'])) {
        if ($nouveau_fichier) {
            supprimer_justificatif($nouveau_fichier);
        }
        [$ht, $estime] = montant_ht($verif);
        $ent = entreprise();
        $err('Attention : ce ticket dépasse ' . euros(seuil_facture()) . ' HT (' . ($estime ? 'environ ' : '') . euros($ht) . ' HT). '
            . "Une facture au nom de l'entreprise est obligatoire : demandez-la au commerçant"
            . ($ent['siren'] ? ' (SIREN ' . $ent['siren_affiche'] . ')' : '') . ' et cochez « Facture électronique demandée ». '
            . 'Enregistrer quand même sans facture ?', ['confirm150' => true]);
    }

    // Doublon probable
    if (empty($_POST['confirm_doublon'])) {
        $doublon = q1('SELECT id FROM {depenses} WHERE user_id = ? AND date_depense = ? AND montant = ? AND id <> ?',
            [$payeur_id, $date, $montant, $id]);
        if ($doublon) {
            if ($nouveau_fichier) {
                supprimer_justificatif($nouveau_fichier);
            }
            $err('Un ticket du même montant existe déjà à cette date. Enregistrer quand même ?', ['doublon' => true]);
        }
    }

    $vals = [$payeur_id, $date, $cat, $repas_midi, $paiement, $montant, $kms, $coef, $objet, $lieu, $invites, $equipe,
        $ville, $description, $siret, $montant_tva, $facture_demandee, $sans_justificatif, $remb_mode, $remb_par, $remb_statut];
    if ($id) {
        $f = $nouveau_fichier ?? $fichier;
        q('UPDATE {depenses} SET user_id = ?, date_depense = ?, categorie = ?, repas_midi = ?, paiement = ?, montant = ?,
             kms = ?, coef_km = ?, objet = ?, lieu = ?, invites = ?, equipe = ?, ville = ?, description = ?, siret = ?,
             montant_tva = ?, facture_demandee = ?, sans_justificatif = ?, remb_mode = ?, remb_par = ?, remb_statut = ?,
             fichier = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            array_merge($vals, [$f, $id]));
        if ($nouveau_fichier && $fichier) {
            supprimer_justificatif($fichier);
        }
        journal('depense_modif', "#$id $date $montant");
    } else {
        q('INSERT INTO {depenses} (user_id, date_depense, categorie, repas_midi, paiement, montant, kms, coef_km, objet, lieu,
             invites, equipe, ville, description, siret, montant_tva, facture_demandee, sans_justificatif,
             remb_mode, remb_par, remb_statut, fichier, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            array_merge($vals, [$nouveau_fichier]));
        $id = (int)db()->lastInsertId();
        journal('depense_ajout', "#$id $date $montant");
    }
    q('DELETE FROM {convives} WHERE depense_id = ?', [$id]);
    foreach ($parts as $pid) {
        q('INSERT INTO {convives} (depense_id, membre_id) VALUES (?, ?)', [$id, $pid]);
    }

    // Demande de remboursement immédiat : l'associé concerné est prévenu par e-mail
    $nouvelle_demande = $remb_mode === 'immediat' && $remb_statut === 'a_faire'
        && (!$ancien || ($ancien['remb_mode'] ?? '') !== 'immediat');
    if ($nouvelle_demande) {
        if ($remb_par) {
            notifier_demande_remboursement(depense_get($id));
            flash('Demande de remboursement envoyée à ' . (q1('SELECT prenom FROM {users} WHERE id = ?', [$remb_par])['prenom'] ?? 'l\'associé') . '.');
        } else {
            flash("Aucun associé n'est désigné pour vos remboursements : prévenez l'administrateur.", 'attention');
        }
    }
    flash('Ticket enregistré : ' . euros($montant) . ' le ' . date_fr($date) . '.');
    $dest = url('accueil', ['a' => $da, 'm' => $dm]);
    if (is_ajax()) {
        json_out(['ok' => true, 'redirect' => $dest]);
    }
    redirect($dest);

case 'depense_suppr':
    post_only();
    $u = require_login();
    $dep = depense_get((int)($_POST['id'] ?? 0));
    if (!$dep || !peut_modifier_depense($dep, $u)) {
        flash('Suppression impossible.', 'erreur');
        redirect(url('accueil'));
    }
    q('DELETE FROM {depenses} WHERE id = ?', [$dep['id']]);
    supprimer_justificatif($dep['fichier']);
    journal('depense_suppr', "#{$dep['id']} {$dep['date_depense']} {$dep['montant']}");
    [$da, $dm] = mois_de_date($dep['date_depense']);
    flash('Ticket supprimé.');
    redirect(url('accueil', ['a' => $da, 'm' => $dm]));

case 'fichier':
    $u = require_login();
    $dep = depense_get((int)($_GET['id'] ?? 0));
    if (!$dep || !peut_voir_depense($dep, $u)) {
        http_response_code(404);
        exit;
    }
    $path = !empty($_GET['mini']) ? chemin_miniature($dep['fichier'])
        : (!empty($_GET['orig']) ? chemin_original($dep['fichier']) : chemin_justificatif($dep['fichier']));
    if (!$path) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;

/* ------------------------------------------------------------------ */
case 'fiche':
    $u = require_login();
    [$a, $m] = mois_demande();
    $uid = (int)($_GET['u'] ?? (is_admin() ? (associes()[0]['id'] ?? $u['id']) : $u['id']));
    if ($uid !== (int)$u['id'] && !is_admin()) {
        $uid = (int)$u['id'];
    }
    $cible = q1('SELECT * FROM {users} WHERE id = ?', [$uid]);
    if ($cible && est_salarie_fiche($cible) && !est_associe($cible)) {
        redirect(url('fiche_rh', ['u' => $cible['id'], 'a' => $a, 'm' => $m]));
    }
    if (!$cible || !est_associe($cible)) {
        flash("Pas de fiche de frais pour ce compte.", 'erreur');
        redirect(url(is_admin() ? 'gestion' : 'accueil', ['a' => $a, 'm' => $m]));
    }
    $fiche = calcul_fiche($cible, $a, $m);
    $periodes = absences_periodes((int)$cible['id'], $a, $m);
    render('fiche', compact('a', 'm', 'fiche', 'periodes'), 'Fiche ' . mois_libelle($a, $m));

/* ------------------------------------------------------------------ */
case 'fiche_rh':
    $u = require_login();
    require_module_salaries();
    [$a, $m] = mois_demande();
    $uid = (int)($_GET['u'] ?? $u['id']);
    $emp = q1('SELECT * FROM {users} WHERE id = ?', [$uid]);
    if (!$emp || !est_salarie_fiche($emp)) {
        flash("Pas de fiche RH pour ce compte.", 'erreur');
        redirect(url('accueil'));
    }
    $frh = calcul_fiche_rh($emp, $a, $m);
    $droits = droits_fiche_rh($emp, $frh['info'], $u);
    if (!$droits['voir']) {
        http_response_code(403);
        exit('Accès réservé au collaborateur et à son responsable.');
    }
    $suivis = $droits['est_resp'] ? employes_suivis($u) : [];
    render('fiche_rh', compact('a', 'm', 'frh', 'droits', 'suivis'), 'Fiche RH ' . mois_libelle($a, $m));

case 'fiche_rh_action':
    post_only();
    $u = require_login();
    require_module_salaries();
    $a = (int)($_POST['a'] ?? 0);
    $m = (int)($_POST['m'] ?? 0);
    $uid = (int)($_POST['u'] ?? 0);
    $emp = q1('SELECT * FROM {users} WHERE id = ?', [$uid]);
    if (!$emp || !est_salarie_fiche($emp) || $m < 1 || $m > 12) {
        redirect(url('accueil'));
    }
    $info = fiche_rh_info($uid, $a, $m);
    $dr = droits_fiche_rh($emp, $info, $u);
    $retour = url('fiche_rh', ['u' => $uid, 'a' => $a, 'm' => $m]);
    $action = (string)($_POST['action'] ?? '');
    $besoin = in_array($action, ['collab_valider'], true) ? 'collab'
        : (in_array($action, ['resp_valider'], true) ? 'resp' : ($action === 'rouvrir' ? 'rouvrir' : 'saisir'));
    if (empty($dr[$besoin])) {
        flash('Action impossible : la fiche est validée ou le mois est clos.', 'erreur');
        redirect($retour);
    }
    [$debut, $fin] = mois_bornes($a, $m);
    $txt = fn(string $k, int $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $dateMois = function (string $k) use ($debut, $fin): ?string {
        $d = (string)($_POST[$k] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d >= $debut && $d <= $fin ? $d : null;
    };
    switch ($action) {
        case 'hs_add':
            $d = $dateMois('date');
            $hrs = round(parse_nombre($_POST['heures'] ?? 0), 2);
            if (!$d || $hrs <= 0 || $hrs > 24) {
                flash('Indiquez une date du mois et un nombre d\'heures (ex. : 1,5).', 'erreur');
            } else {
                q('INSERT INTO {heures_sup} (user_id, date_hs, motif, heures) VALUES (?, ?, ?, ?)', [$uid, $d, $txt('motif'), $hrs]);
                flash('Heures supplémentaires ajoutées.');
            }
            break;
        case 'hs_del':
            q('DELETE FROM {heures_sup} WHERE id = ? AND user_id = ?', [(int)$_POST['id'], $uid]);
            break;
        case 'conge_add':
            $d1 = $dateMois('du');
            $d2 = (string)($_POST['au'] ?? '') !== '' ? $dateMois('au') : $d1;
            $type = ($_POST['type'] ?? '') === 'formation' ? 'formation' : 'conge';
            if (!$d1 || !$d2 || $d2 < $d1) {
                flash('Indiquez une période comprise dans le mois (du … au …).', 'erreur');
            } else {
                $n = 0;
                for ($d = new DateTimeImmutable($d1); $d->format('Y-m-d') <= $d2; $d = $d->modify('+1 day')) {
                    $ymd = $d->format('Y-m-d');
                    if ((int)$d->format('N') <= 5 && !isset(feries($a)[$ymd])) {
                        absence_set($uid, $ymd, $type, 'employe');
                        $n++;
                    }
                }
                flash($n . ' jour(s) ouvré(s) de ' . ($type === 'conge' ? 'congés payés' : 'formation') . ' enregistré(s).');
            }
            break;
        case 'conge_del':
            $d1 = $dateMois('du');
            $d2 = $dateMois('au');
            if ($d1 && $d2) {
                q('DELETE FROM {absences} WHERE user_id = ? AND date_absence BETWEEN ? AND ? AND type = ?',
                    [$uid, $d1, $d2, ($_POST['type'] ?? '') === 'formation' ? 'formation' : 'conge']);
            }
            break;
        case 'it_add':
            if ($txt('client') === '') {
                flash('Indiquez le client.', 'erreur');
            } else {
                $mt = trim((string)($_POST['montant_ht'] ?? '')) === '' ? null : parse_montant($_POST['montant_ht']);
                q('INSERT INTO {indic_tech} (user_id, annee, mois, client, prestation, montant_ht) VALUES (?, ?, ?, ?, ?, ?)',
                    [$uid, $a, $m, $txt('client'), $txt('prestation'), $mt]);
                flash('Indication technique ajoutée.');
            }
            break;
        case 'it_del':
            q('DELETE FROM {indic_tech} WHERE id = ? AND user_id = ?', [(int)$_POST['id'], $uid]);
            break;
        case 'ct_add':
            $autre = !empty($_POST['autre']) ? 1 : 0;
            if ($txt('client') === '') {
                flash('Indiquez le client.', 'erreur');
            } elseif (empty($_POST['panneaux']) && empty($_POST['avis_google']) && !$autre) {
                flash('Cochez au moins une contribution : panneaux, avis Google ou autre.', 'erreur');
            } elseif ($autre && $txt('autre_texte') === '') {
                flash('Précisez la contribution « Autre ».', 'erreur');
            } else {
                q('INSERT INTO {contributions} (user_id, annee, mois, client, panneaux, avis_google, autre, autre_texte) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$uid, $a, $m, $txt('client'), !empty($_POST['panneaux']) ? 1 : 0, !empty($_POST['avis_google']) ? 1 : 0, $autre,
                        $autre ? $txt('autre_texte') : '']);
                flash('Contribution ajoutée.');
            }
            break;
        case 'ct_del':
            q('DELETE FROM {contributions} WHERE id = ? AND user_id = ?', [(int)$_POST['id'], $uid]);
            break;
        case 'libre_save':
            fiche_rh_maj($uid, $a, $m, ['champ_libre' => $txt('champ_libre', 3000)]);
            flash('Champ libre enregistré.');
            break;
        case 'collab_valider':
            fiche_rh_maj($uid, $a, $m, ['valide_collab_at' => gmdate('Y-m-d H:i:s')]);
            journal('fiche_rh_collab', "$uid $m/$a");
            notifier_fiche_a_valider($emp, $a, $m);
            flash('Fiche validée et transmise à votre responsable.');
            break;
        case 'resp_valider':
            fiche_rh_maj($uid, $a, $m, [
                'prime_qualite' => !empty($_POST['prime_qualite']) ? 1 : 0,
                'prime_contributions' => !empty($_POST['prime_contributions']) ? 1 : 0,
                'commentaire_resp' => $txt('commentaire_resp', 2000),
                'valide_resp_at' => gmdate('Y-m-d H:i:s'),
                'valide_resp_par' => (int)$u['id'],
            ]);
            journal('fiche_rh_resp', "$uid $m/$a");
            flash('Fiche RH de ' . $emp['prenom'] . ' validée.');
            break;
        case 'rouvrir':
            fiche_rh_maj($uid, $a, $m, ['valide_collab_at' => null, 'valide_resp_at' => null, 'valide_resp_par' => null]);
            journal('fiche_rh_rouvrir', "$uid $m/$a");
            flash('Fiche rouverte : le collaborateur peut de nouveau la compléter.');
            break;
    }
    redirect($retour);

case 'suivi':
    $u = require_login();
    require_module_salaries();
    if (!est_admin($u) && !est_associe($u)) {
        redirect(url('accueil'));
    }
    [$a, $m] = mois_demande();
    $remb = remboursements_a_faire($u);
    $suivis = [];
    foreach (employes_suivis($u) as $e) {
        $suivis[] = calcul_fiche_rh($e, $a, $m);
    }
    $faits = qall("SELECT d.*, e.prenom AS payeur_prenom, e.nom AS payeur_nom FROM {depenses} d JOIN {users} e ON e.id = d.user_id
                   WHERE d.remb_mode = 'immediat' AND d.remb_statut = 'fait'" . (is_admin() ? '' : ' AND d.remb_par = ' . (int)$u['id'])
        . ' ORDER BY d.remb_le DESC LIMIT 15');
    render('suivi', compact('a', 'm', 'remb', 'suivis', 'faits'), 'Suivi des salariés');

case 'remb_fait':
    post_only();
    $u = require_login();
    require_module_salaries();
    $dep = depense_get((int)($_POST['id'] ?? 0));
    if (!$dep || $dep['remb_mode'] !== 'immediat' || (!est_admin($u) && (int)$dep['remb_par'] !== (int)$u['id'])) {
        flash('Remboursement introuvable.', 'erreur');
        redirect(url('suivi'));
    }
    if (!empty($_POST['annuler'])) {
        q("UPDATE {depenses} SET remb_statut = 'a_faire', remb_le = NULL WHERE id = ?", [$dep['id']]);
        flash('Remboursement remis « à faire ».');
    } else {
        q("UPDATE {depenses} SET remb_statut = 'fait', remb_le = UTC_TIMESTAMP(), remb_note = ? WHERE id = ?",
            [mb_substr(trim((string)($_POST['remb_note'] ?? '')), 0, 255), $dep['id']]);
        journal('remb_fait', "#{$dep['id']} {$dep['montant']}");
        notifier_remboursement_fait(depense_get((int)$dep['id']), $u);
        flash('Remboursement de ' . euros($dep['montant']) . ' à ' . $dep['payeur_prenom'] . ' noté comme effectué.');
    }
    redirect(url('suivi'));

/* ------------------------------------------------------------------ */
case 'gestion':
    require_admin();
    [$a, $m] = mois_demande();
    $info = mois_info($a, $m);
    $lignes = [];
    foreach (associes() as $as) {
        $f = calcul_fiche($as, $a, $m);
        [$debut, $fin] = mois_bornes($a, $m);
        $f['nb_tickets'] = (int)q1('SELECT COUNT(*) n FROM {depenses} WHERE user_id = ? AND date_depense BETWEEN ? AND ?',
            [$as['id'], $debut, $fin])['n'];
        $f['soumis'] = q1('SELECT soumis_at FROM {soumissions} WHERE user_id = ? AND annee = ? AND mois = ?', [$as['id'], $a, $m]);
        $lignes[] = $f;
    }
    [$debut, $fin] = mois_bornes($a, $m);
    $nb_justifs = (int)q1('SELECT COUNT(*) n FROM {depenses} WHERE fichier IS NOT NULL AND date_depense BETWEEN ? AND ?', [$debut, $fin])['n'];
    $total_cbpro = (float)q1("SELECT COALESCE(SUM(montant),0) s FROM {depenses} WHERE paiement = 'cb_pro' AND date_depense BETWEEN ? AND ?", [$debut, $fin])['s'];
    $tous_tickets = depenses_periode($debut, $fin);
    $alertes = array_values(array_filter($tous_tickets, 'alerte_facture'));
    $sans_ticket = array_values(array_filter($tous_tickets, fn($d) => (int)$d['sans_justificatif']));
    $factures = array_values(array_filter($tous_tickets, fn($d) => (int)$d['facture_demandee']));
    $fiches_rh = array_map(fn($e) => calcul_fiche_rh($e, $a, $m), salaries());
    $remb_attente = remboursements_a_faire(null);
    render('gestion', compact('a', 'm', 'info', 'lignes', 'nb_justifs', 'total_cbpro', 'alertes', 'sans_ticket', 'factures',
        'fiches_rh', 'remb_attente'), 'Gestion ' . mois_libelle($a, $m));

case 'gestion_action':
    post_only();
    require_admin();
    $a = (int)$_POST['a'];
    $m = (int)$_POST['m'];
    $info = mois_info($a, $m);
    $action = (string)($_POST['action'] ?? '');
    $transitions = [
        'valider'     => ['ouvert', 'valide', 'Mois validé : les saisies sont verrouillées.'],
        'rouvrir'     => ['*', 'ouvert', 'Mois rouvert : les associés peuvent de nouveau modifier leurs tickets.'],
        'payer'       => ['valide', 'paye', 'Mois marqué comme payé.'],
        'transmettre' => ['paye', 'transmis', 'Mois marqué comme transmis au comptable.'],
    ];
    if ($action === 'prime') {
        if ($info['statut'] !== 'ouvert') {
            flash('Le montant de la prime ne peut être changé que sur un mois en cours.', 'erreur');
        } else {
            $p = parse_montant($_POST['prime_montant'] ?? '');
            if ($p < 0 || $p > 100) {
                flash('Montant de prime invalide.', 'erreur');
            } else {
                if (!$info['existe']) {
                    q('INSERT INTO {mois} (annee, mois, statut, prime_montant) VALUES (?, ?, ?, ?)', [$a, $m, 'ouvert', $p]);
                } else {
                    q('UPDATE {mois} SET prime_montant = ? WHERE annee = ? AND mois = ?', [$p, $a, $m]);
                }
                journal('prime_montant', "$m/$a $p");
                flash('Prime repas du mois fixée à ' . euros($p) . '.');
            }
        }
    } elseif (isset($transitions[$action])) {
        [$de, $vers, $msg] = $transitions[$action];
        if ($de !== '*' && $info['statut'] !== $de) {
            flash("Action impossible depuis l'état « " . STATUTS[$info['statut']] . ' ».', 'erreur');
        } else {
            mois_set_statut($a, $m, $vers);
            journal('mois_' . $action, "$m/$a");
            flash($msg);
        }
    }
    redirect(url('gestion', ['a' => $a, 'm' => $m]));

/* ------------------------------------------------------------------ */
case 'absences':
    require_admin();
    [$a, $m] = mois_demande();
    [$debut, $fin, $nb] = mois_bornes($a, $m);
    $abs = absences_periode($debut, $fin);
    $associes = array_merge(associes(), salaries());
    $info = mois_info($a, $m);
    render('absences', compact('a', 'm', 'nb', 'abs', 'associes', 'info'), 'Congés et formations');

case 'absence_set':
    post_only();
    require_admin();
    $uid = (int)($_POST['user_id'] ?? 0);
    $date = (string)($_POST['date'] ?? '');
    $type = (string)($_POST['type'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !q1('SELECT id FROM {users} WHERE id = ?', [$uid])) {
        json_out(['ok' => false, 'error' => 'Paramètres invalides.'], 400);
    }
    [$da, $dm] = mois_de_date($date);
    if (!mois_ouvert($da, $dm)) {
        json_out(['ok' => false, 'error' => 'Mois validé : rouvrez-le pour modifier les absences.'], 400);
    }
    absence_set($uid, $date, $type ?: null);
    journal('absence', "$uid $date " . ($type ?: 'aucune'));
    json_out(['ok' => true]);

case 'absences_import':
    post_only();
    require_admin();
    require APP_ROOT . '/lib/import.php';
    $a = (int)$_POST['a'];
    $m = (int)$_POST['m'];
    $up = $_FILES['fichier'] ?? null;
    if (!$up || $up['error'] !== UPLOAD_ERR_OK) {
        flash('Choisissez le fichier reçu par e-mail.', 'erreur');
        redirect(url('absences', ['a' => $a, 'm' => $m]));
    }
    $res = importer_absences((string)file_get_contents($up['tmp_name']));
    $msg = $res['ajoutes'] . ' jour(s) importé(s).';
    if ($res['ignores']) {
        $msg .= ' ' . $res['ignores'] . ' ligne(s) ignorée(s) (demi-journées, week-ends, fériés ou mois validé).';
    }
    flash($msg, $res['erreurs'] ? 'attention' : 'ok');
    foreach (array_slice($res['erreurs'], 0, 8) as $e) {
        flash($e, 'erreur');
    }
    journal('absences_import', $msg);
    redirect(url('absences', ['a' => $a, 'm' => $m]));

/* ------------------------------------------------------------------ */
case 'export_xlsx':
    require_admin();
    require APP_ROOT . '/lib/xlsx.php';
    require APP_ROOT . '/lib/pdf.php';
    require APP_ROOT . '/lib/exports.php';
    [$a, $m] = mois_demande();
    $bin = export_xlsx($a, $m);
    $nom = sprintf('%02d-%02d PAYE EQUIPE.xlsx', $m, $a % 100);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;

case 'export_pdf':
    require_admin();
    require APP_ROOT . '/lib/xlsx.php';
    require APP_ROOT . '/lib/pdf.php';
    require APP_ROOT . '/lib/exports.php';
    [$a, $m] = mois_demande();
    $uid = !empty($_GET['u']) ? (int)$_GET['u'] : null;
    @set_time_limit(120);
    $pdf = export_pdf($a, $m, $uid);
    $suffixe = $uid ? ' ' . mb_strtoupper(q1('SELECT prenom FROM {users} WHERE id = ?', [$uid])['prenom'] ?? '') : '';
    $nom = sprintf('%02d-%02d Notes de frais%s.pdf', $m, $a % 100, $suffixe);
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (!empty($_GET['voir']) ? 'inline' : 'attachment') . '; filename="' . $nom . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;

case 'export_zip':
    require_admin();
    require APP_ROOT . '/lib/xlsx.php';
    require APP_ROOT . '/lib/pdf.php';
    require APP_ROOT . '/lib/exports.php';
    [$a, $m] = mois_demande();
    $uid = !empty($_GET['u']) ? (int)$_GET['u'] : null;
    @set_time_limit(300);
    $tmp = export_zip($a, $m, $uid);
    $suffixe = $uid ? ' ' . nom_fichier(mb_strtoupper(nom_complet(q1('SELECT * FROM {users} WHERE id = ?', [$uid]) ?? ['prenom' => '', 'nom' => '']))) : '';
    $nom = sprintf('%02d-%02d Notes de frais%s.zip', $m, $a % 100, $suffixe);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;

/* ------------------------------------------------------------------ */
case 'utilisateurs':
    require_admin();
    $users = qall('SELECT * FROM {users} ORDER BY actif DESC, prenom, nom');
    $membres = membres_pour(null, false);
    render('utilisateurs', compact('users', 'membres'), 'Utilisateurs');

case 'utilisateur':
    require_admin();
    $id = (int)($_GET['id'] ?? 0);
    $cible = $id ? q1('SELECT * FROM {users} WHERE id = ?', [$id]) : null;
    if ($id && !$cible) {
        redirect(url('utilisateurs'));
    }
    $cible ??= ['id' => 0, 'prenom' => '', 'nom' => '', 'initiales' => '', 'email' => '', 'role' => 'associe',
        'admin_fiche' => 0, 'coef_km' => cfg('coef_km_defaut') ?? 0.382, 'actif' => 1,
        'responsable_id' => null, 'rembourseur_id' => null];
    render('utilisateur', compact('cible'), $id ? nom_complet($cible) : 'Nouvel utilisateur');

case 'utilisateur_save':
    post_only();
    $moi = require_admin();
    $id = (int)($_POST['id'] ?? 0);
    $roles_permis = module_salaries() ? ['admin', 'associe', 'salarie'] : ['admin', 'associe'];
    $ancien = $id ? q1('SELECT * FROM {users} WHERE id = ?', [$id]) : null;
    if ($id && !$ancien) {
        redirect(url('utilisateurs'));
    }
    $role = (string)($_POST['role'] ?? '');
    if (!in_array($role, $roles_permis, true)) {
        // un salarié existant garde son rôle même si le module est désactivé
        $role = ($ancien && $ancien['role'] === $role && isset(ROLES[$role])) ? $role : '';
    }
    $d = [
        'prenom'      => trim((string)($_POST['prenom'] ?? '')),
        'nom'         => trim((string)($_POST['nom'] ?? '')),
        'initiales'   => mb_strtoupper(trim((string)($_POST['initiales'] ?? ''))),
        'email'       => mb_strtolower(trim((string)($_POST['email'] ?? ''))),
        'role'        => $role,
        'admin_fiche' => $role === 'admin' && module_salaries() && !empty($_POST['admin_fiche']) ? 1 : 0,
        'coef_km'     => round(parse_nombre($_POST['coef_km'] ?? 0), 3),
        'actif'       => !empty($_POST['actif']) ? 1 : 0,
        'responsable_id' => null,
        'rembourseur_id' => null,
    ];
    if ($role === 'salarie' || $d['admin_fiche']) {
        $ids_assoc = array_map('intval', array_column(associes(), 'id'));
        foreach (['responsable_id', 'rembourseur_id'] as $k) {
            $v = (int)($_POST[$k] ?? 0);
            $d[$k] = in_array($v, $ids_assoc, true) ? $v : null;
        }
    } elseif ($ancien) {
        $d['responsable_id'] = $ancien['responsable_id'] !== null ? (int)$ancien['responsable_id'] : null;
        $d['rembourseur_id'] = $ancien['rembourseur_id'] !== null ? (int)$ancien['rembourseur_id'] : null;
    }
    $pw = (string)($_POST['password'] ?? '');
    $retour = url('utilisateur', $id ? ['id' => $id] : []);
    if ($d['role'] === '') {
        flash('Choisissez le rôle de la personne.', 'erreur');
        redirect($retour);
    }
    if ($d['prenom'] === '' || $d['nom'] === '' || $d['initiales'] === '' || mb_strlen($d['initiales']) > 6
        || !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        flash('Renseignez prénom, nom, initiales (6 caractères maximum) et une adresse e-mail valide.', 'erreur');
        redirect($retour);
    }
    if ($d['coef_km'] < 0 || $d['coef_km'] > 5) {
        flash('Barème kilométrique invalide.', 'erreur');
        redirect($retour);
    }
    if (q1('SELECT id FROM {users} WHERE email = ? AND id <> ?', [$d['email'], $id])) {
        flash('Cette adresse e-mail est déjà utilisée.', 'erreur');
        redirect($retour);
    }
    if (q1('SELECT id FROM {users} WHERE initiales = ? AND id <> ?', [$d['initiales'], $id])) {
        flash('Ces initiales sont déjà utilisées par un autre compte.', 'erreur');
        redirect($retour);
    }
    if ((!$id || $pw !== '') && ($msg = check_password_rules($pw))) {
        flash($msg, 'erreur');
        redirect($retour);
    }
    if ($id === (int)$moi['id'] && ($d['role'] !== 'admin' || !$d['actif'])) {
        flash("Vous ne pouvez pas retirer vos propres droits d'administrateur.", 'erreur');
        redirect($retour);
    }
    if ($d['admin_fiche'] && !$d['responsable_id']) {
        flash("Choisissez l'associé qui validera la fiche : personne ne valide sa propre fiche.", 'erreur');
        redirect($retour);
    }
    if ($id) {
        q('UPDATE {users} SET prenom = ?, nom = ?, initiales = ?, email = ?, role = ?, admin_fiche = ?, coef_km = ?, actif = ?,
             responsable_id = ?, rembourseur_id = ? WHERE id = ?',
            array_merge(array_values($d), [$id]));
        if ($pw !== '') {
            q('UPDATE {users} SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
        }
        journal('user_modif', "#$id {$d['email']} {$d['role']}");
        lier_membre($id);
    } else {
        q('INSERT INTO {users} (prenom, nom, initiales, email, role, admin_fiche, coef_km, actif, responsable_id, rembourseur_id,
             password_hash, created_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            array_merge(array_values($d), [password_hash($pw, PASSWORD_DEFAULT)]));
        // l'identifiant est lu AVANT d'écrire dans le journal (sinon on lirait celui du journal)
        $nouvel_id = (int)db()->lastInsertId();
        lier_membre($nouvel_id);
        journal('user_ajout', "{$d['email']} {$d['role']}");
    }
    flash('Utilisateur enregistré.');
    redirect(url('utilisateurs'));

case 'membre_save':
    post_only();
    require_admin();
    $mid = (int)($_POST['id'] ?? 0);
    $prenom = trim((string)($_POST['prenom'] ?? ''));
    $ini = mb_strtoupper(trim((string)($_POST['initiales'] ?? '')));
    $ordre = (int)($_POST['ordre'] ?? 0);
    $actif = !empty($_POST['actif']) ? 1 : 0;
    if ($prenom === '' || $ini === '' || mb_strlen($ini) > 6) {
        flash('Renseignez le prénom et les initiales (6 caractères maximum).', 'erreur');
    } elseif (q1('SELECT id FROM {membres} WHERE initiales = ? AND id <> ?', [$ini, $mid])) {
        flash('Ces initiales sont déjà utilisées.', 'erreur');
    } elseif ($mid) {
        q('UPDATE {membres} SET prenom = ?, initiales = ?, ordre = ?, actif = ? WHERE id = ?', [$prenom, $ini, $ordre, $actif, $mid]);
        journal('membre_modif', "$mid $ini");
        flash('Membre enregistré.');
    } else {
        if (!$ordre) {
            $ordre = (int)(q1('SELECT COALESCE(MAX(ordre), 0) + 10 o FROM {membres}')['o']);
        }
        q('INSERT INTO {membres} (prenom, initiales, ordre, actif) VALUES (?, ?, ?, 1)', [$prenom, $ini, $ordre]);
        journal('membre_ajout', $ini);
        flash('Membre ajouté.');
    }
    redirect(url('utilisateurs') . '#membres');

case 'absence_perso':
    // Congés / formations en journées entières, saisis par l'associé lui-même ou par l'administrateur
    post_only();
    $u = require_login();
    $uid = (int)($_POST['u'] ?? 0);
    $cible = q1('SELECT * FROM {users} WHERE id = ? AND actif = 1', [$uid]);
    $a = (int)($_POST['a'] ?? 0);
    $m = (int)($_POST['m'] ?? 0);
    $retour = url('fiche', ['a' => $a, 'm' => $m] + (is_admin() ? ['u' => $uid] : []));
    $autorise = $cible && (est_associe($cible) || est_salarie_fiche($cible))
        && ((int)$cible['id'] === (int)$u['id'] || is_admin());
    if (!$autorise) {
        http_response_code(403);
        exit('Action non autorisée.');
    }
    $type = ($_POST['type'] ?? '') === 'formation' ? 'formation' : 'conge';
    $du = (string)($_POST['du'] ?? '');
    $au = (string)($_POST['au'] ?? '') !== '' ? (string)$_POST['au'] : $du;
    $ok = fn(string $d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    if (!$ok($du) || !$ok($au) || $au < $du) {
        flash('Indiquez une date de début et une date de fin valides.', 'erreur');
        redirect($retour);
    }
    if ((new DateTimeImmutable($du))->diff(new DateTimeImmutable($au))->days > 62) {
        flash('Période trop longue : deux mois au maximum à la fois.', 'erreur');
        redirect($retour);
    }
    $ajout = ($_POST['action'] ?? 'ajouter') !== 'retirer';
    [$n, $verr] = absences_periode_maj($uid, $du, $au, $type, $ajout, is_admin() ? 'manuel' : 'associe');
    $lib = $type === 'conge' ? 'congé' : 'formation';
    if ($ajout) {
        flash($n ? "$n jour(s) de $lib enregistré(s) : pas de prime repas ces jours-là." : 'Aucun jour ouvré dans cette période (week-end ou férié).', $n ? 'ok' : 'attention');
    } else {
        flash("$n jour(s) de $lib retiré(s).");
    }
    if ($verr) {
        flash("$verr jour(s) ignoré(s) : leur mois est déjà validé.", 'attention');
    }
    journal('absence', "{$cible['initiales']} $lib " . ($ajout ? 'ajout' : 'retrait') . " $du → $au ($n j)");
    redirect($retour);

case 'siren':
    require_login();
    render('siren', [], "Identité de l'entreprise");

/* ------------------------------------------------------------------ */
case 'journal':
    require_admin();
    $page_j = max(1, (int)($_GET['page'] ?? 1));
    $par_page = 100;
    $lignes = qall('SELECT j.*, u.prenom, u.nom FROM {journal} j LEFT JOIN {users} u ON u.id = j.user_id
                    ORDER BY j.id DESC LIMIT ' . $par_page . ' OFFSET ' . (($page_j - 1) * $par_page));
    $total_j = (int)q1('SELECT COUNT(*) n FROM {journal}')['n'];
    render('journal', compact('lignes', 'page_j', 'par_page', 'total_j'), 'Journal');

case 'diagnostic':
    require_admin();
    require APP_ROOT . '/lib/diagnostic.php';
    $checks = diagnostic();
    render('diagnostic', compact('checks'), 'Diagnostic');

/* ------------------------------------------------------------------ */
case 'compte':
    $u = require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $new = (string)($_POST['password'] ?? '');
        if (!password_verify((string)($_POST['actuel'] ?? ''), $u['password_hash'])) {
            flash('Mot de passe actuel incorrect.', 'erreur');
        } elseif ($msg = check_password_rules($new)) {
            flash($msg, 'erreur');
        } elseif ($new !== ($_POST['password2'] ?? '')) {
            flash('Les deux mots de passe ne correspondent pas.', 'erreur');
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            q('UPDATE {users} SET password_hash = ? WHERE id = ?', [$hash, $u['id']]);
            $_SESSION['pwv'] = substr(hash('sha256', $hash), 0, 16);
            journal('mot_de_passe');
            flash('Mot de passe modifié.');
        }
        redirect(url('compte'));
    }
    render('compte', [], 'Mon compte');

default:
    redirect(url('accueil'));
}
