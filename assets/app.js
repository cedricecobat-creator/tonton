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

  // Application installable (écran d'accueil)
  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () {});
    });
  }
})();
