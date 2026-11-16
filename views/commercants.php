<div class="entete-page">
  <a class="retour" href="<?= h(url('compte')) ?>">‹ Administration</a>
  <h1>Commerçants</h1>
  <a class="btn btn-primaire" href="<?= h(url('commercant')) ?>">+ Ajouter</a>
</div>
<p class="aide">Le carnet se remplit tout seul à chaque ticket enregistré. À la saisie d'un ticket, le commerçant est reconnu
  par son SIRET (le plus sûr) ou par son nom, et Tonton propose son nom, sa ville, la catégorie et la nature habituelles.
  Un commerçant modifié ici n'est plus changé par les tickets suivants.</p>

<form method="get" class="form-recherche">
  <input type="hidden" name="p" value="commercants">
  <input type="search" name="q" value="<?= h($rech) ?>" placeholder="Nom, ville ou SIRET" aria-label="Rechercher">
  <button class="btn btn-secondaire">Rechercher</button>
</form>
<p class="petit muted"><?= count($commercants) ?> commerçant(s) affiché(s)<?= $rech !== '' ? ' sur ' . $total : '' ?>.
  <?php if ($rech !== ''): ?><a href="<?= h(url('commercants')) ?>">Tout afficher</a><?php endif; ?></p>

<?php if ($commercants): ?>
<div class="table-defile">
<table class="tableau">
  <thead><tr><th>Commerçant</th><th>Ville</th><th>SIRET</th><th>Catégorie</th><th class="num">Tickets</th><th>Dernier</th></tr></thead>
  <tbody>
  <?php foreach ($commercants as $c): ?>
    <tr>
      <td><a href="<?= h(url('commercant', ['id' => $c['id']])) ?>"><?= h($c['nom']) ?></a><?= (int)$c['manuel'] ? ' <span class="badge" title="Modifié à la main">✎</span>' : '' ?></td>
      <td><?= h($c['ville']) ?></td>
      <td class="petit"><?= $c['siret'] ? h(trim(chunk_split(substr($c['siret'], 0, 9), 3, ' ')) . ' ' . substr($c['siret'], 9)) : '—' ?></td>
      <td class="petit"><?= h(CATEGORIES[$c['categorie'] ?? ''] ?? '—') ?></td>
      <td class="num"><?= (int)$c['nb'] ?></td>
      <td class="petit"><?= $c['dernier'] ? h(date_fr($c['dernier'])) : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php else: ?>
<p class="carte muted">Aucun commerçant<?= $rech !== '' ? ' ne correspond à cette recherche' : ' pour l\'instant : le carnet se remplira avec les prochains tickets' ?>.</p>
<?php endif; ?>
