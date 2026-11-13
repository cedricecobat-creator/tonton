<?php
$id = (int)$dep['id'];
$partIds = array_map(fn($p) => (int)$p['id'], $dep['participants']);
[$da, $dm] = mois_de_date($dep['date_depense']);
$coef = (float)($dep['coef_km'] ?? $user['coef_km']);
// Un membre désactivé reste affiché sur un ancien ticket où il figure
foreach ($dep['participants'] as $pt) {
    if (!in_array((int)$pt['id'], array_map(fn($x) => (int)$x['id'], $membres), true)) {
        $membres[] = $pt;
    }
}
$avecFiche = array_values(array_filter($membres, fn($x) => !empty($x['user_id']) && ($x['role'] ?? '') === 'associe'));
$employe = est_salarie_fiche($payeurDep);
$rembourseur = $employe && $payeurDep['rembourseur_id'] ? q1('SELECT prenom FROM {users} WHERE id = ?', [(int)$payeurDep['rembourseur_id']]) : null;
$nomsFiche = implode(' et ', array_map(fn($x) => $x['prenom'], $avecFiche));
$avecInvites = trim((string)$dep['invites']) !== '';
$ent = entreprise();
$sansJustif = (int)($dep['sans_justificatif'] ?? 0);
$alerte = $id && alerte_facture($dep);
?>
<div class="entete-page">
  <a class="retour" href="<?= h(url_retour($da, $dm)) ?>">‹ Retour<?= ($_GET['retour'] ?? '') === 'fiche' ? ' à la fiche' : '' ?></a>
  <h1><?= $id ? ($modifiable ? 'Modifier le ticket' : 'Ticket') : 'Nouveau ticket' ?></h1>
</div>

<?php if ($id && !$modifiable): ?>
  <p class="alerte">
    <?= (int)$dep['user_id'] !== (int)$user['id'] ? 'Ticket saisi par ' . h($dep['payeur_prenom']) . ' : lecture seule.' : 'Le mois est validé : ce ticket ne peut plus être modifié.' ?>
  </p>
<?php endif; ?>

