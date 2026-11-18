<?php
$page = 'accueil';
require __DIR__ . '/_mois.php';
$ouvert = mois_ouvert($a, $m);
$moi = (int)$user['id'];
?>

<?php if ($ouvert): ?>
  <a class="btn btn-primaire btn-plein btn-grand" href="<?= h(url('depense')) ?>">📷&nbsp; Ajouter un ticket</a>
<?php endif; ?>

<?php if ($fiche): ?>
<section class="carte resume">
  <div class="resume-total">
    <span class="muted petit"><?= $fiche['definitif'] ? 'À vous régler' : 'À vous régler (à ce jour)' ?></span>
    <strong><?= euros($fiche['totaux']['total']) ?></strong>
  </div>
  <dl class="resume-detail">
    <div><dt>Avances perso (CB, espèces)</dt><dd><?= euros($fiche['total_avances']) ?></dd></div>
    <?php if ($fiche['totaux']['total_kms'] > 0): ?>
      <div><dt>Frais kilométriques</dt><dd><?= euros($fiche['totaux']['total_kms']) ?></dd></div>
    <?php endif; ?>
    <?php if ($fiche['regul']): ?>
      <div><dt><a href="<?= h(url('fiche', ['a' => $a, 'm' => $m])) ?>#regularisations">Régularisations mois précédents</a></dt><dd><?= euros($fiche['total_regul']) ?></dd></div>
    <?php endif; ?>
    <div class="muted"><dt>CB entreprise (info)</dt><dd><?= count($fiche['cb_pro']) ?> ticket(s) · <?= euros($fiche['total_cb_pro']) ?></dd></div>
  </dl>
  <p class="liens-resume">
    <a class="lien-discret" href="<?= h(url('fiche', ['a' => $a, 'm' => $m])) ?>">Voir ma fiche du mois →</a>
    <a class="lien-discret" href="<?= h(url('fiche', ['a' => $a, 'm' => $m])) ?>#conges">Mes congés et formations →</a>
  </p>
</section>
<?php endif; ?>

<?php if ($nb_remb || $nb_suivis): ?>
<a class="carte carte-lien" href="<?= h(url('suivi', ['a' => $a, 'm' => $m])) ?>">
  <span><strong>Suivi des salariés</strong><br><span class="petit muted"><?= $nb_remb ? $nb_remb . ' remboursement(s) à faire' : 'Aucun remboursement en attente' ?><?= $nb_suivis ? ' · ' . $nb_suivis . ' fiche(s) RH suivie(s)' : '' ?></span></span>
  <?php if ($nb_remb): ?><span class="badge badge-alerte"><?= $nb_remb ?></span><?php endif; ?>
</a>
<?php endif; ?>

<?php if ($frh): $ti = $frh['info']; ?>
<section class="carte resume">
  <div class="resume-total">
    <span class="muted petit">À vous rembourser avec le salaire</span>
    <strong><?= euros($frh['totaux']['paie']) ?></strong>
  </div>
  <dl class="resume-detail">
    <?php if ($frh['totaux']['attente'] > 0): ?><div><dt>En attente de virement (dès que possible)</dt><dd><?= euros($frh['totaux']['attente']) ?></dd></div><?php endif; ?>
    <?php if ($frh['totaux']['rembourse'] > 0): ?><div><dt>Déjà remboursé ce mois</dt><dd><?= euros($frh['totaux']['rembourse']) ?></dd></div><?php endif; ?>
    <div><dt>Heures supplémentaires</dt><dd><?= h(nombre_fr($frh['total_heures'])) ?> h</dd></div>
    <div class="muted"><dt>Fiche RH</dt><dd><?= $ti['valide_resp_at'] ? 'validée' : ($ti['valide_collab_at'] ? 'envoyée au responsable' : 'à compléter') ?></dd></div>
  </dl>
  <a class="btn btn-secondaire btn-plein" href="<?= h(url('fiche_rh', ['a' => $a, 'm' => $m])) ?>">Remplir ma fiche RH du mois</a>
</section>
<?php endif; ?>

