/*
 * Recadrage « façon scanner » d'une photo de ticket, entièrement sur le téléphone.
 *  1. détection du ticket (zone claire la plus grande) et de ses 4 coins ;
 *  2. écran d'ajustement : les 4 coins se déplacent au doigt ;
 *  3. redressement (perspective) et blanchiment du papier.
 *
 * Utilisation : NDF.recadrer(canvas[, coinsInitiaux]).then(function (r) { r.canvas, r.recadre, r.coins })
 */
(function () {
  'use strict';
  window.NDF = window.NDF || {};

  /* ------------------------------------------------------------ détection */

  function niveauxDeGris(canvas, tailleMax) {
    var r = Math.min(1, tailleMax / Math.max(canvas.width, canvas.height));
    var w = Math.max(1, Math.round(canvas.width * r));
    var h = Math.max(1, Math.round(canvas.height * r));
    var c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    var ctx = c.getContext('2d');
    ctx.drawImage(canvas, 0, 0, w, h);
    var px = ctx.getImageData(0, 0, w, h).data;
    var g = new Float32Array(w * h);
    for (var i = 0, j = 0; i < px.length; i += 4, j++) {
      g[j] = 0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2];
    }
    return { g: flou(g, w, h, 2), w: w, h: h, r: r };
  }

  // Flou par moyenne glissante (horizontal puis vertical)
  function flou(src, w, h, rayon) {
    var tmp = new Float32Array(w * h);
    var out = new Float32Array(w * h);
    var x, y, k, s, n;
    for (y = 0; y < h; y++) {
      for (x = 0; x < w; x++) {
        s = 0; n = 0;
        for (k = -rayon; k <= rayon; k++) {
          var xx = x + k;
          if (xx >= 0 && xx < w) { s += src[y * w + xx]; n++; }
        }
        tmp[y * w + x] = s / n;
      }
    }
    for (y = 0; y < h; y++) {
      for (x = 0; x < w; x++) {
        s = 0; n = 0;
        for (k = -rayon; k <= rayon; k++) {
          var yy = y + k;
          if (yy >= 0 && yy < h) { s += tmp[yy * w + x]; n++; }
        }
        out[y * w + x] = s / n;
      }
    }
    return out;
  }

  // Seuil d'Otsu : sépare au mieux les pixels clairs (papier) et sombres (fond)
  function seuilOtsu(g) {
    var hist = new Array(256).fill(0), i;
    for (i = 0; i < g.length; i++) hist[Math.min(255, Math.max(0, Math.round(g[i])))]++;
    var total = g.length, somme = 0;
    for (i = 0; i < 256; i++) somme += i * hist[i];
    var sB = 0, wB = 0, meilleur = 0, seuil = 128;
    for (i = 0; i < 256; i++) {
      wB += hist[i];
      if (!wB) continue;
      var wF = total - wB;
      if (!wF) break;
      sB += i * hist[i];
      var mB = sB / wB, mF = (somme - sB) / wF;
      var v = wB * wF * (mB - mF) * (mB - mF);
      if (v > meilleur) { meilleur = v; seuil = i; }
    }
    return seuil;
  }

  function coinsParDefaut(canvas) {
    var mx = canvas.width * 0.04, my = canvas.height * 0.04;
    return [
      { x: mx, y: my }, { x: canvas.width - mx, y: my },
      { x: canvas.width - mx, y: canvas.height - my }, { x: mx, y: canvas.height - my }
    ];
  }

  /** Coins du ticket [haut-gauche, haut-droit, bas-droit, bas-gauche] et fiabilité de la détection */
  function detecter(canvas) {
    var im = niveauxDeGris(canvas, 360);
    var w = im.w, h = im.h, g = im.g;
    var t = seuilOtsu(g);
    var clair = new Uint8Array(w * h), i;
    for (i = 0; i < g.length; i++) clair[i] = g[i] > t ? 1 : 0;

    // Plus grande zone claire d'un seul tenant
    var vu = new Int32Array(w * h).fill(-1);
    var pile = new Int32Array(w * h);
    var meilleure = -1, tailleMax = 0, num = 0;
    for (i = 0; i < w * h; i++) {
      if (!clair[i] || vu[i] !== -1) continue;
      var haut = 0, taille = 0;
      pile[haut++] = i;
      vu[i] = num;
      while (haut) {
        var p = pile[--haut];
        taille++;
        var x = p % w, y = (p - x) / w;
        if (x > 0 && clair[p - 1] && vu[p - 1] === -1) { vu[p - 1] = num; pile[haut++] = p - 1; }
        if (x < w - 1 && clair[p + 1] && vu[p + 1] === -1) { vu[p + 1] = num; pile[haut++] = p + 1; }
        if (y > 0 && clair[p - w] && vu[p - w] === -1) { vu[p - w] = num; pile[haut++] = p - w; }
        if (y < h - 1 && clair[p + w] && vu[p + w] === -1) { vu[p + w] = num; pile[haut++] = p + w; }
      }
      if (taille > tailleMax) { tailleMax = taille; meilleure = num; }
      num++;
    }
    var part = tailleMax / (w * h);
    if (meilleure < 0 || part < 0.06 || part > 0.97) {
      return { coins: coinsParDefaut(canvas), fiable: false };
    }

    // Coins = points extrêmes de la zone (x+y et x-y)
    var tl = null, tr = null, br = null, bl = null;
    var sMin = Infinity, sMax = -Infinity, dMin = Infinity, dMax = -Infinity;
    var bordsTouches = { g: 0, d: 0, h: 0, b: 0 };
    for (i = 0; i < w * h; i++) {
      if (vu[i] !== meilleure) continue;
      var xx = i % w, yy = (i - xx) / w, s = xx + yy, d = xx - yy;
      if (s < sMin) { sMin = s; tl = [xx, yy]; }
      if (s > sMax) { sMax = s; br = [xx, yy]; }
      if (d > dMax) { dMax = d; tr = [xx, yy]; }
      if (d < dMin) { dMin = d; bl = [xx, yy]; }
      if (xx === 0) bordsTouches.g++;
      if (xx === w - 1) bordsTouches.d++;
      if (yy === 0) bordsTouches.h++;
      if (yy === h - 1) bordsTouches.b++;
    }
    // Zone claire collée aux 4 bords : c'est sans doute le fond (nappe blanche…), pas le ticket
    var fiable = !(bordsTouches.g > h * 0.5 && bordsTouches.d > h * 0.5 && bordsTouches.h > w * 0.5 && bordsTouches.b > w * 0.5);
    var k = 1 / im.r;
    var vers = function (p) {
      return { x: Math.min(canvas.width, Math.max(0, (p[0] + 0.5) * k)), y: Math.min(canvas.height, Math.max(0, (p[1] + 0.5) * k)) };
    };
    var coins = [vers(tl), vers(tr), vers(br), vers(bl)];
    if (aire(coins) < canvas.width * canvas.height * 0.05) {
      return { coins: coinsParDefaut(canvas), fiable: false };
    }
    return { coins: coins, fiable: fiable };
  }

  function aire(c) {
    var s = 0;
    for (var i = 0; i < 4; i++) {
      var a = c[i], b = c[(i + 1) % 4];
      s += a.x * b.y - b.x * a.y;
    }
    return Math.abs(s) / 2;
  }

  /* ------------------------------------------------------- redressement */

  function dist(a, b) { return Math.hypot(a.x - b.x, a.y - b.y); }

  // Homographie qui envoie le rectangle (0,0)-(W,H) sur le quadrilatère source
  function homographie(W, H, q) {
    var src = [[0, 0], [W, 0], [W, H], [0, H]];
    var A = [], B = [];
    for (var i = 0; i < 4; i++) {
      var u = src[i][0], v = src[i][1], x = q[i].x, y = q[i].y;
      A.push([u, v, 1, 0, 0, 0, -u * x, -v * x]); B.push(x);
      A.push([0, 0, 0, u, v, 1, -u * y, -v * y]); B.push(y);
    }
    // Élimination de Gauss
    for (var c = 0; c < 8; c++) {
      var piv = c;
      for (var r = c + 1; r < 8; r++) if (Math.abs(A[r][c]) > Math.abs(A[piv][c])) piv = r;
      var t = A[c]; A[c] = A[piv]; A[piv] = t;
      var tb = B[c]; B[c] = B[piv]; B[piv] = tb;
      for (r = c + 1; r < 8; r++) {
        var f = A[r][c] / A[c][c];
        for (var k = c; k < 8; k++) A[r][k] -= f * A[c][k];
        B[r] -= f * B[c];
      }
    }
    var hh = new Array(8);
    for (c = 7; c >= 0; c--) {
      var s = B[c];
      for (k = c + 1; k < 8; k++) s -= A[c][k] * hh[k];
      hh[c] = s / A[c][c];
    }
    return hh;
  }

  function redresser(canvas, q) {
    var W = Math.round(Math.max(dist(q[0], q[1]), dist(q[3], q[2])));
    var H = Math.round(Math.max(dist(q[0], q[3]), dist(q[1], q[2])));
    var r = Math.min(1, 1800 / Math.max(W, H));
    W = Math.max(50, Math.round(W * r));
    H = Math.max(50, Math.round(H * r));
    var m = homographie(W, H, q);
    var sw = canvas.width, sh = canvas.height;
    var sd = canvas.getContext('2d').getImageData(0, 0, sw, sh).data;
    var out = document.createElement('canvas');
    out.width = W;
    out.height = H;
    var octx = out.getContext('2d');
    var od = octx.createImageData(W, H);
    var o = od.data;
    for (var v = 0; v < H; v++) {
      for (var u = 0; u < W; u++) {
        var den = m[6] * u + m[7] * v + 1;
        var x = (m[0] * u + m[1] * v + m[2]) / den;
        var y = (m[3] * u + m[4] * v + m[5]) / den;
        var x0 = Math.floor(x), y0 = Math.floor(y);
        var oi = (v * W + u) * 4;
        if (x0 < 0 || y0 < 0 || x0 >= sw - 1 || y0 >= sh - 1) {
          o[oi] = o[oi + 1] = o[oi + 2] = 255; o[oi + 3] = 255;
          continue;
        }
        var fx = x - x0, fy = y - y0;
        var i00 = (y0 * sw + x0) * 4, i10 = i00 + 4, i01 = i00 + sw * 4, i11 = i01 + 4;
        for (var c = 0; c < 3; c++) {
          o[oi + c] = (sd[i00 + c] * (1 - fx) + sd[i10 + c] * fx) * (1 - fy) + (sd[i01 + c] * (1 - fx) + sd[i11 + c] * fx) * fy;
        }
        o[oi + 3] = 255;
      }
    }
    octx.putImageData(od, 0, 0);
    return out;
  }

  /* --------------------------------------------------------- blanchiment */

  // Le papier devient blanc (ombres et teinte retirées), l'encre reste foncée
  function blanchir(canvas) {
    var W = canvas.width, H = canvas.height;
    var ctx = canvas.getContext('2d');
    var id = ctx.getImageData(0, 0, W, H);
    var px = id.data;
    var B = 40; // taille des blocs pour estimer l'éclairage du papier
    var gw = Math.ceil(W / B), gh = Math.ceil(H / B);
    var fond = new Float32Array(gw * gh);
    var bx, by, x, y, i;
    for (by = 0; by < gh; by++) {
      for (bx = 0; bx < gw; bx++) {
        var hist = new Uint32Array(256), n = 0;
        for (y = by * B; y < Math.min(H, (by + 1) * B); y += 2) {
          for (x = bx * B; x < Math.min(W, (bx + 1) * B); x += 2) {
            i = (y * W + x) * 4;
            hist[(0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2]) | 0]++;
            n++;
          }
        }
        // 90e centile : la couleur du papier, même si le bloc contient du texte
        var cible = n * 0.9, cumul = 0, val = 255;
        for (var k = 0; k < 256; k++) { cumul += hist[k]; if (cumul >= cible) { val = k; break; } }
        fond[by * gw + bx] = Math.max(60, val);
      }
    }
    for (y = 0; y < H; y++) {
      var gy = Math.min(gh - 1, Math.max(0, y / B - 0.5));
      var y0 = Math.floor(gy), y1 = Math.min(gh - 1, y0 + 1), fy = gy - y0;
      for (x = 0; x < W; x++) {
        var gx = Math.min(gw - 1, Math.max(0, x / B - 0.5));
        var x0 = Math.floor(gx), x1 = Math.min(gw - 1, x0 + 1), fx = gx - x0;
        var f = (fond[y0 * gw + x0] * (1 - fx) + fond[y0 * gw + x1] * fx) * (1 - fy)
              + (fond[y1 * gw + x0] * (1 - fx) + fond[y1 * gw + x1] * fx) * fy;
        i = (y * W + x) * 4;
        for (var c = 0; c < 3; c++) {
          var nrm = Math.min(1, px[i + c] / f);
          px[i + c] = nrm > 0.92 ? 255 : 255 * Math.pow(nrm, 1.8);
        }
      }
    }
    ctx.putImageData(id, 0, 0);
    return canvas;
  }

  /* -------------------------------------------------- écran d'ajustement */

  function ajuster(canvas, coins, fiable) {
    return new Promise(function (ok) {
      var fondEcran = document.createElement('div');
      fondEcran.className = 'recadre-fond';
      fondEcran.setAttribute('role', 'dialog');
      fondEcran.setAttribute('aria-label', 'Recadrer le ticket');
      fondEcran.innerHTML =
        '<p class="recadre-titre">' + (fiable ? 'Vérifiez les 4 coins du ticket' : 'Placez les 4 coins sur le ticket') + '</p>' +
        '<div class="recadre-zone"><div class="recadre-cadre"></div></div>' +
        '<div class="recadre-boutons">' +
        '<button type="button" class="btn btn-secondaire" data-r="entiere">Photo entière</button>' +
        '<button type="button" class="btn btn-primaire" data-r="valider">Valider</button>' +
        '</div>';
      document.body.appendChild(fondEcran);
      document.body.classList.add('sans-defilement');
      var cadre = fondEcran.querySelector('.recadre-cadre');
      var zone = fondEcran.querySelector('.recadre-zone');
      var image = document.createElement('img');
      image.src = canvas.toDataURL('image/jpeg', 0.85);
      image.alt = '';
      cadre.appendChild(image);
      var NS = 'http://www.w3.org/2000/svg';
      var svg = document.createElementNS(NS, 'svg');
      svg.setAttribute('viewBox', '0 0 ' + canvas.width + ' ' + canvas.height);
      svg.setAttribute('preserveAspectRatio', 'none');
      var masque = document.createElementNS(NS, 'path');
      masque.setAttribute('class', 'recadre-masque');
      masque.setAttribute('fill-rule', 'evenodd');
      var poly = document.createElementNS(NS, 'polygon');
      poly.setAttribute('class', 'recadre-poly');
      svg.appendChild(masque);
      svg.appendChild(poly);
      cadre.appendChild(svg);
      var poignees = coins.map(function (c, idx) {
        var p = document.createElement('div');
        p.className = 'recadre-coin';
        p.setAttribute('aria-label', ['Coin haut gauche', 'Coin haut droit', 'Coin bas droit', 'Coin bas gauche'][idx]);
        cadre.appendChild(p);
        return p;
      });

      function dimensionner() {
        var dispoW = zone.clientWidth, dispoH = zone.clientHeight;
        var r = Math.min(dispoW / canvas.width, dispoH / canvas.height);
        cadre.style.width = Math.round(canvas.width * r) + 'px';
        cadre.style.height = Math.round(canvas.height * r) + 'px';
        dessiner();
      }
      function dessiner() {
        var pts = coins.map(function (c) { return c.x + ',' + c.y; }).join(' ');
        poly.setAttribute('points', pts);
        masque.setAttribute('d', 'M0,0H' + canvas.width + 'V' + canvas.height + 'H0Z M' +
          coins.map(function (c) { return c.x + ',' + c.y; }).join(' L') + 'Z');
        var r = cadre.clientWidth / canvas.width;
        poignees.forEach(function (p, idx) {
          p.style.left = (coins[idx].x * r) + 'px';
          p.style.top = (coins[idx].y * r) + 'px';
        });
      }
      poignees.forEach(function (p, idx) {
        p.addEventListener('pointerdown', function (e) {
          e.preventDefault();
          p.setPointerCapture(e.pointerId);
          p.classList.add('actif');
          function bouger(ev) {
            var b = cadre.getBoundingClientRect();
            var r = canvas.width / b.width;
            coins[idx] = {
              x: Math.min(canvas.width, Math.max(0, (ev.clientX - b.left) * r)),
              y: Math.min(canvas.height, Math.max(0, (ev.clientY - b.top) * r))
            };
            dessiner();
          }
          function fin() {
            p.classList.remove('actif');
            p.removeEventListener('pointermove', bouger);
            p.removeEventListener('pointerup', fin);
            p.removeEventListener('pointercancel', fin);
          }
          p.addEventListener('pointermove', bouger);
          p.addEventListener('pointerup', fin);
          p.addEventListener('pointercancel', fin);
        });
      });
      window.addEventListener('resize', dimensionner);
      image.onload = dimensionner;
      setTimeout(dimensionner, 0);

      fondEcran.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-r]');
        if (!b) return;
        var choix = b.getAttribute('data-r');
        window.removeEventListener('resize', dimensionner);
        document.body.classList.remove('sans-defilement');
        fondEcran.remove();
        ok(choix === 'valider' ? { valider: true, coins: coins } : { valider: false, coins: coins });
      });
    });
  }

  /* ------------------------------------------------------------ entrée */

  window.NDF.recadrer = function (canvas, coinsInitiaux) {
    var det = coinsInitiaux ? { coins: coinsInitiaux.map(function (c) { return { x: c.x, y: c.y }; }), fiable: true } : detecter(canvas);
    return ajuster(canvas, det.coins, det.fiable).then(function (choix) {
      if (!choix.valider) {
        return { canvas: canvas, recadre: false, coins: choix.coins };
      }
      return { canvas: blanchir(redresser(canvas, choix.coins)), recadre: true, coins: choix.coins };
    });
  };
  // Exposé pour les tests
  window.NDF._recadrage = { detecter: detecter, redresser: redresser, blanchir: blanchir };
})();
