<?php $ent = entreprise(); ?>
<div class="siren-page">
  <p class="muted">Merci d'établir la facture au nom de :</p>
  <p class="siren-nom"><?= h($ent['nom']) ?></p>
  <?php if ($ent['siren']): ?>
    <p class="siren-num">SIREN <strong><?= h($ent['siren_affiche']) ?></strong></p>
  <?php endif; ?>
  <?php if ($ent['tva']): ?><p class="siren-tva">N° TVA <strong><?= h($ent['tva']) ?></strong></p><?php endif; ?>
  <?php if ($ent['adresse']): ?><p><?= nl2br(h($ent['adresse'])) ?></p><?php endif; ?>
  <?php if ($ent['adresse_fe']): ?><p class="petit">Adresse de facturation électronique : <?= h($ent['adresse_fe']) ?></p><?php endif; ?>
  <p class="aide">Pensez ensuite à cocher « Facture électronique demandée » sur le ticket.</p>
</div>
