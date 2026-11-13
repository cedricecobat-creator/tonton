<?php
$page = 'gestion';
require __DIR__ . '/_mois.php';
$st = $info['statut'];
$qs = ['a' => $a, 'm' => $m];
[, $fin] = mois_bornes($a, $m);
$termine = $fin < aujourdhui();
$nbAbs = (int)q1('SELECT COUNT(*) n FROM {absences} WHERE date_absence BETWEEN ? AND ?', [mois_bornes($a, $m)[0], $fin])['n'];
$total = array_sum(array_map(fn($f) => $f['totaux']['total'], $lignes));
$action = function (string $act, string $libelle, string $classe = 'btn-primaire', string $confirm = '') use ($a, $m) {
    return '<form method="post" action="' . h(url('gestion_action')) . '"' . ($confirm ? ' data-confirmer="' . h($confirm) . '"' : '') . '>'
        . csrf_field() . '<input type="hidden" name="a" value="' . $a . '"><input type="hidden" name="m" value="' . $m . '">'
        . '<input type="hidden" name="action" value="' . $act . '"><button class="btn ' . $classe . '">' . h($libelle) . '</button></form>';
};
?>

<section class="carte">
  <h2 class="titre-section">Fiches du mois</h2>
  <div class="table-defile">
  <table class="tableau">
    <thead><tr><th>Associé</th><th class="num">Tickets</th><th class="num">CB perso</th><th class="num">Km</th><th class="num">Primes</th><th class="num">À régler</th><th>Saisie</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lignes as $f): ?>
      <tr>
        <td><a href="<?= h(url('fiche', $qs + ['u' => $f['user']['id']])) ?>"><?= h(nom_complet($f['user'])) ?></a></td>
        <td class="num"><?= $f['nb_tickets'] ?></td>
        <td class="num"><?= euros($f['total_avances']) ?></td>
        <td class="num"><?= euros($f['totaux']['total_kms']) ?></td>
        <td class="num"><?= $f['nb_primes'] ?> × <?= h(nombre_fr($f['prime_montant'])) ?></td>
        <td class="num fort"><?= euros($f['totaux']['total']) ?></td>
        <td><?= $f['soumis'] ? '<span class="badge badge-ok">terminée</span>' : '<span class="badge">en cours</span>' ?></td>
        <td><a class="petit" href="<?= h(url('export_zip', $qs + ['u' => $f['user']['id']])) ?>">ZIP</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th colspan="5">Total à virer</th><td class="num fort"><?= euros($total) ?></td><td colspan="2"></td></tr></tfoot>
  </table>
  </div>
  <p class="petit muted">Tickets CB entreprise du mois (non remboursés) : <?= euros($total_cbpro) ?> · <?= $nb_justifs ?> justificatif(s) au total.</p>
</section>

<?php if ($fiches_rh): ?>
<section class="carte">
  <h2 class="titre-section">Fiches RH des salariés</h2>
  <div class="table-defile">
  <table class="tableau">
    <thead><tr><th>Salarié</th><th>Responsable</th><th>État</th><th class="num">Frais avec salaire</th><th class="num">Heures sup</th><th class="num">Congés</th><th>Primes</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($fiches_rh as $f): $i = $f['info']; ?>
      <tr>
        <td><a href="<?= h(url('fiche_rh', $qs + ['u' => $f['user']['id']])) ?>"><?= h(nom_complet($f['user'])) ?></a></td>
        <td class="petit"><?= h($f['responsable']['prenom'] ?? '—') ?></td>
        <td><?= $i['valide_resp_at'] ? '<span class="badge badge-ok">validée</span>' : ($i['valide_collab_at'] ? '<span class="badge badge-alerte">à valider</span>' : '<span class="badge">en cours</span>') ?></td>
        <td class="num"><?= euros($f['totaux']['paie']) ?></td>
        <td class="num"><?= h(nombre_fr($f['total_heures'])) ?> h</td>
        <td class="num"><?= array_sum(array_column($f['conges'], 2)) ?> j</td>
        <td class="petit"><?= implode(', ', array_filter([(int)$i['prime_qualite'] ? 'qualité' : '', (int)$i['prime_contributions'] ? 'contributions' : ''])) ?: '—' ?></td>
        <td><a class="petit" href="<?= h(url('export_zip', $qs + ['u' => $f['user']['id']])) ?>">ZIP</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($remb_attente): ?>
    <p class="petit"><a href="<?= h(url('suivi', $qs)) ?>"><?= count($remb_attente) ?> remboursement(s) « dès que possible » en attente</a> (<?= euros(array_sum(array_column($remb_attente, 'montant'))) ?>).</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($alertes || $sans_ticket || $factures): ?>
