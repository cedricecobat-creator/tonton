"""Génère des images de tickets de caisse factices pour les tests."""
from PIL import Image, ImageDraw, ImageFont, ImageFilter
import random, io

import os
FONTS = 'C:/Windows/Fonts' if os.name == 'nt' else '/usr/share/fonts/truetype/dejavu'
MONO = FONTS + ('/consola.ttf' if os.name == 'nt' else '/DejaVuSansMono.ttf')
MONOB = FONTS + ('/consolab.ttf' if os.name == 'nt' else '/DejaVuSansMono-Bold.ttf')


def ticket(commercant, ville, date_fr, lignes, total, paiement='CB', rotation=0.0, bruit=True, siret=None):
    W = 600
    f = ImageFont.truetype(MONO, 24)
    fb = ImageFont.truetype(MONOB, 30)
    rows = []
    rows.append((commercant.upper(), fb, 'c'))
    rows.append((ville, f, 'c'))
    rows.append(('Tel 05 59 00 00 00', f, 'c'))
    if siret:
        rows.append(('SIRET ' + siret, f, 'c'))
    rows.append(('', f, 'l'))
    rows.append((f'{date_fr}  12:47   Caisse 2', f, 'l'))
    rows.append(('-' * 36, f, 'l'))
    ht = 0
    for lib, prix in lignes:
        rows.append((f'{lib[:24]:<24}{prix:>10.2f}'.replace('.', ','), f, 'l'))
    rows.append(('-' * 36, f, 'l'))
    rows.append((f'{"TOTAL TTC":<20}{total:>12.2f} EUR'.replace('.', ','), fb, 'l'))
    rows.append((f'{"dont TVA 10%":<24}{total/11:>10.2f}'.replace('.', ','), f, 'l'))
    rows.append((f'{paiement:<24}{total:>10.2f}'.replace('.', ','), f, 'l'))
    rows.append(('', f, 'l'))
    rows.append(('MERCI DE VOTRE VISITE', f, 'c'))
    H = 60 + 40 * len(rows)
    im = Image.new('RGB', (W, H), (250, 249, 245))
    d = ImageDraw.Draw(im)
    y = 30
    for txt, font, al in rows:
        if al == 'c':
            d.text((W / 2, y), txt, font=font, fill=(30, 30, 30), anchor='ma')
        else:
            d.text((30, y), txt, font=font, fill=(30, 30, 30))
        y += 40
    # fond « photo »
    fond = Image.new('RGB', (W + 160, H + 200), (120, 110, 95))
    fond.paste(im, (80, 100))
    if rotation:
        fond = fond.rotate(rotation, expand=True, fillcolor=(120, 110, 95))
    if bruit:
        fond = fond.filter(ImageFilter.GaussianBlur(0.8))
    fond = fond.resize((fond.width * 2, fond.height * 2))  # « photo » haute définition
    buf = io.BytesIO()
    fond.save(buf, 'JPEG', quality=88)
    return buf.getvalue()


