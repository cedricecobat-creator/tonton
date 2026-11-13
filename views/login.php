<div class="login-box">
  <img class="login-logo" src="icone-tonton.svg" alt="Logo Tonton" width="110" height="110">
  <h1 class="login-titre"><?= h(app_nom()) ?></h1>
  <p class="login-sous-titre">par (et pour) <strong>ecoBAT</strong></p>
  <?php if ($erreur): ?><p class="alerte alerte-erreur"><?= h($erreur) ?></p><?php endif; ?>
  <form method="post" action="<?= h(url('login')) ?>" class="form">
    <?= csrf_field() ?>
    <label>E-mail<input type="email" name="email" required autocomplete="username" autofocus value="<?= h($_POST['email'] ?? '') ?>"></label>
    <label>Mot de passe<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn btn-primaire btn-plein">Se connecter</button>
  </form>
  <p class="login-pied">Application interne de notes de frais.<br>Mot de passe oublié : demandez à l'administrateur.</p>
</div>
