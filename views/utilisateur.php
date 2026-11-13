<?php
$id = (int)$cible['id'];
$roles = module_salaries() || $cible['role'] === 'salarie' ? ROLES : array_diff_key(ROLES, ['salarie' => 1]);
$descriptions = [
    'admin'   => 'Gère les comptes, valide les mois, fait les exports. Pas de fiche.',
    'associe' => 'Saisit ses tickets. Fiche mensuelle avec prime repas.',
    'salarie' => 'Saisit ses tickets. Fiche mensuelle RH, jamais de prime repas.',
];
$assoc = associes();
?>
<div class="entete-page">
  <a class="retour" href="<?= h(url('utilisateurs')) ?>">‹ Utilisateurs</a>
  <h1><?= $id ? h(nom_complet($cible)) : 'Nouvel utilisateur' ?></h1>
</div>
<form method="post" action="<?= h(url('utilisateur_save')) ?>" class="form carte">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <div class="grille-2">
    <label>Prénom<input name="prenom" required value="<?= h($cible['prenom']) ?>"></label>
    <label>Nom<input name="nom" required value="<?= h($cible['nom']) ?>"></label>
  </div>
  <div class="grille-2">
    <label>Initiales<input name="initiales" required maxlength="6" value="<?= h($cible['initiales']) ?>"></label>
    <label>Barème km (€/km)<input name="coef_km" inputmode="decimal" value="<?= h(nombre_fr($cible['coef_km'], 3)) ?>"></label>
  </div>
  <label>E-mail (identifiant de connexion)<input type="email" name="email" required value="<?= h($cible['email']) ?>"></label>

  <div class="champ">
    <span class="champ-titre">Rôle</span>
    <div class="choix choix-roles">
      <?php foreach ($roles as $r => $lib): ?>
        <label class="puce"><input type="radio" name="role" value="<?= h($r) ?>" <?= $cible['role'] === $r ? 'checked' : '' ?>>
          <span><strong><?= h($lib) ?></strong><small><?= h($descriptions[$r]) ?></small></span></label>
      <?php endforeach; ?>
    </div>
    <?php if (!module_salaries()): ?><p class="aide">Le rôle « Salarié » sera proposé quand le module salariés sera activé (phase 2).</p><?php endif; ?>
  </div>

  <?php if (module_salaries()): ?>
  <label class="case" data-si-role="admin"><input type="checkbox" name="admin_fiche" value="1" <?= (int)$cible['admin_fiche'] ? 'checked' : '' ?>>
    L'administrateur a aussi une fiche salarié <span class="muted petit">(validée par un associé)</span></label>

  <div class="bloc-employe" data-si-fiche-rh>
    <label>Associé responsable (valide la fiche RH)
      <select name="responsable_id">
        <option value="">— à choisir —</option>
        <?php foreach ($assoc as $as): ?>
          <option value="<?= (int)$as['id'] ?>" <?= (int)($cible['responsable_id'] ?? 0) === (int)$as['id'] ? 'selected' : '' ?>><?= h(nom_complet($as)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Associé qui effectue les remboursements (prévenu par e-mail)
      <select name="rembourseur_id">
        <option value="">— à choisir —</option>
        <?php foreach ($assoc as $as): ?>
          <option value="<?= (int)$as['id'] ?>" <?= (int)($cible['rembourseur_id'] ?? 0) === (int)$as['id'] ? 'selected' : '' ?>><?= h(nom_complet($as)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <?php endif; ?>

  <label class="case"><input type="checkbox" name="actif" value="1" <?= (int)$cible['actif'] ? 'checked' : '' ?>> Compte actif</label>
  <label><?= $id ? 'Nouveau mot de passe (laisser vide pour ne pas changer)' : 'Mot de passe (10 caractères minimum)' ?>
    <input type="text" name="password" autocomplete="new-password" <?= $id ? '' : 'required minlength="10"' ?>>
  </label>
  <p class="aide">Communiquez le mot de passe à la personne : elle pourra le changer depuis « Mon compte ».</p>
  <button class="btn btn-primaire btn-plein">Enregistrer</button>
</form>
<script src="assets/utilisateur.js?v=<?= APP_VERSION ?>" defer></script>
