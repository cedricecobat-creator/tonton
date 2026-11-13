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
    <div><dt>Primes repas</dt><dd><?= $fiche['nb_primes'] ?> × <?= euros($fiche['prime_montant']) ?></dd></div>
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
      <li>
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
