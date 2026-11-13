<?php
$page = 'fiche';
$extra = ['u' => $fiche['user']['id']];
require __DIR__ . '/_mois.php';
$t = $fiche['totaux'];
$ouvert = mois_ouvert($a, $m);
$estMoi = (int)$fiche['user']['id'] === (int)$user['id'];
$lienTicket = fn(array $d, string $texte): string =>
    '<a class="lien-ticket" href="' . h(url('depense', ['id' => $d['id'], 'retour' => 'fiche', 'ru' => $fiche['user']['id']])) . '">' . h($texte) . '</a>';
?>
<?php if (est_admin($user)): ?>
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
      if (in_array($j['motif'], ['conge', 'formation'], true)) {
          $classe = 'ligne-' . $j['motif'];
      }
      // Notes : repas du midi (supprime la prime) et dépenses CB entreprise, avec lien vers le ticket
      $notes = [];
      foreach ($j['repas_midi_par'] as $r) {
          $lib = (int)$r['equipe'] ? "Repas d'équipe" : 'Repas midi';
          $notes[] = $lienTicket($r, (int)$r['user_id'] === (int)$fiche['user']['id']
              ? $lib . ' ' . PAIEMENTS[$r['paiement']] . ' ' . euros($r['montant'])
              : $lib . ' réglé par ' . $r['payeur_prenom'] . ' (' . PAIEMENTS[$r['paiement']] . ')');
      }
      foreach ($j['cb_pro'] as $r) {
          if ($r['categorie'] !== 'repas' || !$r['repas_midi']) {
              $notes[] = $lienTicket($r, CATEGORIES[$r['categorie']] . ' CB entreprise ' . euros($r['montant']));
          }
      }
  ?>
    <tr class="<?= $classe ?>">
      <td class="nowrap"><?= h(date_fr($d, true)) ?></td>
      <td>
        <?php foreach ($j['etiquettes'] as $e): ?><span class="etiquette-jour"><?= h($e) ?></span> <?php endforeach; ?>
        <?= implode(' / ', array_map(fn($tk) => $lienTicket($tk, $tk['objet']), $j['tickets'])) ?>
        <?php if ($notes): ?><div class="note-ligne"><?= implode(' · ', $notes) ?></div><?php endif; ?>
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
<p class="aide"><span class="prime">P</span> prime repas de <?= euros($fiche['prime_montant']) ?> incluse dans la colonne Repas. Les dépenses payées avec la CB entreprise ne figurent pas dans les montants. Touchez un objet pour ouvrir le ticket.</p>

<section class="carte" id="conges">
  <h2 class="titre-section">Congés et formations<?= $estMoi ? '' : ' de ' . h($fiche['user']['prenom']) ?></h2>
  <p class="petit muted">Journées entières seulement (une demi-journée ne supprime pas la prime). Ces jours apparaissent sur la fiche et n'ont pas de prime repas.</p>
  <?php if ($periodes): ?>
    <ul class="rh-periodes">
    <?php foreach ($periodes as [$du, $au, $nb, $type]): ?>
      <li>
        <span><span class="etiquette-jour etiquette-<?= h($type) ?>"><?= $type === 'conge' ? 'Congé' : 'Formation' ?></span>
          <?= $du === $au ? 'le ' . h(date_fr($du, true)) : 'du ' . h(date_fr($du, true)) . ' au ' . h(date_fr($au, true)) ?>
          <span class="muted petit">(<?= $nb ?> j)</span></span>
        <?php if ($ouvert): ?>
        <form method="post" action="<?= h(url('absence_perso')) ?>" class="form-x" data-confirmer="Retirer cette période ?">
          <?= csrf_field() ?>
          <input type="hidden" name="u" value="<?= (int)$fiche['user']['id'] ?>"><input type="hidden" name="a" value="<?= $a ?>"><input type="hidden" name="m" value="<?= $m ?>">
          <input type="hidden" name="du" value="<?= h($du) ?>"><input type="hidden" name="au" value="<?= h($au) ?>">
          <input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="action" value="retirer">
          <button class="btn-x" aria-label="Retirer">×</button>
        </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="petit">Aucun congé ni formation ce mois-ci.</p>
  <?php endif; ?>

  <?php if ($ouvert):
      [$debutMois, $finMois] = mois_bornes($a, $m);
      $auj = aujourdhui();
      $debutMois = $auj >= $debutMois && $auj <= $finMois ? $auj : $debutMois; ?>
  <form method="post" action="<?= h(url('absence_perso')) ?>" class="form form-conge">
    <?= csrf_field() ?>
    <input type="hidden" name="u" value="<?= (int)$fiche['user']['id'] ?>"><input type="hidden" name="a" value="<?= $a ?>"><input type="hidden" name="m" value="<?= $m ?>">
    <div class="choix choix-2">
      <label class="puce"><input type="radio" name="type" value="conge" checked><span>Congé</span></label>
      <label class="puce"><input type="radio" name="type" value="formation"><span>Formation</span></label>
    </div>
    <div class="grille-2">
      <label>Du<input type="date" name="du" required value="<?= h($debutMois) ?>"></label>
      <label>Au <span class="muted petit">(inclus)</span><input type="date" name="au" value=""></label>
    </div>
    <p class="aide">Laissez « Au » vide pour une seule journée. Week-ends et jours fériés ne sont pas comptés.</p>
    <button class="btn btn-secondaire btn-plein">Ajouter</button>
  </form>
  <?php else: ?>
    <p class="alerte petit">Le mois est validé : les congés et formations ne peuvent plus être modifiés.</p>
  <?php endif; ?>
</section>

<?php if (est_admin($user)): ?>
  <p class="actions">
    <a class="btn btn-secondaire" href="<?= h(url('export_pdf', ['a' => $a, 'm' => $m, 'u' => $fiche['user']['id']])) ?>">PDF de cette fiche + justificatifs</a>
  </p>
<?php endif; ?>
