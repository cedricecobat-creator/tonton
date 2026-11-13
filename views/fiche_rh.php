<?php
$page = 'fiche_rh';
$emp = $frh['user'];
$extra = ['u' => $emp['id']];
require __DIR__ . '/_mois.php';
$info = $frh['info'];
$s = $droits['saisir'];
$t = $frh['totaux'];
[, $finMois] = mois_bornes($a, $m);
$cache = function (string $action, array $champs = []) use ($a, $m, $emp): string {
    $h = csrf_field() . '<input type="hidden" name="a" value="' . $a . '"><input type="hidden" name="m" value="' . $m . '">'
        . '<input type="hidden" name="u" value="' . (int)$emp['id'] . '"><input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($champs as $k => $v) {
        $h .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    }
    return $h;
};
$suppr = fn(string $action, array $champs) => '<form method="post" action="' . h(url('fiche_rh_action')) . '" class="form-x" data-confirmer="Supprimer cette ligne ?">'
    . $cache($action, $champs) . '<button class="btn-x" title="Supprimer" aria-label="Supprimer">×</button></form>';
$action = h(url('fiche_rh_action'));
$fmtDate = fn($ts) => $ts ? date('d/m/Y à H:i', strtotime($ts . ' UTC')) : '';
?>

<?php if (count($suivis) > 1): ?>
  <div class="onglets">
    <?php foreach ($suivis as $sv): ?>
      <a class="<?= (int)$sv['id'] === (int)$emp['id'] ? 'actif' : '' ?>" href="<?= h(url('fiche_rh', ['u' => $sv['id'], 'a' => $a, 'm' => $m])) ?>"><?= h($sv['prenom']) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<section class="carte rh-entete">
  <div>
    <p class="rh-titre">FICHE MENSUELLE RH</p>
    <h1 class="fiche-nom"><?= h(mb_strtoupper($emp['nom'])) ?> <?= h($emp['prenom']) ?></h1>
    <p class="petit muted">
      <?php if ($frh['responsable']): ?>À valider par <?= h($frh['responsable']['prenom']) ?> au plus tard le <?= h(date_fr($finMois)) ?>.<?php else: ?>Aucun responsable désigné : à régler dans « Utilisateurs ».<?php endif; ?>
    </p>
  </div>
  <div class="rh-statut">
    <?php if ($info['valide_resp_at']): ?>
      <span class="badge badge-ok">Validée par <?= h($frh['valide_par']['prenom'] ?? '') ?></span>
    <?php elseif ($info['valide_collab_at']): ?>
      <span class="badge badge-facture">En attente du responsable</span>
    <?php else: ?>
      <span class="badge">En cours de saisie</span>
    <?php endif; ?>
  </div>
</section>

