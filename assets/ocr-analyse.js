/* Analyse du texte lu sur un ticket : date, montant total, commerçant.
   Fonctionne aussi sous Node pour les tests (module.exports). */
(function (racine) {
  'use strict';

  var MOIS = { janv: 1, jan: 1, fevr: 2, fev: 2, mars: 3, mar: 3, avr: 4, mai: 5, juin: 6, juil: 7, aout: 8,
    sept: 9, sep: 9, oct: 10, nov: 11, dec: 12 };

  function sansAccents(s) {
    return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
  }

  function dateValide(j, m, a, aujourdhui) {
    if (a < 100) a += 2000;
    if (m < 1 || m > 12 || j < 1 || j > 31 || a < 2000) return null;
    var d = new Date(Date.UTC(a, m - 1, j));
    if (d.getUTCDate() !== j) return null;
    var ymd = d.toISOString().slice(0, 10);
    var ref = aujourdhui || new Date().toISOString().slice(0, 10);
    var ecart = (Date.parse(ref) - d.getTime()) / 86400000;
    if (ecart < -1 || ecart > 400) return null; // ni futur, ni plus d'un an
    return ymd;
  }

  function trouverDate(texte, aujourdhui) {
    var t = sansAccents(texte.toLowerCase());
    // Corrections fréquentes de lecture : O lu à la place de 0
    t = t.replace(/(\d)[o](\d)/g, '$10$2');
    var re = /(^|[^\d])(\d{1,2})\s?[\/.\-]\s?(\d{1,2})\s?[\/.\-]\s?(\d{4}|\d{2})(?!\d)/g;
    var m;
    while ((m = re.exec(t))) {
      var d = dateValide(+m[2], +m[3], +m[4], aujourdhui);
      if (d) return d;
    }
    var re2 = /(\d{1,2})\s*(janv|jan|fevr|fev|mars|mar|avr|mai|juin|juil|aout|sept|sep|oct|nov|dec)[a-z.]*\s*(\d{4}|\d{2})/g;
    while ((m = re2.exec(t))) {
      var d2 = dateValide(+m[1], MOIS[m[2]], +m[3], aujourdhui);
      if (d2) return d2;
    }
    return null;
  }

  function montantsDe(ligne) {
    var out = [];
    var re = /(^|[^\d,.])(\d{1,3}(?:[ . ]\d{3})+|\d{1,5})\s?[,.]\s?(\d{2})(?![\d%])/g;
    var m;
    while ((m = re.exec(ligne))) {
      var entier = m[2].replace(/[ . ]/g, '');
      var v = parseFloat(entier + '.' + m[3]);
      if (v > 0 && v < 20000) out.push(v);
    }
    return out;
  }

  function trouverMontant(texte) {
    var lignes = texte.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
    var regles = [
      [/total\s*t\.?t\.?c|ttc\s*total|montant\s*t\.?t\.?c/, 10],
      [/net\s*a\s*payer|a\s*payer|reste\s*a\s*payer/, 9],
      [/montant\s*(du|regle|paye|carte|cb)?|transaction/, 7],
      [/^total|\stotal|total\s*(eur|€)|total\s*:/, 6],
      [/carte\s*bancaire|paiement\s*cb|\bcb\b|visa|mastercard|sans\s*contact|debit/, 4],
      [/\beur\b|€/, 1]
    ];
    var exclus = /\bh\.?t\b|hors\s*taxe|tva|taux|rendu|monnaie|remise|economie|fidelite|points|sous[\s-]?total\s*ht/;
    var candidats = [];
    var articles = 0; // somme des lignes d'articles (sans mot-clé)
    lignes.forEach(function (ligne, i) {
      var l = sansAccents(ligne.toLowerCase());
      if (exclus.test(l)) return;
      var score = 0;
      regles.forEach(function (r) { if (r[0].test(l) && r[1] > score) score = r[1]; });
      if (!score) {
        var va = montantsDe(ligne);
        if (va.length) articles += va[va.length - 1];
        return;
      }
      var vals = montantsDe(ligne);
      // Montant sur la ligne suivante (ex. « TOTAL » puis « 24,28 »)
      if (!vals.length && lignes[i + 1] && !exclus.test(sansAccents(lignes[i + 1].toLowerCase()))) {
        vals = montantsDe(lignes[i + 1]);
      }
      if (!vals.length) return;
      candidats.push({ score: score, v: vals[vals.length - 1] });
    });
    // Un même montant retrouvé sur plusieurs lignes (total, carte…) est plus fiable :
    // cela corrige la plupart des erreurs de lecture d'un chiffre.
    var meilleur = null;
    candidats.forEach(function (c) {
      var freq = candidats.filter(function (o) { return o.v === c.v; }).length;
      var note = c.score + 7 * (freq - 1);
      // Le total égal à la somme des articles est très probablement le bon
      if (articles > 0 && Math.abs(articles - c.v) < 0.005) note += 8;
      if (!meilleur || note > meilleur.note || (note === meilleur.note && c.v > meilleur.v)) {
        meilleur = { note: note, v: c.v };
      }
    });
    if (meilleur) return meilleur.v;
    // À défaut : le plus grand montant du ticket
    var tous = [];
    lignes.forEach(function (l) {
      if (!exclus.test(sansAccents(l.toLowerCase()))) tous = tous.concat(montantsDe(l));
    });
    return tous.length ? Math.max.apply(null, tous) : null;
  }

  function trouverLieu(texte) {
    var lignes = texte.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean).slice(0, 6);
    var ignore = /ticket|facture|bienvenue|merci|\bcaisse|\bclient|\btel\b|\bt[eé]l[eé]phone|siret|siren|\btva\b|www\.|http|@|\bdate\b|\bheure|n[°o]\s*\d|^(sous[\s-]?)?total\s*(ttc|ht|eur|:)?$|^montant|^a payer/i;
    for (var i = 0; i < lignes.length; i++) {
      var l = lignes[i].replace(/[*=_#~|<>]+/g, ' ').replace(/\s+/g, ' ').trim();
      var lettres = (l.match(/[A-Za-zÀ-ÿ]/g) || []).length;
      if (l.length >= 3 && lettres >= 3 && lettres / l.length > 0.6 && !ignore.test(l) && !montantsDe(l).length) {
        return l.length > 60 ? l.slice(0, 60) : l;
      }
    }
    return null;
  }

  // SIRET : 14 chiffres (souvent groupés « 123 456 789 00012 »), de préférence après « SIRET »
  function trouverSiret(texte) {
    var t = texte.replace(/[oO](?=\d)/g, '0');
    var re = /(\d[\d .]{12,20}\d)/g, m, cands = [];
    while ((m = re.exec(t))) {
      var chiffres = m[1].replace(/\D/g, '');
      if (chiffres.length === 14) {
        var avant = t.slice(Math.max(0, m.index - 20), m.index).toLowerCase();
        cands.push({ v: chiffres, bonus: /siret|siren|rcs/.test(avant) ? 1 : 0 });
      }
    }
    // Les numéros de téléphone (10 chiffres) et de carte ne font pas 14 chiffres groupés ainsi
    cands.sort(function (a, b) { return b.bonus - a.bonus; });
    return cands.length && (cands[0].bonus || cands.length === 1) ? cands[0].v : null;
  }

  // Montant total de TVA : ligne « total TVA », sinon somme des lignes de TVA par taux
  function trouverTva(texte, total) {
    var lignes = texte.split(/\r?\n/).map(function (l) { return sansAccents(l.trim().toLowerCase()); }).filter(Boolean);
    var tot = null, somme = 0, n = 0;
    lignes.forEach(function (l) {
      if (!/\bt\.?v\.?a\b/.test(l) || /intracom|n[°o]|fr\d{2}/.test(l)) return;
      var vals = montantsDe(l);
      if (!vals.length) return;
      // Sur une ligne « taux / HT / TVA / TTC », la TVA est la plus petite valeur
      var v = vals.length > 1 ? Math.min.apply(null, vals) : vals[0];
      if (/total/.test(l)) tot = v; else { somme += v; n++; }
    });
    var tva = tot !== null ? tot : (n ? Math.round(somme * 100) / 100 : null);
    if (tva === null || !(tva > 0)) return null;
    if (total && tva > total * 0.21) return null; // invraisemblable
    return tva;
  }

  // Ville : ligne « 64100 BAYONNE », sinon ligne courte sous le nom du commerçant
  function trouverVille(texte, lieu) {
    var lignes = texte.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean).slice(0, 10);
    for (var i = 0; i < lignes.length; i++) {
      var m = lignes[i].match(/\b\d{5}\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ' -]{1,40})/);
      if (m) return nettoyerVille(m[1]);
    }
    var idx = lieu ? lignes.indexOf(lieu) : -1;
    if (idx >= 0 && lignes[idx + 1]) {
      var l = lignes[idx + 1];
      if (/^[A-Za-zÀ-ÿ' -]{2,30}$/.test(l) && !/\brue\b|avenue|\bbd\b|boulevard|\bplace\b|chemin|route|\btel\b|caisse/i.test(l)) {
        return nettoyerVille(l);
      }
    }
    return null;
  }
  function nettoyerVille(v) {
    v = v.replace(/\s+(cedex|tel|t[eé]l).*$/i, '').trim();
    return v.toUpperCase();
  }

  function analyserTicket(texte, aujourdhui) {
    texte = String(texte || '');
    var montant = trouverMontant(texte);
    var lieu = trouverLieu(texte);
    return {
      date: trouverDate(texte, aujourdhui), montant: montant, lieu: lieu,
      ville: trouverVille(texte, lieu), siret: trouverSiret(texte), tva: trouverTva(texte, montant)
    };
  }

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { analyserTicket: analyserTicket };
  } else {
    racine.NDF = racine.NDF || {};
    racine.NDF.analyserTicket = analyserTicket;
  }
})(this);
