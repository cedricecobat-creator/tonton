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

// Carnet des commerçants
var carnet = [
  { n: 'Brasserie du Port', v: 'BAYONNE', s: '', c: 'repas', o: '', k: 12 },
  { n: 'Brasserie du Port', v: 'ANGLET', s: '', c: 'repas', o: '', k: 2 },
  { n: 'Total Énergies Anglet', v: 'ANGLET', s: '12345678900015', c: 'carburant', o: 'Carburant', k: 8 },
  { n: 'Leclerc', v: 'BAYONNE', s: '', c: 'autres', o: '', k: 5 },
  { n: 'BP', v: 'PAU', s: '', c: 'carburant', o: '', k: 3 }
];
var tc = o.trouverCommercant;
var inf;
var res = tc('STATION XYZ\nSIRET 123 456 789 00015\nTOTAL 50,00', carnet, '12345678900015');
ok(res && res.par === 'siret' && res.commercant.n === 'Total Énergies Anglet', 'commerçant reconnu par son SIRET');
res = tc('BRASSERIE DU P0RT\n12 quai Galuperie\n64100 BAYONNE\nTOTAL 24,00', carnet, null);
ok(res && res.commercant.n === 'Brasserie du Port' && res.commercant.v === 'BAYONNE', 'nom mal lu (P0RT) + ville lue → bon établissement');
res = tc('BRASSERIEDUPORT\nANGLET\nTOTAL 24,00', carnet, null);
ok(res && res.commercant.v === 'ANGLET', 'nom collé, ville Anglet');
res = tc('E.LECLERC\nBAYONNE\nTOTAL 12,00', carnet, null);
ok(res && res.commercant.n === 'Leclerc', 'Leclerc dans « E.LECLERC »');
res = tc('BOULANGERIE MARTIN\nTOTAL 5,00\nBPX', carnet, null);
ok(res === null, 'nom court (BP) : seulement à l\'identique, pas de fausse reconnaissance');
res = tc('STATION XYZ\nSIRET 999 888 777 00011', carnet, '99988877700011');
ok(res === null, 'SIRET inconnu et nom absent : rien');
res = tc('TOTAL ENERGIES ANGLET\nSIRET 999 888 777 00011', carnet, '99988877700011');
ok(res === null, 'même nom mais autre SIRET : autre établissement, pas reconnu');
res = tc('BRASSERIE XYZ\nTOTAL 24,00\nCARTE', [{ n: 'TOTAL', v: '', s: '11122233300044', c: 'carburant', o: '', k: 9 }], null);
ok(res === null, 'commerçant nommé « TOTAL » : pas reconnu sur le mot TOTAL du ticket');
ok(o.siretValide('13002526500013') && !o.siretValide('13002526500014'), 'clé de contrôle du SIRET');
inf = o.analyserTicket('STATION\nSIRET 130 025 265 00014\nTOTAL 10,00', AUJ, {});
ok(inf.siret === null, 'SIRET mal lu (clé fausse) : écarté');
inf = o.analyserTicket('STATION\nSIRET 130 025 265 00013\nTOTAL 10,00', AUJ, {});
ok(inf.siret === '13002526500013', 'SIRET correct : retenu');
inf = o.analyserTicket('CHEZ NOUS\nSIRET 811 917 467 00611\nTOTAL 37,00', AUJ, {});
ok(inf.siret === null && inf.siretsDouteux.indexOf('81191746700011') >= 0 && inf.siretsDouteux.length <= 4,
  'SIRET mal lu (00611) : la bonne correction (00011) fait partie des variantes à confirmer');
inf = o.analyserTicket('BRASSERIE DU PORT\n64100 BAYONNE\n13/10/2026\nTOTAL 24,00', AUJ, { commercants: carnet });
ok(inf.commercant && inf.commercant.commercant.v === 'BAYONNE', 'analyserTicket renvoie le commerçant reconnu');

console.log(erreurs ? '\n' + erreurs + ' erreur(s)' : '\nRÉSULTAT : tout est OK');
process.exit(erreurs ? 1 : 0);
