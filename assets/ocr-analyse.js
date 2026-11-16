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
    // « l3 » ou « i3 » isolé : la lettre l / i lue à la place du chiffre 1
    t = t.replace(/(^|[^a-z0-9])[li|](\d)(?![a-z0-9])/g, '$11$2');
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
    return trouverDateEnLettres(texte, aujourdhui);
  }

  /* ---- Dates écrites en toutes lettres, tolérantes aux erreurs de lecture ----
     « 13 octobre 2026 », « 1er oct. 2026 », « 13 0ctobre 2O26 », « 13 octobre » (année déduite)… */
  var MOIS_COMPLETS = ['janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];
  var MOIS_ABREGES = { janv: 1, janvi: 1, fevr: 2, fevri: 2, avri: 4, juil: 7, juill: 7, sept: 9, octo: 10, nove: 11, nov: 11, dece: 12, dec: 12, oct: 10, avr: 4, fev: 2, jan: 1 };

  function distance(a, b) {
    var d = [], i, j;
    for (i = 0; i <= a.length; i++) { d[i] = [i]; }
    for (j = 0; j <= b.length; j++) { d[0][j] = j; }
    for (i = 1; i <= a.length; i++) {
      for (j = 1; j <= b.length; j++) {
        d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
      }
    }
    return d[a.length][b.length];
  }

  // Mot lu comme un mois ? (chiffres confondus avec des lettres : 0→o, 1→l, 5→s)
  function moisDuMot(mot) {
    var w = sansAccents(mot.toLowerCase()).replace(/0/g, 'o').replace(/1/g, 'l').replace(/5/g, 's').replace(/[^a-z]/g, '');
    if (w.length < 3) return 0;
    if (w === 'mai') return 5;
    if (w === 'mar' || w === 'mars') return 3;
    if (MOIS_ABREGES[w]) return MOIS_ABREGES[w];
    var meilleur = 0, dmin = 99;
    MOIS_COMPLETS.forEach(function (nom, i) {
      var dd = distance(w, nom);
      // préfixe d'un nom de mois (abréviation lue partiellement), au moins 4 lettres
      if (w.length >= 4 && nom.indexOf(w) === 0) dd = 0;
      if (dd < dmin) { dmin = dd; meilleur = i + 1; }
    });
    // mots courts : correspondance exacte seulement (évite « mais » lu comme « mars »)
    var tolerance = w.length >= 8 ? 2 : (w.length >= 5 ? 1 : 0);
    return dmin <= tolerance ? meilleur : 0;
  }

  // Nombre lu avec des lettres confondues : O→0, l/I/i→1, S→5, B→8, Z→2
  function nombreDuMot(mot) {
    var w = mot.replace(/^(\d{1,2})(er|e|eme)$/i, '$1')
      .replace(/[oO]/g, '0').replace(/[lIi|]/g, '1').replace(/[sS]/g, '5').replace(/B/g, '8').replace(/[zZ]/g, '2');
    return /^\d+$/.test(w) ? w : null;
  }

  function trouverDateEnLettres(texte, aujourdhui) {
    var ref = aujourdhui || new Date().toISOString().slice(0, 10);
    var mots = texte.split(/[\s,;:()\/\-]+/).map(function (w) { return w.replace(/[.']+$/, '').replace(/^[.']+/, ''); }).filter(Boolean);
    var sansAnnee = null;
    for (var i = 1; i < mots.length; i++) {
      var mois = moisDuMot(mots[i]);
      if (!mois) continue;
      if (!/\d/.test(mots[i - 1])) continue; // le jour contient au moins un vrai chiffre
      var jour = nombreDuMot(mots[i - 1]);
      if (jour === null || jour.length > 2) continue;
      var an = mots[i + 1] ? nombreDuMot(mots[i + 1]) : null;
      if (an && (an.length === 4 || an.length === 2)) {
        var d = dateValide(+jour, mois, +an, aujourdhui);
        if (d) return d;
        continue; // année écrite mais impossible (futur, trop ancienne) : on ne devine pas
      }
      if (!sansAnnee) {
        // Année absente : la date passée la plus récente (cette année ou l'année d'avant)
        var a0 = +ref.slice(0, 4);
        sansAnnee = dateValide(+jour, mois, a0, aujourdhui) || dateValide(+jour, mois, a0 - 1, aujourdhui);
      }
    }
    return sansAnnee;
  }

  /* ---- Annotations manuscrites sur le ticket ----
     PERSO → payé avec la CB perso ; ESPECES → payé en espèces ;
     initiales d'un membre de l'équipe (FC, DP…) → participant au repas.
     Les initiales doivent être écrites en MAJUSCULES et isolées. */
  function trouverAnnotations(texte, initialesConnues, minuscules) {
    var out = { perso: false, especes: false, initiales: [] };
    var mots = String(texte || '').split(/[^A-Za-zÀ-ÿ0-9]+/).filter(Boolean);
    var connues = (initialesConnues || []).map(function (s) { return String(s).toUpperCase(); });
    mots.forEach(function (w) {
      var n = normaliserMot(w);
      if (n === 'PERSO' || (n.length === 5 && distance(n, 'PERSO') === 1 && /^PER/.test(n))) out.perso = true;
      if (/^ESP(ECES?)?$/.test(n) && w === w.toUpperCase()) out.especes = true;
      var W = w.toUpperCase();
      if ((minuscules || w === W) && w.length >= 2 && w.length <= 3 && connues.indexOf(W) >= 0 && out.initiales.indexOf(W) < 0) {
        out.initiales.push(W);
      }
    });
    return out;
  }
  function normaliserMot(w) { // normalise un mot pour la comparaison (majuscules, sans accents, 0 lu pour O)
    return sansAccents(w.toUpperCase()).replace(/0/g, 'O');
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

  // Nom réduit pour comparer : majuscules, sans accents ni ponctuation (comme côté serveur)
  function cleNom(s) {
    return sansAccents(String(s || '').toUpperCase()).replace(/Œ/g, 'OE').replace(/Æ/g, 'AE')
      .replace(/[^A-Z0-9]+/g, ' ').trim();
  }

  /*
   * Commerçant du carnet présent sur le ticket.
   * liste : [{ n: nom, v: ville, s: siret, c: catégorie, o: nature, k: nombre de tickets }]
   * 1. le SIRET lu est l'index le plus sûr ;
   * 2. sinon le nom est cherché dans le texte, en tolérant les erreurs de lecture
   *    (aucune pour un nom de 4 lettres ou moins, 1 jusqu'à 8, puis 1 par 5 lettres, 3 au plus).
   * Retourne { commercant, par: 'siret' | 'nom' } ou null.
   */
  var MOTS_TICKET = /^(TOTAL|TTC|HT|TVA|CB|CARTE|CARTE BANCAIRE|MERCI|TICKET|CAISSE|ESPECES|MONTANT|EUR|EUROS|VENTE|FACTURE|CLIENT|PAIEMENT|SANS CONTACT|DEBIT|CREDIT|A PAYER|RENDU|TABLE|SERVICE|BIENVENUE)$/;

  function trouverCommercant(texte, liste, siret) {
    if (!liste || !liste.length) return null;
    var i;
    if (siret) {
      for (i = 0; i < liste.length; i++) {
        if (liste[i].s && liste[i].s === siret) return { commercant: liste[i], par: 'siret' };
      }
    }
    var lignes = String(texte || '').split(/\r?\n/).map(cleNom).filter(Boolean);
    // le nom est en tête (ou en pied) de ticket : la recherche approchée se limite à ces lignes
    var proches = lignes.slice(0, 12).concat(lignes.length > 18 ? lignes.slice(-6) : lignes.slice(12));
    var tout = ' ' + lignes.join(' ') + ' ';
    var meilleur = null;
    liste.forEach(function (c) {
      if (siret && c.s && c.s !== siret) return; // autre établissement
      var cle = cleNom(c.n);
      var lettres = cle.replace(/ /g, '').length;
      if (lettres < 3 || MOTS_TICKET.test(cle)) return; // « TOTAL », « CARTE »… : reconnus par le SIRET seulement
      var tol = lettres <= 4 ? 0 : (lettres <= 8 ? 1 : Math.min(3, Math.floor(lettres / 5)));
      var best = tout.indexOf(' ' + cle + ' ') >= 0 ? 0 : Infinity;
      if (best && tol) {
        var nbMots = cle.split(' ').length;
        proches.forEach(function (l) {
          var w = l.split(' ');
          for (var n = 1; n <= nbMots + 1; n++) {
            for (var j = 0; j + n <= w.length; j++) {
              var cand = w.slice(j, j + n).join(' ');
              if (Math.abs(cand.length - cle.length) > tol) continue;
              var d = distance(cand, cle);
              if (d < best) best = d;
            }
          }
        });
      }
      if (best > tol) return;
      var villeLue = c.v && cleNom(c.v).length >= 2 && tout.indexOf(' ' + cleNom(c.v) + ' ') >= 0;
      // le plus fidèle d'abord, puis le plus long (plus précis), la ville lue et l'habitude
      var score = best / lettres - lettres / 1000 - (villeLue ? 0.05 : 0) - Math.min(c.k || 0, 100) / 100000;
      if (!meilleur || score < meilleur.score) meilleur = { commercant: c, par: 'nom', score: score };
    });
    return meilleur ? { commercant: meilleur.commercant, par: 'nom' } : null;
  }

  function analyserTicket(texte, aujourdhui, options) {
    texte = String(texte || '');
    options = options || {};
    var montant = trouverMontant(texte);
    var lieu = trouverLieu(texte);
    var siret = trouverSiret(texte);
    return {
      date: trouverDate(texte, aujourdhui), montant: montant, lieu: lieu,
      ville: trouverVille(texte, lieu), siret: siret, tva: trouverTva(texte, montant),
      annotations: trouverAnnotations(texte, options.initiales || [], !!options.minuscules),
      commercant: options.commercants ? trouverCommercant(texte, options.commercants, siret) : null
    };
  }

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { analyserTicket: analyserTicket, trouverCommercant: trouverCommercant, cleNom: cleNom };
  } else {
    racine.NDF = racine.NDF || {};
    racine.NDF.analyserTicket = analyserTicket;
    racine.NDF.trouverCommercant = trouverCommercant;
    racine.NDF.cleNom = cleNom;
  }
})(this);
