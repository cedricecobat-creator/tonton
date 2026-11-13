<div class="entete-page">
  <h1>Utilisateurs</h1>
  <a class="btn btn-primaire" href="<?= h(url('utilisateur')) ?>">+ Ajouter</a>
</div>
<div class="table-defile">
<table class="tableau">
  <thead><tr><th>Nom</th><th>Initiales</th><th>E-mail</th><th>Rôle</th><th class="num">€/km</th><th>Dernière connexion</th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr class="<?= (int)$u['actif'] ? '' : 'inactif' ?>">
      <td><a href="<?= h(url('utilisateur', ['id' => $u['id']])) ?>"><?= h(nom_complet($u)) ?></a><?= (int)$u['actif'] ? '' : ' <span class="badge">désactivé</span>' ?></td>
      <td><?= h($u['initiales']) ?></td>
      <td><?= h($u['email']) ?></td>
      <td><span class="badge badge-role-<?= h($u['role']) ?>"><?= h(ROLES[$u['role']] ?? $u['role']) ?></span><?= (int)$u['admin_fiche'] ? ' <span class="petit muted">+ fiche</span>' : '' ?></td>
      <td class="num"><?= h(nombre_fr($u['coef_km'], 3)) ?></td>
      <td class="petit"><?= $u['last_login'] ? h(date('d/m/Y H:i', strtotime($u['last_login'] . ' UTC'))) : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="aide">Associé : fiche mensuelle avec prime repas. Salarié : fiche mensuelle RH (phase 2). Administrateur : gère les comptes, valide les mois et fait les exports.</p>

<section class="carte" id="membres">
  <h2 class="titre-section">Membres de l'équipe (participants aux repas)</h2>
  <p class="petit muted">Liste proposée lors de la saisie d'un repas. La personne qui saisit apparaît en premier, puis les associés, puis les autres membres selon l'ordre ci-dessous. Un membre relié à un compte associé perd sa prime repas du jour quand il est coché sur un repas du midi. Un membre est relié automatiquement au compte qui a les mêmes initiales.</p>
  <div class="ligne-membre entete-membre petit muted"><span>Prénom</span><span>Initiales</span><span>Ordre</span><span></span><span>Compte</span><span></span></div>
  <?php foreach ($membres as $mb): ?>
    <form method="post" action="<?= h(url('membre_save')) ?>" class="ligne-membre <?= (int)$mb['actif'] ? '' : 'inactif' ?>">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$mb['id'] ?>">
      <input name="prenom" value="<?= h($mb['prenom']) ?>" aria-label="Prénom" required>
      <input name="initiales" value="<?= h($mb['initiales']) ?>" aria-label="Initiales" maxlength="6" required>
      <input name="ordre" value="<?= (int)$mb['ordre'] ?>" aria-label="Ordre" inputmode="numeric">
      <label class="case"><input type="checkbox" name="actif" value="1" <?= (int)$mb['actif'] ? 'checked' : '' ?>> actif</label>
      <span class="petit muted"><?= $mb['user_id'] ? h(ROLES[$mb['role']] ?? 'compte') : '—' ?></span>
      <button class="btn btn-lien">Enregistrer</button>
    </form>
  <?php endforeach; ?>
  <p class="petit muted" style="margin:.8rem 0 .2rem">Ajouter un membre :</p>
  <form method="post" action="<?= h(url('membre_save')) ?>" class="ligne-membre">
    <?= csrf_field() ?><input type="hidden" name="id" value="0">
    <input name="prenom" placeholder="Prénom" required>
    <input name="initiales" placeholder="Initiales" maxlength="6" required>
    <input name="ordre" placeholder="Ordre" inputmode="numeric">
    <button class="btn btn-secondaire">Ajouter un membre</button>
  </form>
</section>
