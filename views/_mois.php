<?php
/** Sélecteur de mois : variables $a, $m, $page, $extra (paramètres supplémentaires) */
[[$pa, $pm], [$na, $nm]] = mois_voisins($a, $m);
$extra ??= [];
$statut = mois_info($a, $m)['statut'];
?>
<div class="mois-nav">
  <?php if (avant_tonton($pa, $pm)): ?>
  <span class="mois-fleche mois-fleche-off" title="Tonton démarre en <?= h(mois_libelle($a, $m)) ?>" aria-hidden="true">‹</span>
  <?php else: ?>
  <a class="mois-fleche" href="<?= h(url($page, ['a' => $pa, 'm' => $pm] + $extra)) ?>" aria-label="Mois précédent">‹</a>
  <?php endif; ?>
  <div class="mois-centre">
    <span class="mois-nom"><?= h(ucfirst(mois_libelle($a, $m))) ?></span>
    <span class="pastille pastille-<?= h($statut) ?>"><?= h(STATUTS[$statut]) ?></span>
  </div>
  <a class="mois-fleche" href="<?= h(url($page, ['a' => $na, 'm' => $nm] + $extra)) ?>" aria-label="Mois suivant">›</a>
</div>
