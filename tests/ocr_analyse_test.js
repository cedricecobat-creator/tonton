/* Tests de l'analyse du texte lu sur les tickets (dates, annotations).
   Usage : node tests/ocr_analyse_test.js */
'use strict';
var o = require('../assets/ocr-analyse.js');
var AUJ = '2026-11-16';
var erreurs = 0;
function ok(cond, msg) {
  console.log((cond ? 'OK   ' : 'ERR  ') + msg);
  if (!cond) erreurs++;
}

[
  ['13/10/2026', '2026-10-13'], ['13.10.26 12:47', '2026-10-13'],
  ['13 octobre 2026', '2026-10-13'], ['Le 13 OCTOBRE 2026 à 12h47', '2026-10-13'], ['lundi 13 oct 2026', '2026-10-13'],
  ['Date : 13 0ctobre 2026', '2026-10-13'], ['1er octobre 2026', '2026-10-01'], ['13 octobre, 2026', '2026-10-13'],
  ['13 0CT0BRE 2O26', '2026-10-13'], ['Le 13 Octobre', '2026-10-13'], ['l3 octobre 2026', '2026-10-13'],
  ['13 octbre 2026', '2026-10-13'], ['3 septembre 2O26', '2026-09-03'], ['13 décembre', '2025-12-13'],
  ['20 mai 2026', '2026-05-20'], ['13 avri1 2026', '2026-04-13'],
  ['Total 12,50 Merci', null], ['2 mais 3', null], ['13 octobre 2027', null]
].forEach(function (c) {
  var r = o.analyserTicket(c[0], AUJ).date;
  ok(r === c[1], 'date « ' + c[0] + ' » → ' + r);
});

var ini = ['FC', 'DP', 'JB'];
var a = o.analyserTicket('BRASSERIE\nTOTAL 32,00\nPERSO\nFC DP\nCB 32,00', AUJ, { initiales: ini }).annotations;
ok(a.perso && a.initiales.join() === 'FC,DP', 'annotations PERSO, FC, DP');
a = o.analyserTicket('Pers0 ESPECES 10,00', AUJ, { initiales: ini }).annotations;
ok(a.perso && a.especes, 'PERS0 (zéro) et ESPECES');
a = o.analyserTicket('Personnel dp fc Perspective', AUJ, { initiales: ini }).annotations;
ok(!a.perso && a.initiales.length === 0, 'pas de fausse annotation (Personnel, initiales en minuscules)');
a = o.analyserTicket('dp fc', AUJ, { initiales: ini, minuscules: true }).annotations;
ok(a.initiales.join() === 'DP,FC', 'initiales en minuscules acceptées sur la 2e lecture');
a = o.analyserTicket('LG TO CB', AUJ, { initiales: ini }).annotations;
ok(a.initiales.length === 0, 'seules les initiales des membres comptent');

console.log(erreurs ? '\n' + erreurs + ' erreur(s)' : '\nRÉSULTAT : tout est OK');
process.exit(erreurs ? 1 : 0);
