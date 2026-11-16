/* Saisie d'un ticket : photo, compression, lecture automatique (OCR), envoi */
(function () {
  'use strict';

  var form = document.getElementById('form-depense');
  if (!form) return;

  var $ = function (sel) { return form.querySelector(sel); };
  var $$ = function (sel) { return Array.prototype.slice.call(form.querySelectorAll(sel)); };

  /* ---------- Affichage selon la catégorie ---------- */
  function categorie() {
    var c = $('input[name=categorie]:checked');
    return c ? c.value : '';
  }
  function majCategorie() {
    var c = categorie();
    $$('[data-si-km]').forEach(function (el) { el.hidden = c !== 'km'; });
    $$('[data-si-pas-km]').forEach(function (el) { el.hidden = c === 'km'; });
    $$('[data-si-repas]').forEach(function (el) { el.hidden = c !== 'repas'; });
    var pmt = $('input[name=paiement]:checked');
    var avance = c !== 'km' && pmt && (pmt.value === 'cb_perso' || pmt.value === 'especes');
    $$('[data-si-avance]').forEach(function (el) { el.hidden = !avance; });
    majKm();
  }
  function lireNombre(s) {
    s = String(s || '').replace(/[\s  €]/g, '').replace(',', '.');
    var n = parseFloat(s);
    return isNaN(n) ? 0 : n;
  }
  function formatEuro(n) {
    return n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
  }
  function majKm() {
    var kms = $('input[name=kms]');
    var out = document.getElementById('montant-km');
    if (!kms || !out) return;
    var v = lireNombre(kms.value) * parseFloat(kms.getAttribute('data-coef') || '0');
    out.textContent = v > 0 ? formatEuro(Math.round(v * 100) / 100) : '—';
  }
  $$('input[name=categorie]').forEach(function (r) { r.addEventListener('change', majCategorie); });
  $$('input[name=paiement]').forEach(function (r) { r.addEventListener('change', majCategorie); });
  var champKms = $('input[name=kms]');
  if (champKms) champKms.addEventListener('input', majKm);
  majCategorie();

  // Les champs modifiés à la main ne sont plus écrasés par la lecture automatique
  // (sur un ticket existant, les valeurs enregistrées sont conservées)
  var nouveau = form.querySelector('input[name=id]').value === '0';
  var touche = { date_depense: !nouveau, montant: !nouveau, lieu: !nouveau, ville: !nouveau, siret: !nouveau, montant_tva: !nouveau };
  ['date_depense', 'montant', 'lieu', 'ville', 'siret', 'montant_tva'].forEach(function (n) {
    var el = $('[name=' + n + ']');
    if (el) el.addEventListener('input', function () { touche[n] = true; });
  });
  // moyen de paiement et participants choisis à la main : les annotations lues ne les changent pas
  touche.paiement = !nouveau;
  touche.participants = !nouveau;
  // catégorie et nature choisies à la main : le carnet des commerçants ne les change pas
  touche.categorie = !nouveau;
  touche.objet = !nouveau;
  $$('input[name=categorie]').forEach(function (r) { r.addEventListener('change', function (e) { if (e.isTrusted) touche.categorie = true; }); });
  var champObjet = $('[name=objet]');
  if (champObjet) champObjet.addEventListener('input', function () { touche.objet = true; });
  $$('input[name=paiement]').forEach(function (r) { r.addEventListener('change', function (e) { if (e.isTrusted) touche.paiement = true; }); });
  $$('input[name="participants[]"]').forEach(function (r) { r.addEventListener('change', function (e) { if (e.isTrusted) touche.participants = true; }); });

  /* ---------- Carnet des commerçants ---------- */
  var commercants = [];
  var categorieDuCarnet = false; // catégorie habituelle du commerçant : les annotations ne la changent pas
  try { commercants = JSON.parse(form.getAttribute('data-commercants') || '[]'); } catch (e) { commercants = []; }

  // Propose ce que l'on sait du commerçant, sans écraser ce que la personne a saisi.
  // depuisLecture : reconnu sur la photo (le nom du carnet remplace alors le nom lu, souvent imparfait)
  function appliquerCommercant(c, depuisLecture) {
    if (depuisLecture && !touche.lieu) remplir('lieu', c.n);
    var v = $('[name=ville]');
    if (c.v && v && (!touche.ville || !v.value.trim())) remplir('ville', c.v);
    var s = $('[name=siret]');
    if (c.s && s && !s.value.trim()) remplir('siret', c.s);
    if (c.c && c.c !== 'km' && !touche.categorie) {
      var r = $('input[name=categorie][value=' + c.c + ']');
      if (r && !r.checked) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }
      categorieDuCarnet = !!r;
    }
    var o = $('[name=objet]');
    if (c.o && o && !touche.objet && !o.value.trim()) remplir('objet', c.o);
  }

  // Choix dans la liste (ou nom tapé en entier) : ville, catégorie, nature habituelles
  var champLieu = $('[name=lieu]');
  var dernierChoisi = '';
  if (champLieu && commercants.length && window.NDF && window.NDF.cleNom) {
    champLieu.addEventListener('input', function () {
      var cle = window.NDF.cleNom(champLieu.value);
      if (!cle || cle === dernierChoisi) return;
      var memes = commercants.filter(function (c) { return window.NDF.cleNom(c.n) === cle; });
      if (!memes.length) return;
      var villeSaisie = window.NDF.cleNom(($('[name=ville]') || {}).value || '');
      var c = memes.filter(function (x) { return villeSaisie && window.NDF.cleNom(x.v) === villeSaisie; })[0] || memes[0];
      dernierChoisi = cle;
      appliquerCommercant(c, false);
    });
  }
  // SIRET tapé : charge le commerçant correspondant
  var champSiret = $('[name=siret]');
  if (champSiret && commercants.length) {
    champSiret.addEventListener('input', function () {
      var s = champSiret.value.replace(/\D/g, '');
      if (s.length !== 14) return;
      var c = commercants.filter(function (x) { return x.s === s; })[0];
      if (!c) return;
      if (champLieu && !champLieu.value.trim()) remplir('lieu', c.n);
      appliquerCommercant(c, false);
    });
  }

  // Initiales des membres proposés comme participants (« Fabien (FC) »)
  function initialesMembres() {
    return $$('input[name="participants[]"]').map(function (c) {
      var sm = c.parentNode.querySelector('small');
      return { case: c, ini: sm ? sm.textContent.replace(/[()\s]/g, '').toUpperCase() : '' };
    }).filter(function (x) { return x.ini; });
  }

  // Équipe et invités
  var chkEquipe = document.getElementById('chk-equipe');
  var chkInvites = document.getElementById('chk-invites');
  var blocInvites = document.getElementById('bloc-invites');
  var aideEquipe = document.getElementById('aide-equipe');
  function majParticipants(focus) {
    if (aideEquipe && chkEquipe) aideEquipe.hidden = !chkEquipe.checked;
    if (blocInvites && chkInvites) {
      blocInvites.hidden = !chkInvites.checked;
      var champ = blocInvites.querySelector('input');
      champ.required = chkInvites.checked;
      if (focus === true && chkInvites.checked) champ.focus();
    }
  }
  if (chkEquipe) chkEquipe.addEventListener('change', majParticipants);
  if (chkInvites) chkInvites.addEventListener('change', function () { majParticipants(true); });
  majParticipants();

  // Ticket sans photo
  var chkSans = document.getElementById('chk-sans-justif');
  function majSansJustif() {
    var on = chkSans && chkSans.checked && categorie() !== 'km';
    var aide = document.getElementById('aide-sans-justif');
    if (aide) aide.hidden = !on;
    var o = document.getElementById('desc-obligatoire'), f = document.getElementById('desc-facultatif');
    if (o) o.hidden = !on;
    if (f) f.hidden = on;
    var boutons = form.querySelector('.photo-boutons');
    if (boutons) boutons.style.opacity = on ? '.5' : '';
  }
  if (chkSans) chkSans.addEventListener('change', majSansJustif);

  // Alerte : 150 € HT ou plus sans facture demandée
  var alerte = document.getElementById('alerte-150');
  var chkFacture = document.getElementById('chk-facture');
  function estimationHT() {
    var ttc = lireNombre(($('input[name=montant]') || {}).value);
    var champTva = $('input[name=montant_tva]');
    var tva = champTva && champTva.value.trim() !== '' ? lireNombre(champTva.value) : null;
    if (tva !== null) return { ht: ttc - tva, estime: false };
    return { ht: ttc / (categorie() === 'repas' ? 1.10 : 1.20), estime: true };
  }
  function majAlerte() {
    if (!alerte) return;
    var seuil = parseFloat(alerte.getAttribute('data-seuil') || '150');
    var e = estimationHT();
    var montrer = categorie() !== 'km' && !(chkFacture && chkFacture.checked) && e.ht >= seuil;
    alerte.hidden = !montrer;
    var det = document.getElementById('alerte-150-ht');
    if (det) det.textContent = montrer ? ' Montant : ' + (e.estime ? 'environ ' : '') + formatEuro(Math.round(e.ht * 100) / 100) + ' HT.' : '';
  }
  ['montant', 'montant_tva'].forEach(function (n) {
    var el = $('input[name=' + n + ']');
    if (el) el.addEventListener('input', majAlerte);
  });
  if (chkFacture) chkFacture.addEventListener('change', majAlerte);
  $$('input[name=categorie]').forEach(function (r) { r.addEventListener('change', function () { majAlerte(); majSansJustif(); }); });
  majAlerte();
  majSansJustif();

  // Fenêtre SIREN à montrer au commerçant
  var fond = document.getElementById('siren-fond');
  var btnSiren = document.getElementById('btn-siren');
  if (fond && btnSiren) {
    btnSiren.addEventListener('click', function () { fond.hidden = false; });
    fond.addEventListener('click', function (e) {
      if (e.target === fond || e.target.id === 'btn-siren-fermer') fond.hidden = true;
    });
  }

  if (form.querySelector('fieldset[disabled]')) return;

  /* ---------- Photo ---------- */
  var photoBlob = null;
  var etat = document.getElementById('ocr-etat');
  var apercu = document.getElementById('photo-apercu');

  function message(txt, classe) {
    if (!etat) return;
    etat.hidden = !txt;
    etat.textContent = txt || '';
    etat.className = 'ocr-etat' + (classe ? ' ' + classe : '');
  }

  function chargerImage(file) {
    return new Promise(function (ok, ko) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () { ok(img); };
      img.onerror = function () { ko(new Error("Image illisible")); };
      img.src = url;
    });
  }

  function versCanvas(source, largeurMax) {
    var w = source.naturalWidth || source.width;
    var h = source.naturalHeight || source.height;
    var r = Math.min(1, largeurMax / Math.max(w, h));
    var c = document.createElement('canvas');
    c.width = Math.round(w * r);
    c.height = Math.round(h * r);
    var ctx = c.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, c.width, c.height);
    ctx.drawImage(source, 0, 0, c.width, c.height);
    return c;
  }

  // PDF : les 3 premières pages sont rendues en image, les unes sous les autres
  function pdfVersCanvas(file) {
    if (!window.pdfjsLib) return Promise.reject(new Error('Lecteur PDF indisponible'));
    window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/vendor/pdfjs/pdf.worker.min.js';
    return file.arrayBuffer().then(function (buf) {
      return window.pdfjsLib.getDocument({ data: buf, isEvalSupported: false }).promise;
    }).then(function (doc) {
      var n = Math.min(doc.numPages, 3);
      var pages = [];
      var chaine = Promise.resolve();
      for (var i = 1; i <= n; i++) {
        (function (num) {
          chaine = chaine.then(function () { return doc.getPage(num); }).then(function (page) {
            var vp0 = page.getViewport({ scale: 1 });
            var vp = page.getViewport({ scale: 1500 / vp0.width });
            var c = document.createElement('canvas');
            c.width = Math.round(vp.width);
            c.height = Math.round(vp.height);
            var ctx = c.getContext('2d');
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, c.width, c.height);
            return page.render({ canvasContext: ctx, viewport: vp }).promise.then(function () { pages.push(c); });
          });
        })(i);
      }
      return chaine.then(function () {
        var total = pages.reduce(function (s, p) { return s + p.height; }, 0);
        var out = document.createElement('canvas');
        out.width = 1500;
        out.height = total;
        var ctx = out.getContext('2d');
        var y = 0;
        pages.forEach(function (p) { ctx.drawImage(p, 0, y); y += p.height; });
        return out;
      });
    });
  }

  function canvasVersBlob(c, qualite) {
    return new Promise(function (ok) { c.toBlob(ok, 'image/jpeg', qualite); });
  }

  // Image en niveaux de gris, contraste étiré : meilleure lecture des tickets thermiques
  function preparerOcr(c) {
    var o = versCanvas(c, 1600);
    var ctx = o.getContext('2d');
    var d = ctx.getImageData(0, 0, o.width, o.height);
    var px = d.data, min = 255, max = 0, i, g;
    for (i = 0; i < px.length; i += 4) {
      g = 0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2];
      px[i] = g;
      if (g < min) min = g;
      if (g > max) max = g;
    }
    var etendue = Math.max(1, max - min);
    for (i = 0; i < px.length; i += 4) {
      g = (px[i] - min) * 255 / etendue;
      px[i] = px[i + 1] = px[i + 2] = g;
    }
    ctx.putImageData(d, 0, 0);
    return o;
  }

  // Photo d'origine (avant recadrage) : conservée et envoyée en plus du ticket recadré
  var photoOrigBlob = null;
  var canvasOrigine = null;
  var derniersCoins = null;
  var btnRecadrer = null;

  function afficherJustificatif(canvas) {
    return canvasVersBlob(canvas, 0.82).then(function (blob) {
      photoBlob = blob;
      apercu.innerHTML = '';
      var im = new Image();
      im.alt = 'Justificatif';
      im.src = URL.createObjectURL(blob);
      apercu.appendChild(im);
      apercu.classList.add('a-image');
      lireTicket(preparerOcr(canvas));
    });
  }

  // Recadrage « façon scanner » (photo seulement, pas pour les PDF)
  function recadrer(canvas) {
    if (!window.NDF.recadrer) return Promise.resolve({ canvas: canvas, recadre: false });
    return window.NDF.recadrer(canvas, derniersCoins).then(function (r) {
      derniersCoins = r.coins;
      return r;
    });
  }

  function montrerBoutonRecadrer() {
    if (btnRecadrer || !apercu) return;
    btnRecadrer = document.createElement('button');
    btnRecadrer.type = 'button';
    btnRecadrer.className = 'btn btn-lien';
    btnRecadrer.textContent = '✂ Ajuster le recadrage';
    apercu.parentNode.insertBefore(btnRecadrer, apercu.nextSibling);
    btnRecadrer.addEventListener('click', function () {
      if (!canvasOrigine) return;
      recadrer(canvasOrigine).then(function (r) {
        return canvasVersBlob(canvasOrigine, 0.8).then(function (orig) {
          photoOrigBlob = r.recadre ? orig : null;
          return afficherJustificatif(r.canvas);
        });
      });
    });
  }

  $$('input[data-photo]').forEach(function (input) {
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file) return;
      var estPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
      message(estPdf ? 'Ouverture du PDF…' : 'Préparation de la photo…');
      input.value = '';
      photoOrigBlob = null;
      canvasOrigine = null;
      derniersCoins = null;
      if (btnRecadrer) btnRecadrer.hidden = true;
      var p = estPdf ? pdfVersCanvas(file) : chargerImage(file).then(function (img) { return versCanvas(img, 1800); });
      p.then(function (canvas) {
        if (estPdf) {
          return afficherJustificatif(canvas);
        }
        message('');
        canvasOrigine = canvas;
        return recadrer(canvas).then(function (r) {
          montrerBoutonRecadrer();
          btnRecadrer.hidden = false;
          return canvasVersBlob(canvas, 0.8).then(function (orig) {
            photoOrigBlob = r.recadre ? orig : null;
            return afficherJustificatif(r.canvas);
          });
        });
      }).catch(function (e) {
        message("Ce fichier n'a pas pu être lu (" + e.message + '). Essayez une photo.', 'ko');
      });
    });
  });

  /* ---------- Lecture automatique (Tesseract, dans le navigateur) ---------- */
  var workerPromesse = null;
  var lectureDiscrete = false; // 2e lecture (annotations) : pas de message de progression
  function abs(chemin) { return new URL(chemin, location.href).href.replace(/\/$/, ''); }
  function obtenirWorker() {
    if (!window.Tesseract) return Promise.reject(new Error('module de lecture indisponible'));
    if (!workerPromesse) {
      workerPromesse = window.Tesseract.createWorker('fra', 1, {
        workerPath: abs('assets/vendor/tesseract/worker.min.js'),
        corePath: abs('assets/vendor/tesseract/core/'),
        langPath: abs('assets/vendor/tesseract/lang'),
        workerBlobURL: false,
        logger: function (m) {
          if (lectureDiscrete) return;
          if (m.status === 'recognizing text') {
            message('Lecture du ticket… ' + Math.round(m.progress * 100) + ' %');
          } else if (/load/i.test(m.status)) {
            message('Chargement du module de lecture (une seule fois)…');
          }
        }
      });
    }
    return workerPromesse;
  }

  function lireTicket(canvas) {
    message('Lecture du ticket…');
    obtenirWorker().then(function (w) {
      return w.recognize(canvas);
    }).then(function (res) {
      var membres = initialesMembres();
      window.NDF.dernierTexte = res.data.text || '';
      var infos = window.NDF.analyserTicket(res.data.text || '', null,
        { initiales: membres.map(function (x) { return x.ini; }), commercants: commercants });
      var trouve = [];
      if (infos.montant && !touche.montant) {
        remplir('montant', infos.montant.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
        trouve.push('montant ' + formatEuro(infos.montant));
      }
      if (infos.date && !touche.date_depense) {
        remplir('date_depense', infos.date);
        trouve.push('date ' + infos.date.split('-').reverse().join('/'));
      }
      if (infos.lieu && !touche.lieu) {
        remplir('lieu', infos.lieu);
      }
      if (infos.ville && !touche.ville) {
        remplir('ville', infos.ville);
      }
      var compta = false;
      if (infos.siret && !touche.siret) {
        remplir('siret', infos.siret);
        compta = true;
      }
      if (infos.tva && !touche.montant_tva) {
        remplir('montant_tva', infos.tva.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
        trouve.push('TVA ' + formatEuro(infos.tva));
        compta = true;
      }
      if (infos.commercant) {
        appliquerCommercant(infos.commercant.commercant, true);
        trouve.unshift('commerçant ' + infos.commercant.commercant.n + (infos.commercant.par === 'siret' ? ' (par son SIRET)' : ''));
        if (infos.commercant.commercant.s) compta = true;
      }
      var det = form.querySelector('.details-compta');
      if (compta && det) det.open = true;
      var notes = appliquerAnnotations(infos.annotations || {}, membres);
      majAlerte();
      var msg = function () {
        var tout = trouve.concat(notes.length ? ['annotations ' + notes.join(', ')] : []);
        if (tout.length) {
          message('Lu sur le ticket : ' + tout.join(', ') + '. Vérifiez avant d\'enregistrer.', 'ok');
        } else {
          message('Montant et date non reconnus : saisissez-les à la main.', 'ko');
        }
      };
      msg();
      // 2e lecture en « texte épars » : trouve les mots isolés (annotations à la main
      // dans une marge), que la lecture normale du ticket laisse souvent de côté.
      return obtenirWorker().then(function (w) {
        lectureDiscrete = true;
        return w.setParameters({ tessedit_pageseg_mode: '11' })
          .then(function () { return w.recognize(canvas); })
          .then(function (res2) {
            window.NDF.texteEpars = res2.data.text || '';
            // seuls les mots absents de la lecture normale : ce qui est imprimé sur le ticket
            // (et donc déjà lu) ne peut pas passer pour une annotation à la main
            var connus = {};
            (res.data.text || '').split(/[^A-Za-zÀ-ÿ0-9]+/).forEach(function (m) { if (m) connus[m.toUpperCase()] = true; });
            // mots isolés uniquement : un trait « ------ » mal lu (« —cm-su— ») ne donne pas d'initiales
            var nouveaux = (res2.data.text || '').split(/\s+/).map(function (m) {
              return m.replace(/^[«"'(.,;:!?]+|[»"').,;:!?]+$/g, '');
            }).filter(function (m) { return /^[A-Za-zÀ-ÿ0-9]+$/.test(m) && !connus[m.toUpperCase()]; });
            var an2 = window.NDF.analyserTicket(nouveaux.join(' '), null,
              { initiales: membres.map(function (x) { return x.ini; }), minuscules: true }).annotations;
            notes = notes.concat(appliquerAnnotations(an2, membres, notes));
            msg();
          })
          .then(retablir, retablir);
        function retablir() { lectureDiscrete = false; return w.setParameters({ tessedit_pageseg_mode: '6' }); }
      });
    }).catch(function () {
      message('Lecture automatique indisponible : saisissez la date et le montant.', 'ko');
    });
  }

  // Annotations manuscrites : PERSO / ESPECES (moyen de paiement) et initiales (participants au repas).
  // Ne change jamais un choix fait à la main. Retourne les nouvelles notes (pour le message).
  function appliquerAnnotations(an, membres, deja) {
    deja = deja || [];
    var notes = [];
    var choixPaiement = an.perso ? 'cb_perso' : (an.especes ? 'especes' : '');
    var notePaie = an.perso ? 'PERSO → CB perso' : 'ESPÈCES → espèces';
    if (choixPaiement && !touche.paiement && deja.indexOf(notePaie) < 0) {
      var radio = $('input[name=paiement][value=' + choixPaiement + ']');
      if (radio && !radio.checked) {
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
      }
      notes.push(notePaie);
    }
    var nouvelles = (an.initiales || []).filter(function (ini) {
      return !deja.some(function (n) { return n.indexOf(ini) >= 0 && n.indexOf('participants') >= 0; });
    });
    // commerçant connu pour autre chose qu'un repas (carburant…) : pas de participants à cocher
    if (nouvelles.length && !touche.participants && !(categorieDuCarnet && categorie() !== 'repas')) {
      if (categorie() !== 'repas') {
        var rRepas = $('input[name=categorie][value=repas]');
        if (rRepas) { rRepas.checked = true; rRepas.dispatchEvent(new Event('change', { bubbles: true })); }
      }
      membres.forEach(function (x) {
        if (nouvelles.indexOf(x.ini) >= 0 && !x.case.checked) {
          x.case.checked = true;
          x.case.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
      notes.push(nouvelles.join(' + ') + ' → participants');
    }
    return notes;
  }

  function remplir(nom, val) {
    var el = $('[name=' + nom + ']');
    if (!el) return;
    if (el.type === 'date' && el.max && val > el.max) return;
    el.value = val;
    el.classList.remove('champ-detecte');
    void el.offsetWidth;
    el.classList.add('champ-detecte');
  }

  /* ---------- Envoi ---------- */
  var btn = document.getElementById('btn-enregistrer');
  var zoneErreur = document.getElementById('form-erreur');
  form.addEventListener('submit', function (e) {
    if (!window.fetch || !window.FormData) return; // envoi classique
    e.preventDefault();
    if (chkInvites && chkInvites.checked && categorie() === 'repas' && !blocInvites.querySelector('input').value.trim()) {
      zoneErreur.textContent = "Indiquez le nom de l'invité (ou décochez « Invité »).";
      zoneErreur.hidden = false;
      blocInvites.querySelector('input').focus();
      return;
    }
    envoyer({});
  });

  function envoyer(conf) {
    var fd = new FormData(form);
    fd.delete('photo');
    fd.delete('photo2');
    if (photoBlob) fd.append('photo', photoBlob, 'ticket.jpg');
    if (photoBlob && photoOrigBlob) fd.append('photo_originale', photoOrigBlob, 'ticket-original.jpg');
    if (conf.doublon) fd.set('confirm_doublon', '1');
    if (conf.seuil) fd.set('confirm_150', '1');
    if (conf.regul) fd.set('confirm_regul', '1');
    zoneErreur.hidden = true;
    btn.classList.add('charge');
    btn.textContent = 'Enregistrement…';
    fetch(form.action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.ok) {
          location.href = j.redirect;
          return;
        }
        btn.classList.remove('charge');
        btn.textContent = 'Enregistrer';
        if (j.doublon && window.confirm(j.error)) {
          conf.doublon = true;
          envoyer(conf);
          return;
        }
        if (j.confirmRegul && window.confirm(j.error)) {
          conf.regul = true;
          envoyer(conf);
          return;
        }
        if (j.confirm150 && window.confirm(j.error)) {
          conf.seuil = true;
          envoyer(conf);
          return;
        }
        zoneErreur.textContent = j.error || 'Erreur.';
        zoneErreur.hidden = false;
        zoneErreur.scrollIntoView({ behavior: 'smooth', block: 'center' });
      })
      .catch(function () {
        btn.classList.remove('charge');
        btn.textContent = 'Enregistrer';
        zoneErreur.textContent = "Envoi impossible. Vérifiez votre connexion et réessayez.";
        zoneErreur.hidden = false;
      });
  }
})();
