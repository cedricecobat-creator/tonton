<div class="entete-page">
  <a class="retour" href="<?= h(url('compte')) ?>">‹ Administration</a>
  <h1>Diagnostic</h1>
</div>
<?php $nbErr = count(array_filter($checks, fn($c) => $c[1] === 'erreur')); ?>
<p class="alerte <?= $nbErr ? 'alerte-erreur' : 'alerte-ok' ?>">
  <?= $nbErr ? $nbErr . ' point(s) à corriger.' : 'Tout est en ordre.' ?>
</p>
<div class="table-defile">
<table class="tableau">
  <thead><tr><th>Vérification</th><th>État</th><th>Détail</th></tr></thead>
  <tbody>
  <?php foreach ($checks as [$lib, $etat, $detail]): ?>
    <tr>
      <td><?= h($lib) ?></td>
      <td><span class="badge badge-etat-<?= h($etat) ?>"><?= ['ok' => 'OK', 'attention' => 'À vérifier', 'erreur' => 'Problème'][$etat] ?></span></td>
      <td class="petit"><?= h($detail) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
