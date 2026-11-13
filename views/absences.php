<?php
$page = 'absences';
require __DIR__ . '/_mois.php';
$ouvert = $info['statut'] === 'ouvert';
$feries = feries($a);
$jours = [];
for ($j = 1; $j <= $nb; $j++) {
    $d = sprintf('%04d-%02d-%02d', $a, $m, $j);
    if ((int)date('N', strtotime($d)) <= 5) {
        $jours[] = $d;
    }
}
?>
<div class="entete-page">
  <a class="retour" href="<?= h(url('gestion', ['a' => $a, 'm' => $m])) ?>">‹ Gestion</a>
  <h1>Congés et formations</h1>
</div>
<p class="muted">Seules les journées entières comptent : une demi-journée de congé ne supprime pas la prime repas. Touchez une case pour passer de « présent » à « congé », puis « formation ».</p>

<div class="legende">
  <span><i class="case-abs"></i> Présent</span>
  <span><i class="case-abs conge"></i> Congé</span>
  <span><i class="case-abs formation"></i> Formation</span>
  <span><i class="case-abs ferie"></i> Férié</span>
</div>

<div class="table-defile">
<table class="grille-abs" data-modifiable="<?= $ouvert ? '1' : '0' ?>">
  <thead>
    <tr><th></th>
    <?php foreach ($jours as $d): ?>
      <th class="<?= isset($feries[$d]) ? 'ferie' : '' ?>"><span class="petit"><?= h(mb_substr(JOURS_FR[(int)date('N', strtotime($d))], 0, 1)) ?></span><br><?= (int)substr($d, 8) ?></th>
    <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($associes as $as): $uid = (int)$as['id']; ?>
    <tr>
      <th class="nowrap"><?= h($as['prenom']) ?></th>
      <?php foreach ($jours as $d):
          if (isset($feries[$d])): ?>
          <td><span class="case-abs ferie" title="<?= h($feries[$d]) ?>"></span></td>
        <?php else: $t = $abs[$uid][$d] ?? ''; ?>
          <td><button type="button" class="case-abs <?= h($t) ?>" data-user="<?= $uid ?>" data-date="<?= h($d) ?>" data-type="<?= h($t) ?>"
            title="<?= h($as['prenom'] . ' · ' . date_fr($d, true)) ?>" <?= $ouvert ? '' : 'disabled' ?>></button></td>
        <?php endif; ?>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (!$ouvert): ?><p class="alerte">Le mois est validé : rouvrez-le depuis la page Gestion pour modifier les absences.</p><?php endif; ?>

<section class="carte">
  <h2 class="titre-section">Importer le fichier d'Odoo</h2>
  <form method="post" action="<?= h(url('absences_import')) ?>" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?><input type="hidden" name="a" value="<?= $a ?>"><input type="hidden" name="m" value="<?= $m ?>">
    <input type="file" name="fichier" accept=".csv,text/csv,text/plain" required>
    <button class="btn btn-primaire">Importer</button>
  </form>
  <details class="petit">
    <summary>Format attendu du fichier (CSV)</summary>
    <p>Une ligne d'en-tête, puis une ligne par congé ou formation. Séparateur point-virgule ou virgule.</p>
<pre>personne;date_debut;date_fin;type;duree
fabien@exemple.fr;14/09/2026;18/09/2026;Congés payés;5
DP;23/09/2026;;Formation;1
Fabien Coignard;25/09/2026;;Congé;0,5</pre>
    <p>« personne » : e-mail, initiales ou prénom et nom. « date_fin » et « duree » sont facultatives. Les lignes de moins d'une journée, les week-ends et les jours fériés sont ignorés.</p>
  </details>
</section>