<section class="carte">
  <h2 class="titre-section">Points d'attention</h2>
  <?php $ligneT = fn($d) => '<a href="' . h(url('depense', ['id' => $d['id']])) . '">' . h(date_fr($d['date_depense'])) . ' · '
      . h(lieu_complet($d) ?: $d['objet']) . ' · ' . euros($d['montant']) . '</a> <span class="muted">(' . h($d['payeur_prenom']) . ', ' . h(PAIEMENTS[$d['paiement']]) . ')</span>'; ?>
  <?php if ($alertes): ?>
    <div class="alerte alerte-attention">
      <strong><?= count($alertes) ?> ticket(s) de <?= h(nombre_fr(seuil_facture(), 0)) ?> € HT ou plus sans facture électronique demandée.</strong>
      Une facture au nom de l'entreprise est obligatoire : demandez-la au commerçant ou vérifiez avec le comptable.
      <ul class="attention-liste"><?php foreach ($alertes as $d): ?><li><?= $ligneT($d) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <?php if ($sans_ticket): ?>
    <div class="alerte">
      <strong><?= count($sans_ticket) ?> dépense(s) sans ticket original</strong> (justificatif généré à partir de la saisie) :
      <ul class="attention-liste"><?php foreach ($sans_ticket as $d): ?><li><?= $ligneT($d) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <?php if ($factures): ?>
    <p class="petit"><strong><?= count($factures) ?> facture(s) électronique(s) demandée(s)</strong> : elles arriveront par la plateforme de facturation électronique.</p>
    <ul class="attention-liste"><?php foreach ($factures as $d): ?><li><?= $ligneT($d) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="etapes">
  <div class="etape <?= $nbAbs ? 'fait' : '' ?>">
    <div class="etape-titre"><span class="etape-num">1</span> Congés et formations</div>
    <p class="petit"><?= $nbAbs ?> jour(s) enregistré(s) ce mois-ci. Importez le fichier envoyé par Odoo ou cochez les jours à la main.</p>
    <a class="btn btn-secondaire" href="<?= h(url('absences', $qs)) ?>">Gérer les absences</a>
  </div>

  <div class="etape <?= $st !== 'ouvert' ? 'fait' : '' ?>">
    <div class="etape-titre"><span class="etape-num">2</span> Valider le mois</div>
    <?php if ($st === 'ouvert'): ?>
      <p class="petit">La validation verrouille les tickets et fige les primes.<?= $termine ? '' : ' <strong>Le mois n\'est pas terminé.</strong>' ?></p>
      <form method="post" action="<?= h(url('gestion_action')) ?>" class="ligne-form">
        <?= csrf_field() ?><input type="hidden" name="a" value="<?= $a ?>"><input type="hidden" name="m" value="<?= $m ?>"><input type="hidden" name="action" value="prime">
        <label class="petit">Prime repas du mois
          <span class="champ-euro"><input name="prime_montant" inputmode="decimal" value="<?= h(nombre_fr($info['prime_montant'])) ?>" size="5"></span>
        </label>
        <button class="btn btn-lien">Modifier</button>
      </form>
      <?= $action('valider', 'Valider ' . mois_libelle($a, $m), 'btn-primaire',
          $termine ? 'Valider le mois ? Les associés ne pourront plus modifier leurs tickets.' : "Le mois n'est pas terminé. Valider quand même ?") ?>
    <?php else: ?>
      <p class="petit">Validé le <?= h(date('d/m/Y', strtotime($info['valide_at'] . ' UTC'))) ?>.</p>
      <?= $action('rouvrir', 'Rouvrir le mois', 'btn-lien', 'Rouvrir le mois ? Les primes seront recalculées et les associés pourront de nouveau modifier leurs tickets.') ?>
    <?php endif; ?>
  </div>

  <div class="etape <?= in_array($st, ['paye', 'transmis'], true) ? 'fait' : '' ?>">
    <div class="etape-titre"><span class="etape-num">3</span> Envoyer pour les virements</div>
    <p class="petit">Téléchargez le tableau Excel (une feuille par associé et un récapitulatif des virements) et envoyez-le.</p>
    <div class="actions">
      <a class="btn btn-secondaire" href="<?= h(url('export_xlsx', $qs)) ?>">Tableau Excel</a>
      <?php if ($st === 'valide'): ?><?= $action('payer', 'Marquer comme payé', 'btn-primaire') ?><?php endif; ?>
    </div>
    <?php if ($info['paye_at']): ?><p class="petit">Payé le <?= h(date('d/m/Y', strtotime($info['paye_at'] . ' UTC'))) ?>.</p><?php endif; ?>
  </div>

  <div class="etape <?= $st === 'transmis' ? 'fait' : '' ?>">
    <div class="etape-titre"><span class="etape-num">4</span> Transmettre au comptable</div>
    <p class="petit">Le ZIP contient un dossier par associé : sa fiche (Excel et PDF) et un PDF par ticket, lisible par Pennylane. Le lien « ZIP » du tableau ne prend qu'un associé. Le PDF complet regroupe tout en un seul fichier.</p>
    <div class="actions">
      <a class="btn btn-primaire" href="<?= h(url('export_zip', $qs)) ?>">ZIP pour Pennylane</a>
      <a class="btn btn-secondaire" href="<?= h(url('export_pdf', $qs)) ?>">PDF complet</a>
      <a class="btn btn-lien" href="<?= h(url('export_pdf', $qs + ['voir' => 1])) ?>" target="_blank">Aperçu</a>
      <?php if ($st === 'paye'): ?><?= $action('transmettre', 'Marquer comme transmis', 'btn-primaire') ?><?php endif; ?>
    </div>
    <?php if ($info['transmis_at']): ?><p class="petit">Transmis le <?= h(date('d/m/Y', strtotime($info['transmis_at'] . ' UTC'))) ?>.</p><?php endif; ?>
  </div>
</section>
