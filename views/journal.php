<?php
$libelles = [
    'connexion' => 'Connexion', 'depense_ajout' => 'Ticket ajouté', 'depense_modif' => 'Ticket modifié',
    'depense_suppr' => 'Ticket supprimé', 'mois_termine' => 'Saisie terminée', 'mois_valider' => 'Mois validé',
    'mois_rouvrir' => 'Mois rouvert', 'mois_payer' => 'Mois payé', 'mois_transmettre' => 'Mois transmis',
    'prime_montant' => 'Prime repas modifiée', 'absence' => 'Absence', 'absences_import' => 'Import Odoo',
    'user_ajout' => 'Utilisateur ajouté', 'user_modif' => 'Utilisateur modifié', 'membre_ajout' => 'Membre ajouté',
    'membre_modif' => 'Membre modifié', 'mot_de_passe' => 'Mot de passe changé', 'installation' => 'Installation',
];
$pages = max(1, (int)ceil($total_j / $par_page));
?>
<div class="entete-page">
  <a class="retour" href="<?= h(url('compte')) ?>">‹ Administration</a>
  <h1>Journal</h1>
</div>
<p class="muted petit">Toutes les actions importantes, de la plus récente à la plus ancienne (heure de Paris).</p>
<div class="table-defile">
<table class="tableau">
  <thead><tr><th>Date</th><th>Qui</th><th>Action</th><th>Détail</th></tr></thead>
  <tbody>
  <?php foreach ($lignes as $l): ?>
    <tr>
      <td class="nowrap petit"><?= h((new DateTimeImmutable($l['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(cfg('timezone') ?? 'Europe/Paris'))->format('d/m/Y H:i')) ?></td>
      <td class="nowrap"><?= h($l['prenom'] ? nom_complet($l) : '—') ?></td>
      <td class="nowrap"><?= h($libelles[$l['action']] ?? $l['action']) ?></td>
      <td class="petit"><?= h($l['detail']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$lignes): ?><tr><td colspan="4" class="muted">Aucune action enregistrée.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<p class="actions">
  <?php if ($page_j > 1): ?><a class="btn btn-secondaire" href="<?= h(url('journal', ['page' => $page_j - 1])) ?>">‹ Plus récent</a><?php endif; ?>
  <span class="muted petit">Page <?= $page_j ?> / <?= $pages ?></span>
  <?php if ($page_j < $pages): ?><a class="btn btn-secondaire" href="<?= h(url('journal', ['page' => $page_j + 1])) ?>">Plus ancien ›</a><?php endif; ?>
</p>
<?php endif; ?>
