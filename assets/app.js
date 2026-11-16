/* Scripts communs */
(function () {
  'use strict';
  var csrf = (document.querySelector('meta[name=csrf]') || {}).content || '';
  window.NDF = window.NDF || {};
  window.NDF.csrf = csrf;

  // Confirmation avant les actions sensibles
  document.addEventListener('submit', function (e) {
    var f = e.target;
    var msg = f.getAttribute('data-confirmer');
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
    }
  });

  // Grille des congés et formations : un appui fait défiler présent → congé → formation
  var grille = document.querySelector('.grille-abs[data-modifiable="1"]');
  if (grille) {
    var suivant = { '': 'conge', conge: 'formation', formation: '' };
    grille.addEventListener('click', function (e) {
      var b = e.target.closest('button.case-abs');
      if (!b || b.disabled) return;
      var avant = b.getAttribute('data-type') || '';
      var apres = suivant[avant];
      b.className = 'case-abs ' + apres;
      b.setAttribute('data-type', apres);
      var fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('user_id', b.getAttribute('data-user'));
      fd.append('date', b.getAttribute('data-date'));
      fd.append('type', apres);
      fetch('index.php?p=absence_set', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (!j.ok) throw new Error(j.error || 'Erreur'); })
        .catch(function (err) {
          b.className = 'case-abs ' + avant;
          b.setAttribute('data-type', avant);
          alert("Modification non enregistrée : " + err.message);
        });
    });
  }

  // Cases qui enregistrent dès qu'on les coche (ex. : déduction d'une prime en régularisation)
  document.addEventListener('change', function (e) {
    var c = e.target.closest && e.target.closest('[data-auto-submit]');
    if (c && c.form) c.form.submit();
  });

  // Filtres de la liste des tickets (sur la page, sans recharger ; gardés d'un mois à l'autre)
  var filtres = document.getElementById('filtres-tickets');
  if (filtres) {
    var champs = Array.prototype.slice.call(filtres.querySelectorAll('[data-filtre]'));
    var lignes = Array.prototype.slice.call(document.querySelectorAll('.liste-tickets .ligne-ticket'));
    var resultat = document.getElementById('filtres-resultat');
    var pastille = document.getElementById('filtres-nb');
    var CLE = 'tonton-filtres-tickets';
    var sansAccents = function (t) { return t.normalize ? t.normalize('NFD').replace(/[̀-ͯ]/g, '') : t; };
    var appliquer = function () {
      var v = {};
      champs.forEach(function (c) { v[c.getAttribute('data-filtre')] = c.value.trim(); });
      var mots = sansAccents(v.texte.toLowerCase()).split(/\s+/).filter(Boolean);
      var n = 0, total = 0;
      lignes.forEach(function (li) {
        var d = li.dataset;
        var ok = (!v.cat || (v.cat === 'equipe' ? d.equipe === '1' : d.cat === v.cat))
          && (!v.paiement || d.paiement === v.paiement)
          && (!v.payeur || d.payeur === v.payeur)
          && (!v.convive || d.convives.split(',').indexOf(v.convive) >= 0)
          && (!v.special || d.special.split(' ').indexOf(v.special) >= 0)
          && mots.every(function (m) { return sansAccents(d.texte).indexOf(m) >= 0; });
        li.hidden = !ok;
        if (ok) { n++; total += parseFloat(d.montant) || 0; }
      });
      // en-têtes de jour sans ticket visible
      Array.prototype.slice.call(document.querySelectorAll('.liste-tickets .liste-jour')).forEach(function (j) {
        var el = j.nextElementSibling, vu = false;
        while (el && !el.classList.contains('liste-jour')) { if (!el.hidden) vu = true; el = el.nextElementSibling; }
        j.hidden = !vu;
      });
      var actifs = champs.filter(function (c) { return c.value.trim() !== ''; }).length;
      pastille.hidden = !actifs;
      pastille.textContent = actifs;
      resultat.hidden = !actifs;
      resultat.textContent = n + ' ticket(s) sur ' + lignes.length + ' · total '
        + total.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
      try { sessionStorage.setItem(CLE, JSON.stringify(v)); } catch (e) { /* navigation privée */ }
    };
    try {
      var memo = JSON.parse(sessionStorage.getItem(CLE) || '{}');
      champs.forEach(function (c) {
        var val = memo[c.getAttribute('data-filtre')];
        if (val && (c.tagName !== 'SELECT' || c.querySelector('option[value="' + val.replace(/"/g, '') + '"]'))) c.value = val;
      });
    } catch (e) { /* rien de mémorisé */ }
    champs.forEach(function (c) { c.addEventListener(c.tagName === 'SELECT' ? 'change' : 'input', appliquer); });
    document.getElementById('filtres-effacer').addEventListener('click', function () {
      champs.forEach(function (c) { c.value = ''; });
      appliquer();
    });
    appliquer();
    if (champs.some(function (c) { return c.value.trim() !== ''; })) filtres.open = true;
  }

  // Application installable (écran d'accueil)
  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () {});
    });
  }
})();
