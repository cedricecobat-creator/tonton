<?php $page = 'suivi'; ?>
<div class="entete-page"><h1>Suivi des salariés</h1></div>

<section class="carte">
  <h2 class="titre-section">Remboursements à faire <span class="muted">(<?= count($remb) ?>)</span></h2>
  <?php if (!$remb): ?>
    <p class="vide petit">Aucune demande de remboursement en attente.</p>
  <?php else: ?>
    <p class="aide">Demandes « dès que possible ». Une fois le virement fait, cliquez sur « Remboursé » : le salarié est prévenu par e-mail.</p>
    <?php foreach ($remb as $d): ?>
      <div class="ligne-suivi">
        <div>
          <strong><?= h($d['payeur_prenom'] . ' ' . $d['payeur_nom']) ?></strong> · <?= euros($d['montant']) ?>
          <div class="note-ligne"><?= h(date_fr($d['date_depense'])) ?> · <a href="<?= h(url('depense', ['id' => $d['id']])) ?>"><?= h(lieu_complet($d) ?: $d['objet']) ?></a> · <?= h(PAIEMENTS[$d['paiement']]) ?></div>
        </div>
        <form method="post" action="<?= h(url('remb_fait')) ?>" data-confirmer="Confirmer le remboursement de <?= h(strip_tags(euros($d['montant']))) ?> à <?= h($d['payeur_prenom']) ?> ?">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <input name="remb_note" placeholder="Note (ex. : virement du 23/10)" maxlength="255" aria-label="Note">
          <button class="btn btn-primaire">Remboursé</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php if ($suivis): ?>
<?php require __DIR__ . '/_mois.php'; ?>
<section class="carte">
  <h2 class="titre-section">Fiches RH de <?= h(mois_libelle($a, $m)) ?></h2>
  <div class="table-defile">
  <table class="tableau">
    <thead><tr><th>Salarié</th><th>État</th><th class="num">Frais avec salaire</th><th class="num">Heures sup</th><th class="num">Congés</th><th>Primes</th></tr></thead>
    <tbody>
    <?php foreach ($suivis as $f): $i = $f['info']; ?>
      <tr>
        <td><a href="<?= h(url('fiche_rh', ['u' => $f['user']['id'], 'a' => $a, 'm' => $m])) ?>"><?= h(nom_complet($f['user'])) ?></a></td>
        <td><?= $i['valide_resp_at'] ? '<span class="badge badge-ok">validée</span>' : ($i['valide_collab_at'] ? '<span class="badge badge-alerte">à valider</span>' : '<span class="badge">en cours</span>') ?></td>
        <td class="num"><?= euros($f['totaux']['paie']) ?></td>
        <td class="num"><?= h(nombre_fr($f['total_heures'])) ?> h</td>
        <td class="num"><?= array_sum(array_column($f['conges'], 2)) ?> j</td>
        <td class="petit"><?= implode(', ', array_filter([(int)$i['prime_qualite'] ? 'qualité' : '', (int)$i['prime_contributions'] ? 'contributions' : ''])) ?: '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<?php if ($faits): ?>
<section class="carte">
  <h2 class="titre-section">Derniers remboursements effectués</h2>
  <?php foreach ($faits as $d): ?>
    <div class="ligne-suivi">
      <div><?= h($d['payeur_prenom'] . ' ' . $d['payeur_nom']) ?> · <?= euros($d['montant']) ?>
        <div class="note-ligne">Ticket du <?= h(date_fr($d['date_depense'])) ?> · remboursé le <?= h(date('d/m/Y', strtotime($d['remb_le'] . ' UTC'))) ?><?= $d['remb_note'] ? ' · ' . h($d['remb_note']) : '' ?></div>
      </div>
      <form method="post" action="<?= h(url('remb_fait')) ?>" data-confirmer="Remettre ce remboursement « à faire » ?">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="annuler" value="1">
        <button class="btn btn-lien">Annuler</button>
      </form>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>