<form id="form-depense" class="form" method="post" action="<?= h(url('depense_save')) ?>" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $id ?>"><?= champs_retour() ?>
  <input type="hidden" name="confirm_doublon" value="">
  <input type="hidden" name="confirm_150" value="">
  <fieldset <?= $modifiable ? '' : 'disabled' ?>>

  <!-- Photo -->
  <div class="photo-bloc">
    <div class="photo-apercu <?= $dep['fichier'] ? 'a-image' : '' ?>" id="photo-apercu">
      <?php if ($dep['fichier']): ?>
        <a href="<?= h(url('fichier', ['id' => $id])) ?>" target="_blank"><img src="<?= h(url('fichier', ['id' => $id])) ?>" alt="Justificatif"></a>
      <?php else: ?>
        <span class="photo-vide">Aucun justificatif</span>
      <?php endif; ?>
    </div>
    <?php if ($modifiable): ?>
    <div class="photo-boutons">
      <label class="btn btn-primaire">📷 Photographier
        <input type="file" name="photo" accept="image/*" capture="environment" class="cache" data-photo>
      </label>
      <label class="btn btn-secondaire">📎 Fichier ou PDF
        <input type="file" name="photo2" accept="image/*,application/pdf" class="cache" data-photo>
      </label>
    </div>
    <p class="ocr-etat" id="ocr-etat" hidden></p>
    <?php endif; ?>
    <?php if ($id && $dep['fichier'] && chemin_original($dep['fichier'])): ?>
      <a class="petit" href="<?= h(url('fichier', ['id' => $id, 'orig' => 1])) ?>" target="_blank">Voir la photo d'origine (avant recadrage)</a>
    <?php endif; ?>
    <label class="case petit" data-si-pas-km><input type="checkbox" name="sans_justificatif" value="1" id="chk-sans-justif" <?= $sansJustif ? 'checked' : '' ?>>
      Pas de ticket (perdu ou illisible) : générer un justificatif sans photo</label>
    <p class="aide" id="aide-sans-justif" <?= $sansJustif ? '' : 'hidden' ?>>Un justificatif est généré à partir des informations saisies. Expliquez la situation dans la description. Sans ticket original, la TVA n'est en général pas récupérable.</p>
  </div>

  <?php if ($tous): ?>
  <label>Payé par
    <select name="payeur_id">
      <?php foreach ($tous as $t): ?>
        <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === (int)$dep['user_id'] ? 'selected' : '' ?>><?= h(nom_complet($t)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>

  <div class="grille-2">
    <label>Date<input type="date" name="date_depense" required value="<?= h($dep['date_depense']) ?>" max="<?= h(aujourdhui()) ?>"></label>
    <label data-si-pas-km>Montant TTC
      <span class="champ-euro"><input type="text" name="montant" inputmode="decimal" autocomplete="off" placeholder="0,00"
        value="<?= $dep['montant'] !== null && $dep['categorie'] !== 'km' ? h(nombre_fr($dep['montant'])) : '' ?>"></span>
    </label>
    <label data-si-km hidden>Kilomètres
      <input type="text" name="kms" inputmode="decimal" autocomplete="off" value="<?= $dep['kms'] !== null ? h(nombre_fr($dep['kms'], 1)) : '' ?>" data-coef="<?= h((string)$coef) ?>">
    </label>
  </div>
  <p class="aide" data-si-km hidden>Barème : <?= h(nombre_fr($coef, 3)) ?> €/km · montant : <strong id="montant-km">—</strong></p>

  <div class="champ">
    <span class="champ-titre">Catégorie</span>
    <div class="choix">
      <?php foreach (CATEGORIES as $k => $lib): ?>
        <label class="puce"><input type="radio" name="categorie" value="<?= $k ?>" <?= $dep['categorie'] === $k ? 'checked' : '' ?>><span><?= h($lib) ?></span></label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="champ" data-si-pas-km>
    <span class="champ-titre">Payé avec</span>
    <div class="choix choix-3">
      <label class="puce puce-pro"><input type="radio" name="paiement" value="cb_pro" <?= $dep['paiement'] === 'cb_pro' ? 'checked' : '' ?>><span>CB entreprise</span></label>
      <label class="puce puce-perso"><input type="radio" name="paiement" value="cb_perso" <?= $dep['paiement'] === 'cb_perso' ? 'checked' : '' ?>><span>CB perso <small>(à rembourser)</small></span></label>
      <label class="puce puce-perso"><input type="radio" name="paiement" value="especes" <?= $dep['paiement'] === 'especes' ? 'checked' : '' ?>><span>Espèces <small>(à rembourser)</small></span></label>
    </div>
  </div>

  <?php if ($employe): ?>
  <div class="champ bloc-remb" data-si-avance <?= in_array($dep['paiement'], ['cb_perso', 'especes'], true) ? '' : 'hidden' ?>>
    <span class="champ-titre">Remboursement souhaité</span>
    <?php if (($dep['remb_statut'] ?? null) === 'fait'): ?>
      <p class="alerte alerte-ok">Remboursé le <?= h(date('d/m/Y', strtotime($dep['remb_le'] . ' UTC'))) ?>.</p>
    <?php endif; ?>
    <div class="choix choix-2">
      <label class="puce"><input type="radio" name="remb_mode" value="paie" <?= ($dep['remb_mode'] ?? 'paie') === 'paie' ? 'checked' : '' ?>><span>Avec mon salaire</span></label>
      <label class="puce"><input type="radio" name="remb_mode" value="immediat" <?= ($dep['remb_mode'] ?? '') === 'immediat' ? 'checked' : '' ?>><span>Dès que possible</span></label>
    </div>
    <p class="aide"><?= $rembourseur ? '« Dès que possible » : ' . h($rembourseur['prenom']) . ' est prévenu par e-mail et fait le virement.' : "Aucun associé n'est encore désigné pour vos remboursements." ?></p>
  </div>
  <?php endif; ?>

  <div class="bloc-repas" data-si-repas>
    <label class="interrupteur">
      <input type="checkbox" name="repas_midi" value="1" <?= (int)$dep['repas_midi'] ? 'checked' : '' ?>>
      <span>Repas du midi</span>
    </label>
    <p class="aide">Un repas du midi supprime la prime repas de chaque associé présent, quel que soit celui qui paie.</p>
    <div class="champ">
      <span class="champ-titre">Participants</span>
      <div class="choix">
        <label class="puce puce-equipe"><input type="checkbox" name="equipe" value="1" id="chk-equipe" <?= (int)($dep['equipe'] ?? 0) ? 'checked' : '' ?>><span>👥 Équipe</span></label>
        <?php foreach ($membres as $mb): ?>
          <label class="puce"><input type="checkbox" name="participants[]" value="<?= (int)$mb['id'] ?>" <?= in_array((int)$mb['id'], $partIds, true) ? 'checked' : '' ?>><span><?= h($mb['prenom']) ?> <small>(<?= h($mb['initiales']) ?>)</small></span></label>
        <?php endforeach; ?>
        <label class="puce puce-invite"><input type="checkbox" name="avec_invites" value="1" id="chk-invites" <?= $avecInvites ? 'checked' : '' ?>><span>+ Invité</span></label>
      </div>
      <p class="aide" id="aide-equipe" <?= (int)($dep['equipe'] ?? 0) ? '' : 'hidden' ?>>Repas d'équipe : <?= h($nomsFiche ?: 'les associés') ?> sont décomptés automatiquement (pas de prime repas ce jour-là si c'est un repas du midi).</p>
    </div>
    <label id="bloc-invites" <?= $avecInvites ? '' : 'hidden' ?>>Nom de l'invité ou des invités <span class="obligatoire">*</span>
      <input name="invites" maxlength="255" placeholder="Ex. : M. Dupont (société X)" value="<?= h($dep['invites']) ?>">
    </label>
  </div>

  <div class="grille-2" data-si-pas-km>
    <label>Commerçant <span class="obligatoire">*</span><input name="lieu" maxlength="255" placeholder="Ex. : Brasserie Le Berry" value="<?= h($dep['lieu']) ?>"></label>
    <label>Ville <span class="obligatoire">*</span><input name="ville" maxlength="120" placeholder="Ex. : Pau" value="<?= h($dep['ville'] ?? '') ?>"></label>
  </div>
  <label data-si-km hidden>Trajet<input name="lieu_km" maxlength="255" placeholder="Ex. : Bayonne - Pau aller-retour" value="<?= $dep['categorie'] === 'km' ? h($dep['lieu']) : '' ?>"></label>
  <label>Nature de la dépense <span class="muted">(remplie automatiquement si vide)</span><input name="objet" maxlength="255" placeholder="Ex. : Déjeuner client, câble réseau…" value="<?= h($dep['objet']) ?>"></label>
  <label>Description <span class="muted" id="desc-facultatif">(facultatif)</span><span class="obligatoire" id="desc-obligatoire" hidden>*</span>
    <textarea name="description" rows="2" maxlength="2000" placeholder="Informations utiles pour le comptable"><?= h($dep['description'] ?? '') ?></textarea>
  </label>

  <div class="bloc-facture" data-si-pas-km>
    <label class="interrupteur">
      <input type="checkbox" name="facture_demandee" value="1" id="chk-facture" <?= (int)($dep['facture_demandee'] ?? 0) ? 'checked' : '' ?>>
      <span>Facture électronique demandée au commerçant</span>
    </label>
    <p class="aide">La facture au nom de l'entreprise arrivera par la plateforme de facturation électronique : le comptable ne la comptera pas deux fois.</p>
    <?php if ($ent['siren']): ?>
      <button type="button" class="btn btn-secondaire" id="btn-siren">Montrer le SIREN au commerçant</button>
    <?php endif; ?>
    <div class="alerte alerte-attention" id="alerte-150" data-seuil="<?= h((string)seuil_facture()) ?>" <?= $alerte ? '' : 'hidden' ?>>
      <strong>À partir de <?= h(nombre_fr(seuil_facture(), 0)) ?> € HT : facture obligatoire.</strong>
      Demandez au commerçant une facture au nom de l'entreprise<?= $ent['siren'] ? ' (SIREN ' . h($ent['siren_affiche']) . ')' : '' ?>, puis cochez « Facture électronique demandée ».
      <span class="petit" id="alerte-150-ht"></span>
    </div>
    <details class="details-compta" <?= ($dep['siret'] ?? '') !== '' || ($dep['montant_tva'] ?? null) !== null ? 'open' : '' ?>>
      <summary>Informations pour la comptabilité <span class="muted">(facultatif, lues sur le ticket si possible)</span></summary>
      <div class="grille-compta">
        <label>SIRET du commerçant<input name="siret" inputmode="numeric" maxlength="17" placeholder="14 chiffres" value="<?= h($dep['siret'] ?? '') ?>"></label>
        <label>Montant de TVA
          <span class="champ-euro"><input name="montant_tva" inputmode="decimal" autocomplete="off" placeholder="0,00"
            value="<?= ($dep['montant_tva'] ?? null) !== null ? h(nombre_fr($dep['montant_tva'])) : '' ?>"></span>
        </label>
      </div>
    </details>
  </div>

  <?php if ($modifiable): ?>
    <p class="alerte alerte-erreur" id="form-erreur" hidden></p>
    <button class="btn btn-primaire btn-plein btn-grand" id="btn-enregistrer">Enregistrer</button>
  <?php endif; ?>
  </fieldset>
