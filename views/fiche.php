<?php
$page = 'fiche';
$extra = ['u' => $fiche['user']['id']];
require __DIR__ . '/_mois.php';
$t = $fiche['totaux'];
$motifs = ['weekend' => '', 'ferie' => 'Férié', 'conge' => 'Congé', 'formation' => 'Formation', 'a_venir' => 'À venir'];
?>
<?php if ($user['role'] === 'admin'): ?>
  <div class="onglets">
    <?php foreach (associes() as $as): ?>
      <a class="<?= (int)$as['id'] === (int)$fiche['user']['id'] ? 'actif' : '' ?>" href="<?= h(url('fiche', ['a' => $a, 'm' => $m, 'u' => $as['id']])) ?>"><?= h($as['prenom']) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<section class="carte">
  <div class="fiche-entete">
    <div>
      <h1 class="fiche-nom"><?= h(mb_strtoupper(nom_complet($fiche['user']))) ?></h1>
      <p class="muted petit">Fiche de <?= h(mois_libelle($a, $m)) ?><?= $fiche['definitif'] ? '' : ' · provisoire, primes calculées jusqu\'à aujourd\'hui' ?></p>
    </div>
    <div class="fiche-total">
      <span class="muted petit">Montant à régler</span>
      <strong><?= euros($t['total']) ?></strong>
    </div>
  </div>
  <p class="petit">
    Avances CB perso <?= euros($fiche['total_avances']) ?> ·
    Frais km <?= euros($t['total_kms']) ?> ·
    Primes repas <?= $fiche['nb_primes'] ?> × <?= euros($fiche['prime_montant']) ?> = <?= euros($fiche['total_primes']) ?>
  </p>
</section>

<div class="table-defile">
<table class="fiche">
  <thead>
    <tr><th>Date</th><th>Objet</th><th>Lieu</th><th>Kms</th><th>Coef</th><th>Total frais Kms</th><th>Péage Station&shy;nement</th><th>Repas</th><th>Avance carburant</th><th>Autres</th><th>Total</th></tr>
  </thead>
  <tbody>
  <?php foreach ($fiche['jours'] as $d => $j):
      $classe = $j['jour_semaine'] >= 6 || $j['motif'] === 'ferie' ? 'ligne-grise' : '';
      $notes = [];
      foreach ($j['repas_midi_par'] as $r) {
          $lib = (int)$r['equipe'] ? "Repas d'équipe" : 'Repas midi';
          $notes[] = (int)$r['user_id'] === (int)$fiche['user']['id']
              ? $lib . ' ' . PAIEMENTS[$r['paiement']] . ' ' . euros($r['montant'])
              : $lib . ' réglé par ' . $r['payeur_prenom'] . ' (' . PAIEMENTS[$r['paiement']] . ')';
      }
      foreach ($j['cb_pro'] as $r) {
          if ($r['categorie'] !== 'repas' || !$r['repas_midi']) {
              $notes[] = CATEGORIES[$r['categorie']] . ' CB entreprise ' . euros($r['montant']);
          }
      }
  ?>
    <tr class="<?= $classe ?>">
      <td class="nowrap"><?= h(date_fr($d, true)) ?></td>
      <td>
        <?= h(implode(' / ', $j['objets'])) ?>
        <?php if ($notes): ?><div class="note-ligne"><?= h(implode(' · ', $notes)) ?></div><?php endif; ?>
        <?php if ($j['motif'] === 'a_venir'): ?><div class="note-ligne">À venir</div><?php endif; ?>
      </td>
      <td><?= h(implode(' / ', array_unique($j['lieux']))) ?></td>
      <td class="num"><?= $j['kms'] ? h(nombre_fr($j['kms'], 0)) : '' ?></td>
      <td class="num"><?= $j['coef'] ? h(nombre_fr($j['coef'], 3)) : '' ?></td>
      <td class="num"><?= euros($j['total_kms'], true) ?></td>
      <td class="num"><?= euros($j['peage'], true) ?></td>
      <td class="num"><?= euros($j['repas'], true) ?><?= $j['prime'] ? '<span class="prime" title="Prime repas">P</span>' : '' ?></td>
      <td class="num"><?= euros($j['carburant'], true) ?></td>
      <td class="num"><?= euros($j['autres'], true) ?></td>
      <td class="num fort"><?= euros($j['total'], true) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr>
      <th colspan="3">TOTAL</th>
      <td class="num"><?= $t['kms'] ? h(nombre_fr($t['kms'], 0)) : '' ?></td>
      <td class="num"><?= h(nombre_fr($fiche['coef_km'], 3)) ?></td>
      <td class="num"><?= euros($t['total_kms']) ?></td>
      <td class="num"><?= euros($t['peage']) ?></td>
      <td class="num"><?= euros($t['repas']) ?></td>
      <td class="num"><?= euros($t['carburant']) ?></td>
      <td class="num"><?= euros($t['autres']) ?></td>
      <td class="num fort"><?= euros($t['total']) ?></td>
    </tr>
  </tfoot>
</table>
</div>
<p class="aide"><span class="prime">P</span> prime repas de <?= euros($fiche['prime_montant']) ?> incluse dans la colonne Repas. Les dépenses payées avec la CB entreprise ne figurent pas dans les montants.</p>

<?php if ($user['role'] === 'admin'): ?>
  <p class="actions">
    <a class="btn btn-secondaire" href="<?= h(url('export_pdf', ['a' => $a, 'm' => $m, 'u' => $fiche['user']['id']])) ?>">PDF de cette fiche + justificatifs</a>
  </p>
<?php endif; ?>