<!-- NOTE DE FRAIS -->
<section class="carte rh-section">
  <h2 class="rh-h">Note de frais <span>(remboursement collaborateur)</span></h2>
  <p class="aide">Seuls les frais avancés par vous (carte perso, espèces) figurent ici. Les tickets sont ajoutés avec le bouton « + » et joints à la fiche.</p>
  <?php if ($frh['frais']): ?>
  <div class="table-defile">
  <table class="tableau">
    <thead><tr><th>Date et description</th><th class="num">Montant TTC</th></tr></thead>
    <tbody>
    <?php foreach ($frh['frais'] as $d): ?>
      <tr>
        <td><span class="nowrap"><?= h(date_fr($d['date_depense'])) ?></span> · <a href="<?= h(url('depense', ['id' => $d['id']])) ?>"><?= h(lieu_complet($d)) ?></a>
          <div class="note-ligne"><?= h($d['objet']) ?> · <?= h(PAIEMENTS[$d['paiement']]) ?></div>
          <?php if (($d['remb_mode'] ?? '') === 'immediat'): ?>
            <?= ($d['remb_statut'] ?? '') === 'fait' ? '<span class="badge badge-ok">remboursé le ' . h(date('d/m', strtotime($d['remb_le'] . ' UTC'))) . '</span>' : '<span class="badge badge-alerte">dès que possible</span>' ?>
          <?php else: ?>
            <span class="badge">avec le salaire</span>
          <?php endif; ?>
        </td>
        <td class="num"><?= euros($d['montant']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th>Total</th><td class="num"><?= euros($t['frais']) ?></td></tr></tfoot>
  </table>
  </div>
  <p class="petit">À rembourser avec le salaire : <strong><?= euros($t['paie']) ?></strong>
    <?php if ($t['attente'] > 0): ?> · en attente de virement : <strong><?= euros($t['attente']) ?></strong><?php endif; ?>
    <?php if ($t['rembourse'] > 0): ?> · déjà remboursé : <?= euros($t['rembourse']) ?><?php endif; ?></p>
  <?php else: ?>
    <p class="vide petit">Aucun frais avancé ce mois-ci.</p>
  <?php endif; ?>
  <?php if ($s && $droits['est_employe']): ?><a class="btn btn-secondaire" href="<?= h(url('depense')) ?>">📷 Ajouter un ticket</a><?php endif; ?>
</section>

<!-- HEURES SUPPLÉMENTAIRES -->
<section class="carte rh-section">
  <h2 class="rh-h">Heures supplémentaires</h2>
  <?php if ($frh['heures_sup']): ?>
  <table class="tableau">
    <thead><tr><th>Date</th><th>Chantier / motif</th><th class="num">Heures</th><?= $s ? '<th></th>' : '' ?></tr></thead>
    <tbody>
    <?php foreach ($frh['heures_sup'] as $r): ?>
      <tr><td class="nowrap"><?= h(date_fr($r['date_hs'])) ?></td><td><?= h($r['motif']) ?></td><td class="num"><?= h(nombre_fr($r['heures'])) ?></td>
        <?= $s ? '<td>' . $suppr('hs_del', ['id' => $r['id']]) . '</td>' : '' ?></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th colspan="2">Total</th><td class="num"><?= h(nombre_fr($frh['total_heures'])) ?> h</td><?= $s ? '<td></td>' : '' ?></tr></tfoot>
  </table>
  <?php else: ?><p class="vide petit">Aucune heure supplémentaire.</p><?php endif; ?>
  <?php if ($s): ?>
  <form method="post" action="<?= $action ?>" class="rh-ajout">
    <?= $cache('hs_add') ?>
    <input type="date" name="date" required min="<?= h(mois_bornes($a, $m)[0]) ?>" max="<?= h($finMois) ?>" aria-label="Date">
    <input name="motif" placeholder="Chantier / motif" maxlength="255" aria-label="Chantier ou motif">
    <input name="heures" placeholder="Heures" inputmode="decimal" required aria-label="Nombre d'heures" class="court">
    <button class="btn btn-secondaire">Ajouter</button>
  </form>
  <?php endif; ?>
</section>

<!-- CONGÉS PAYÉS -->
<section class="carte rh-section">
  <h2 class="rh-h">Congés payés</h2>
  <?php $periodes = array_merge(array_map(fn($p) => $p + [3 => 'conge'], $frh['conges']), array_map(fn($p) => $p + [3 => 'formation'], $frh['formations'])); ?>
  <?php if ($periodes): ?>
  <ul class="rh-periodes">
    <?php foreach ($periodes as [$du, $au, $nb, $type]): ?>
      <li><span>Du <strong><?= h(date_fr($du)) ?></strong> au <strong><?= h(date_fr($au)) ?></strong> (inclus) · <?= $nb ?> jour(s)<?= $type === 'formation' ? ' · <em>formation</em>' : '' ?></span>
        <?= $s ? $suppr('conge_del', ['du' => $du, 'au' => $au, 'type' => $type]) : '' ?></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?><p class="vide petit">Aucun congé ce mois-ci.</p><?php endif; ?>
  <?php if ($s): ?>
  <form method="post" action="<?= $action ?>" class="rh-ajout">
    <?= $cache('conge_add') ?>
    <label class="petit">Du<input type="date" name="du" required min="<?= h(mois_bornes($a, $m)[0]) ?>" max="<?= h($finMois) ?>"></label>
    <label class="petit">Au<input type="date" name="au" min="<?= h(mois_bornes($a, $m)[0]) ?>" max="<?= h($finMois) ?>"></label>
    <select name="type" aria-label="Type"><option value="conge">Congés payés</option><option value="formation">Formation</option></select>
    <button class="btn btn-secondaire">Ajouter</button>
  </form>
  <p class="aide">Les week-ends et jours fériés ne sont pas comptés. Une période qui déborde sur le mois suivant se saisit sur la fiche de ce mois-là.</p>
  <?php endif; ?>
</section>

<!-- INDIC TECH -->
<section class="carte rh-section">
  <h2 class="rh-h">Indic tech</h2>
  <?php if ($frh['indic_tech']): ?>
  <table class="tableau">
    <thead><tr><th>Client</th><th>Prestation</th><th class="num">Montant HT</th><?= $s ? '<th></th>' : '' ?></tr></thead>
    <tbody>
    <?php foreach ($frh['indic_tech'] as $r): ?>
      <tr><td><?= h($r['client']) ?></td><td><?= h($r['prestation']) ?></td><td class="num"><?= $r['montant_ht'] !== null ? euros($r['montant_ht']) : '' ?></td>
        <?= $s ? '<td>' . $suppr('it_del', ['id' => $r['id']]) . '</td>' : '' ?></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th colspan="2">Total</th><td class="num"><?= euros($frh['total_indic']) ?></td><?= $s ? '<td></td>' : '' ?></tr></tfoot>
  </table>
  <?php else: ?><p class="vide petit">Aucune indication technique.</p><?php endif; ?>
  <?php if ($s): ?>
  <form method="post" action="<?= $action ?>" class="rh-ajout">
    <?= $cache('it_add') ?>
    <input name="client" placeholder="Client" required maxlength="255" aria-label="Client">
    <input name="prestation" placeholder="Prestation" maxlength="255" aria-label="Prestation">
    <span class="champ-euro court"><input name="montant_ht" placeholder="HT" inputmode="decimal" aria-label="Montant HT"></span>
    <button class="btn btn-secondaire">Ajouter</button>
  </form>
  <?php endif; ?>
</section>

<!-- CONTRIBUTIONS -->
<section class="carte rh-section">
  <h2 class="rh-h">Contributions</h2>
  <?php if ($frh['contributions']): ?>
  <table class="tableau">
    <thead><tr><th>Client</th><th class="centre">Panneaux</th><th class="centre">Avis Google</th><th>Autre</th><?= $s ? '<th></th>' : '' ?></tr></thead>
    <tbody>
    <?php foreach ($frh['contributions'] as $r): ?>
      <tr><td><?= h($r['client']) ?></td><td class="centre"><?= (int)$r['panneaux'] ? '✔' : '' ?></td><td class="centre"><?= (int)$r['avis_google'] ? '✔' : '' ?></td>
        <td><?= (int)$r['autre'] ? '✔ ' . h($r['autre_texte']) : '' ?></td><?= $s ? '<td>' . $suppr('ct_del', ['id' => $r['id']]) . '</td>' : '' ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p class="vide petit">Aucune contribution.</p><?php endif; ?>
  <?php if ($s): ?>
  <form method="post" action="<?= $action ?>" class="rh-ajout rh-ajout-contrib">
    <?= $cache('ct_add') ?>
    <input name="client" placeholder="Client" required maxlength="255" aria-label="Client">
    <div class="choix">
      <label class="puce"><input type="checkbox" name="panneaux" value="1"><span>Panneaux</span></label>
      <label class="puce"><input type="checkbox" name="avis_google" value="1"><span>Avis Google</span></label>
      <label class="puce"><input type="checkbox" name="autre" value="1" data-autre><span>Autre</span></label>
    </div>
    <input name="autre_texte" placeholder="Précisez l'autre contribution" maxlength="255" data-autre-texte hidden aria-label="Autre contribution">
    <button class="btn btn-secondaire">Ajouter</button>
  </form>
  <?php endif; ?>
</section>

<!-- CHAMP LIBRE -->
<section class="carte rh-section">
  <h2 class="rh-h">Champ libre <span>(challenge ponctuel / autres…)</span></h2>
  <?php if ($s): ?>
  <form method="post" action="<?= $action ?>" class="form">
    <?= $cache('libre_save') ?>
    <textarea name="champ_libre" rows="3" maxlength="3000"><?= h($info['champ_libre'] ?? '') ?></textarea>
    <button class="btn btn-secondaire">Enregistrer</button>
  </form>
  <?php else: ?>
    <p><?= nl2br(h($info['champ_libre'] ?? '')) ?: '<span class="muted">—</span>' ?></p>
  <?php endif; ?>
</section>

<!-- VALIDATIONS -->
<section class="rh-cadres">
  <div class="carte rh-cadre rh-cadre-direction">
    <h2 class="rh-h">Cadre direction</h2>
    <?php if ($droits['resp']): ?>
      <form method="post" action="<?= $action ?>" class="form" data-confirmer="Valider la fiche de <?= h($emp['prenom']) ?> ?">
        <?= $cache('resp_valider') ?>
        <label class="case"><input type="checkbox" name="prime_qualite" value="1" <?= (int)$info['prime_qualite'] ? 'checked' : '' ?>> Prime qualité</label>
        <label class="case"><input type="checkbox" name="prime_contributions" value="1" <?= (int)$info['prime_contributions'] ? 'checked' : '' ?>> Prime contributions</label>
        <label>Commentaire <span class="muted">(facultatif)</span><textarea name="commentaire_resp" rows="2"><?= h($info['commentaire_resp'] ?? '') ?></textarea></label>
        <?php if (!$info['valide_collab_at']): ?><p class="aide">Le collaborateur n'a pas encore validé sa fiche.</p><?php endif; ?>
        <button class="btn btn-primaire">Valider la fiche</button>
      </form>
    <?php else: ?>
      <p><?= (int)$info['prime_qualite'] ? '☑' : '☐' ?> Prime qualité<br>
         <?= (int)$info['prime_contributions'] ? '☑' : '☐' ?> Prime contributions</p>
      <?php if (trim((string)$info['commentaire_resp']) !== ''): ?><p class="petit"><?= nl2br(h($info['commentaire_resp'])) ?></p><?php endif; ?>
      <p class="petit"><?= $info['valide_resp_at'] ? 'Validé par ' . h(nom_complet($frh['valide_par'] ?? ['prenom' => '', 'nom' => ''])) . ' le ' . h($fmtDate($info['valide_resp_at'])) : '<span class="muted">En attente de validation' . ($frh['responsable'] ? ' par ' . h($frh['responsable']['prenom']) : '') . '</span>' ?></p>
    <?php endif; ?>
  </div>
  <div class="carte rh-cadre">
    <h2 class="rh-h">Cadre collaborateur</h2>
    <?php if ($info['valide_collab_at']): ?>
      <p class="petit">Fiche validée par <?= h(nom_complet($emp)) ?> le <?= h($fmtDate($info['valide_collab_at'])) ?>.</p>
    <?php elseif ($droits['collab']): ?>
      <p class="petit">Une fois la fiche complète, validez-la : <?= $frh['responsable'] ? h($frh['responsable']['prenom']) . ' sera prévenu par e-mail.' : 'elle sera transmise à votre responsable.' ?></p>
      <form method="post" action="<?= $action ?>" data-confirmer="Valider votre fiche ? Vous ne pourrez plus la modifier, sauf si votre responsable la rouvre.">
        <?= $cache('collab_valider') ?><button class="btn btn-primaire">Je valide ma fiche</button>
      </form>
    <?php else: ?>
      <p class="petit muted">Pas encore validée par le collaborateur.</p>
    <?php endif; ?>
  </div>
</section>

<?php if ($droits['rouvrir']): ?>
<form method="post" action="<?= $action ?>" class="centre" data-confirmer="Rouvrir la fiche ? Les validations seront annulées.">
  <?= $cache('rouvrir') ?><button class="btn btn-lien">Rouvrir la fiche</button>
</form>
<?php endif; ?>
<?php if ($droits['est_resp'] && $user['role'] === 'admin'): ?>
  <p class="actions"><a class="btn btn-secondaire" href="<?= h(url('export_pdf', ['a' => $a, 'm' => $m, 'u' => $emp['id']])) ?>">PDF de la fiche + tickets</a></p>
<?php endif; ?>
<script src="assets/fiche_rh.js?v=<?= APP_VERSION ?>" defer></script>