<section>
  <h2 class="titre-section">Tickets du mois <span class="muted">(<?= count($deps) ?>)</span></h2>
  <?php if (count($deps) > 1):
      // valeurs proposées dans les filtres : seulement celles présentes ce mois-ci
      $payeurs = [];
      $convives = [];
      $cats = [];
      foreach ($deps as $d) {
          $payeurs[(int)$d['user_id']] = (int)$d['user_id'] === $moi ? 'Moi' : $d['payeur_prenom'];
          foreach ($d['participants'] ?? [] as $p) {
              $convives[$p['initiales']] = $p['prenom'] . ' (' . $p['initiales'] . ')';
          }
          $cats[$d['categorie']] = true;
          if ((int)$d['equipe']) {
              $cats['equipe'] = true;
          }
      }
      asort($payeurs);
      asort($convives);
  ?>
  <details class="filtres carte" id="filtres-tickets">
    <summary>Filtrer les tickets <span class="badge badge-filtres" id="filtres-nb" hidden></span></summary>
    <div class="filtres-grille">
      <label class="filtre-texte">Rechercher<input type="search" data-filtre="texte" placeholder="Commerçant, ville, nature…" autocomplete="off"></label>
      <label>Catégorie<select data-filtre="cat">
        <option value="">Toutes</option>
        <?php foreach (CATEGORIES as $k => $lib): if (!isset($cats[$k])) continue; ?><option value="<?= $k ?>"><?= h($lib) ?></option><?php endforeach; ?>
        <?php if (isset($cats['equipe'])): ?><option value="equipe">Repas d'équipe seulement</option><?php endif; ?>
      </select></label>
      <label>Payé avec<select data-filtre="paiement">
        <option value="">Tous</option>
        <?php foreach (PAIEMENTS as $k => $lib): ?><option value="<?= $k ?>"><?= h($lib) ?></option><?php endforeach; ?>
      </select></label>
      <?php if (count($payeurs) > 1): ?>
      <label>Payé par<select data-filtre="payeur">
        <option value="">Tout le monde</option>
        <?php foreach ($payeurs as $id => $nom): ?><option value="<?= $id ?>"><?= h($nom) ?></option><?php endforeach; ?>
      </select></label>
      <?php endif; ?>
      <?php if ($convives): ?>
      <label>Participant<select data-filtre="convive">
        <option value="">Tous</option>
        <?php foreach ($convives as $ini => $nom): ?><option value="<?= h($ini) ?>"><?= h($nom) ?></option><?php endforeach; ?>
      </select></label>
      <?php endif; ?>
      <label>Particularité<select data-filtre="special">
        <option value="">Aucune</option>
        <option value="midi">Repas du midi</option>
        <option value="alerte">⚠ Facture obligatoire manquante</option>
        <option value="facture">Facture demandée</option>
        <option value="sans">Sans ticket</option>
        <option value="regul">Régularisation</option>
      </select></label>
    </div>
    <button type="button" class="btn btn-lien" id="filtres-effacer">Effacer les filtres</button>
  </details>
  <p class="petit muted filtres-resultat" id="filtres-resultat" hidden></p>
  <?php endif; ?>
  <?php if (!$deps): ?>
    <p class="vide">Aucun ticket pour <?= h(mois_libelle($a, $m)) ?>.<?= $ouvert ? ' Touchez « Ajouter un ticket » pour photographier votre premier justificatif.' : '' ?></p>
  <?php else: ?>
    <ul class="liste-tickets">
    <?php
    $jour = null;
    foreach (array_reverse($deps) as $d):
        $mien = (int)$d['user_id'] === $moi;
        if ($d['date_depense'] !== $jour):
            $jour = $d['date_depense']; ?>
        <li class="liste-jour"><?= h(ucfirst(JOURS_FR[(int)date('N', strtotime($jour))]) . ' ' . date_fr($jour)) ?></li>
      <?php endif; ?>
      <li class="ligne-ticket" data-cat="<?= h($d['categorie']) ?>" data-equipe="<?= (int)$d['equipe'] ?>" data-paiement="<?= h($d['paiement']) ?>"
          data-payeur="<?= (int)$d['user_id'] ?>" data-convives="<?= h(implode(',', array_map(fn($p) => $p['initiales'], $d['participants'] ?? []))) ?>"
          data-montant="<?= h((string)(float)$d['montant']) ?>"
          data-special="<?= h(implode(' ', array_filter([
              $d['categorie'] === 'repas' && (int)$d['repas_midi'] ? 'midi' : '', alerte_facture($d) ? 'alerte' : '',
              (int)$d['facture_demandee'] ? 'facture' : '', (int)$d['sans_justificatif'] ? 'sans' : '', $d['est_regul'] ? 'regul' : '']))) ?>"
          data-texte="<?= h(mb_strtolower(implode(' ', [$d['lieu'], $d['ville'] ?? '', $d['objet'], $d['description'] ?? '', $d['invites'] ?? '', $d['payeur_prenom'], euros($d['montant'])]), 'UTF-8')) ?>">
        <a class="ticket" href="<?= h(url('depense', ['id' => $d['id']])) ?>">
          <span class="ticket-vignette">
            <?php if ($d['fichier']): ?><img src="<?= h(url('fichier', ['id' => $d['id'], 'mini' => 1])) ?>" alt="" loading="lazy"><?php else: ?><span><?= $d['categorie'] === 'km' ? '🚗' : '—' ?></span><?php endif; ?>
          </span>
          <span class="ticket-texte">
            <span class="ticket-objet"><?= h(lieu_complet($d) ?: $d['objet']) ?></span>
            <span class="ticket-sous">
              <?= (int)$d['equipe'] ? "Repas d'équipe" : h(CATEGORIES[$d['categorie']]) ?><?= $d['categorie'] === 'repas' && $d['repas_midi'] ? ' midi' : '' ?>
              <?php if ($lp = libelle_participants($d)): ?> · <?= h($lp) ?><?php endif; ?>
              <?php if (!$mien): ?> · réglé par <?= h($d['payeur_prenom']) ?><?php endif; ?>
            </span>
          </span>
          <span class="ticket-montant">
            <?= euros($d['montant']) ?>
            <span class="badge badge-<?= h($d['paiement']) ?>"><?= h(PAIEMENTS[$d['paiement']]) ?></span>
            <?php if (alerte_facture($d)): ?><span class="badge badge-alerte">⚠ facture &gt; <?= h(nombre_fr(seuil_facture(), 0)) ?> € HT</span>
            <?php elseif ((int)$d['facture_demandee']): ?><span class="badge badge-facture">facture demandée</span><?php endif; ?>
            <?php if ((int)$d['sans_justificatif']): ?><span class="badge badge-sans">sans ticket</span><?php endif; ?>
            <?php if ($d['est_regul']): ?><span class="badge badge-regul">régularisation <?= h(MOIS_FR[(int)substr($d['date_depense'], 5, 2)]) ?></span><?php endif; ?>
          </span>
        </a>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php if ($fiche && $ouvert): ?>
<section class="carte carte-fin">
  <?php if ($soumis): ?>
    <p>✅ Saisie terminée le <?= h(date('d/m/Y', strtotime($soumis['soumis_at'] . ' UTC'))) ?>. Vous pouvez encore ajouter un ticket oublié tant que le mois n'est pas validé.</p>
    <form method="post" action="<?= h(url('terminer')) ?>"><?= csrf_field() ?>
      <input type="hidden" name="a" value="<?= $a ?>"><input type="hidden" name="m" value="<?= $m ?>"><input type="hidden" name="annuler" value="1">
      <button class="btn btn-lien">Annuler « saisie terminée »</button>
    </form>
  <?php else: ?>
    <p class="muted">Tous vos tickets de <?= h(mois_libelle($a, $m)) ?> sont saisis ? Prévenez l'administrateur.</p>
    <form method="post" action="<?= h(url('terminer')) ?>"><?= csrf_field() ?>
      <input type="hidden" name="a" value="<?= $a ?>"><input type="hidden" name="m" value="<?= $m ?>">
      <button class="btn btn-secondaire btn-plein">J'ai terminé ma saisie du mois</button>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>
