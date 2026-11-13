/* Fiche utilisateur : champs affichés selon le rôle */
(function () {
  'use strict';
  var caseAdmin = document.querySelector('[data-si-role="admin"]');
  var blocRh = document.querySelector('[data-si-fiche-rh]');
  function maj() {
    var r = document.querySelector('input[name=role]:checked');
    var role = r ? r.value : '';
    if (caseAdmin) caseAdmin.hidden = role !== 'admin';
    if (blocRh) {
      var chk = document.querySelector('input[name=admin_fiche]');
      blocRh.hidden = !(role === 'salarie' || (role === 'admin' && chk && chk.checked));
    }
  }
  document.querySelectorAll('input[name=role], input[name=admin_fiche]').forEach(function (el) {
    el.addEventListener('change', maj);
  });
  maj();
})();