</form>

<?php if ($id && $modifiable): ?>
<form method="post" action="<?= h(url('depense_suppr')) ?>" data-confirmer="Supprimer définitivement ce ticket ?" class="form-suppr">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><?= champs_retour() ?>
  <button class="btn btn-lien btn-danger">Supprimer ce ticket</button>
</form>
<?php endif; ?>

<?php if ($modifiable): ?>
<script src="assets/vendor/tesseract/tesseract.min.js" defer></script>
<script src="assets/vendor/pdfjs/pdf.min.js" defer></script>
<script src="assets/ocr-analyse.js?v=<?= APP_VERSION ?>" defer></script>
<script src="assets/recadrage.js?v=<?= APP_VERSION ?>" defer></script>
<script src="assets/saisie.js?v=<?= APP_VERSION ?>" defer></script>
<?php else: ?>
<script src="assets/saisie.js?v=<?= APP_VERSION ?>" defer></script>
<?php endif; ?>

<?php if ($ent['siren']): ?>
<div class="siren-fond" id="siren-fond" hidden>
  <div class="siren-carte" role="dialog" aria-label="Identité de l'entreprise">
    <p class="muted petit">Merci d'établir la facture au nom de :</p>
    <p class="siren-nom"><?= h($ent['nom']) ?></p>
    <p class="siren-num">SIREN <strong><?= h($ent['siren_affiche']) ?></strong></p>
    <?php if ($ent['tva']): ?><p>N° TVA <?= h($ent['tva']) ?></p><?php endif; ?>
    <?php if ($ent['adresse']): ?><p><?= nl2br(h($ent['adresse'])) ?></p><?php endif; ?>
    <?php if ($ent['adresse_fe']): ?><p class="petit">Adresse de facturation électronique : <?= h($ent['adresse_fe']) ?></p><?php endif; ?>
    <button type="button" class="btn btn-primaire btn-plein" id="btn-siren-fermer">Fermer</button>
  </div>
</div>
<?php endif; ?>
