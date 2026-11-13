/* Fiche RH : champ « Autre » des contributions */
(function () {
  'use strict';
  document.querySelectorAll('[data-autre]').forEach(function (chk) {
    var form = chk.closest('form');
    var champ = form && form.querySelector('[data-autre-texte]');
    if (!champ) return;
    function maj() {
      champ.hidden = !chk.checked;
      champ.required = chk.checked;
      if (chk.checked) champ.focus();
    }
    chk.addEventListener('change', maj);
  });
})();
