<?php $id = (int)$c['id']; ?>
<div class="entete-page">
  <a class="retour" href="<?= h(url('commercants')) ?>">‹ Commerçants</a>
  <h1><?= $id ? h($c['nom']) : 'Nouveau commerçant' ?></h1>
</div>

<form method="post" action="<?= h(url('commercant_save')) ?>" class="form carte">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <label>Nom du commerçant <span class="obligatoire">*</span>
    <input name="nom" maxlength="255" required value="<?= h($c['nom']) ?>" placeholder="Ex. : Brasserie du Port"></label>
  <label>Ville<input name="ville" maxlength="120" value="<?= h($c['ville']) ?>" placeholder="Ex. : BAYONNE"></label>
  <label>SIRET <span class="muted">(14 chiffres : reconnaissance automatique la plus sûre)</span>
    <input name="siret" inputmode="numeric" maxlength="17" value="<?= h((string)$c['siret']) ?>" placeholder="123 456 789 00012"></label>
  <label>Catégorie habituelle
    <select name="categorie">
      <option value="">— aucune —</option>
      <?php foreach (CATEGORIES as $k => $lib): if ($k === 'km') continue; ?>
        <option value="<?= $k ?>" <?= ($c['categorie'] ?? '') === $k ? 'selected' : '' ?>><?= h($lib) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Nature habituelle <span class="muted">(facultatif)</span>
    <input name="objet" maxlength="255" value="<?= h($c['objet']) ?>" placeholder="Ex. : Carburant véhicule"></label>
  <?php if ($id): ?>
    <p class="petit muted"><?= (int)$c['nb'] ?> ticket(s)<?= $c['dernier'] ? ', dernier le ' . h(date_fr($c['dernier'])) : '' ?>.
      <?= (int)$c['manuel'] ? 'Modifié à la main : les tickets suivants ne changent plus ces informations.' : 'Appris automatiquement à partir des tickets.' ?></p>
  <?php endif; ?>
  <button class="btn btn-primaire btn-plein">Enregistrer</button>
</form>

<?php if ($id): ?>
<form method="post" action="<?= h(url('commercant_suppr')) ?>" class="form-suppr" data-confirmer="Supprimer « <?= h($c['nom']) ?> » du carnet ? Les tickets déjà enregistrés ne sont pas modifiés.">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <button class="btn btn-lien btn-danger">Supprimer ce commerçant</button>
</form>
<p class="aide centre">Les tickets déjà enregistrés ne changent pas. S'il revient sur un nouveau ticket, il sera de nouveau appris.</p>
<?php endif; ?>
