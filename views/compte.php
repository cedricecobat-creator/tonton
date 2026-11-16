<div class="entete-page"><h1><?= est_admin($user) ? 'Administration' : 'Mon compte' ?></h1></div>
<section class="carte">
  <p><strong><?= h(nom_complet($user)) ?></strong> · <span class="badge badge-role-<?= h($user['role']) ?>"><?= h(ROLES[$user['role']] ?? '') ?></span><br><span class="muted"><?= h($user['email']) ?></span></p>
  <p class="petit muted">Pour installer Tonton sur votre téléphone : dans Safari (iPhone), bouton Partager puis « Sur l'écran d'accueil » ; dans Chrome (Android), menu ⋮ puis « Ajouter à l'écran d'accueil ».</p>
</section>

<?php if (est_admin($user)): ?>
<section class="carte menu-admin">
  <h2 class="titre-section">Outils</h2>
  <a class="carte-lien" href="<?= h(url('utilisateurs')) ?>"><span><strong>Utilisateurs et membres</strong><br><span class="petit muted">Comptes, rôles, barème km, liste des participants aux repas</span></span><span aria-hidden="true">›</span></a>
  <a class="carte-lien" href="<?= h(url('absences')) ?>"><span><strong>Congés et formations</strong><br><span class="petit muted">Calendrier et import du fichier Odoo</span></span><span aria-hidden="true">›</span></a>
  <a class="carte-lien" href="<?= h(url('commercants')) ?>"><span><strong>Commerçants</strong><br><span class="petit muted">Carnet utilisé pour reconnaître les tickets (nom, ville, SIRET)</span></span><span aria-hidden="true">›</span></a>
  <a class="carte-lien" href="<?= h(url('journal')) ?>"><span><strong>Journal</strong><br><span class="petit muted">Historique des actions</span></span><span aria-hidden="true">›</span></a>
  <a class="carte-lien" href="<?= h(url('diagnostic')) ?>"><span><strong>Diagnostic</strong><br><span class="petit muted">Vérification de l'hébergement et de la configuration</span></span><span aria-hidden="true">›</span></a>
</section>
<?php endif; ?>

<form method="post" action="<?= h(url('compte_paiement')) ?>" class="form carte">
  <?= csrf_field() ?>
  <h2 class="titre-section">Moyen de paiement par défaut</h2>
  <p class="petit muted">Proposé automatiquement sur chaque nouveau ticket (modifiable ticket par ticket).</p>
  <div class="choix choix-3">
      <?php foreach (PAIEMENTS_DEFAUT as $pm): ?>
        <label class="puce puce-<?= $pm === 'cb_pro' ? 'pro' : 'perso' ?>"><input type="radio" name="paiement_defaut" value="<?= $pm ?>" <?= paiement_defaut($user) === $pm ? 'checked' : '' ?>><span><?= h(PAIEMENTS[$pm]) ?></span></label>
      <?php endforeach; ?>
    </div>
  <button class="btn btn-secondaire btn-plein">Enregistrer</button>
</form>

<form method="post" action="<?= h(url('compte')) ?>" class="form carte">
  <?= csrf_field() ?>
  <h2 class="titre-section">Changer de mot de passe</h2>
  <label>Mot de passe actuel<input type="password" name="actuel" required autocomplete="current-password"></label>
  <label>Nouveau mot de passe (10 caractères minimum)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
  <label>Confirmer<input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>
  <button class="btn btn-primaire btn-plein">Enregistrer</button>
</form>
<form method="post" action="<?= h(url('logout')) ?>" class="centre">
  <?= csrf_field() ?><button class="btn btn-lien btn-danger">Se déconnecter</button>
</form>
<p class="centre petit muted">Tonton <?= h(APP_VERSION) ?></p>
