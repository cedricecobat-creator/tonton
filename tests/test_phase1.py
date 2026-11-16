"""
Tests automatiques de la phase 1 de Tonton (admin + associés), sur une installation
LOCALE et VIDE (base jetable). Ne jamais lancer sur la base de production.

Usage :
  TONTON_CONFIG=/chemin/tonton-config.php  php -S 127.0.0.1:8080   (dans le dossier du site)
  python tests/test_phase1.py http://127.0.0.1:8080 <clé d'installation> <php.exe> <tonton-config.php>
"""
import io
import json
import os
import re
import subprocess
import sys
import zipfile

import requests

sys.path.insert(0, os.path.dirname(__file__))
from ticket_img import ticket  # noqa: E402

BASE = sys.argv[1].rstrip('/')
CLE = sys.argv[2]
PHP = sys.argv[3]
CONFIG = sys.argv[4]
B = BASE + '/index.php'
ICI = os.path.dirname(os.path.abspath(__file__))
erreurs = 0


def check(cond, msg):
    global erreurs
    print(('OK   ' if cond else 'ERR  ') + msg)
    if not cond:
        erreurs += 1


def tok(html):
    m = re.search(r'name="csrf" (?:value|content)="([a-f0-9]+)"', html)
    return m.group(1) if m else ''


def flashes(html):
    return ' | '.join(re.findall(r'class="alerte alerte-\w+" role="status">([^<]+)', html))


def session(email, pw, attendu=True):
    s = requests.Session()
    t = tok(s.get(B, params={'p': 'login'}).text)
    r = s.post(B + '?p=login', data={'csrf': t, 'email': email, 'password': pw})
    connecte = 'barre-compte' in r.text
    if attendu:
        assert connecte, 'connexion échouée ' + email
    s.csrf = tok(r.text)
    s.derniere = r
    return s


# ---------------------------------------------------------------- installation
r = requests.get(BASE + '/install.php')
s0 = requests.Session()
html = s0.get(BASE + '/install.php').text
check('Installation' in html, "page d'installation")
r = s0.post(BASE + '/install.php', data={'csrf': tok(html), 'cle': 'mauvaise-cle-123', 'prenom': 'Admin', 'nom': 'ecoBAT',
                                         'initiales': 'ADM', 'email': 'admin@test.local', 'password': 'motdepasse123', 'password2': 'motdepasse123'})
check('Clé d' in r.text and 'incorrecte' in r.text, "installation refusée sans la bonne clé")
r = s0.post(BASE + '/install.php', data={'csrf': tok(html), 'cle': CLE, 'prenom': 'Admin', 'nom': 'ecoBAT',
                                         'initiales': 'ADM', 'email': 'admin@test.local', 'password': 'motdepasse123', 'password2': 'motdepasse123'})
check('Installation terminée' in r.text, 'installation réussie')
r = requests.get(BASE + '/install.php')
check('déjà installé' in r.text and 'name="cle"' not in r.text, "installation impossible une 2e fois")

admin = session('admin@test.local', 'motdepasse123')
check('Tickets' in admin.derniere.text and 'Gestion' in admin.derniere.text, 'admin : navigation admin (Tickets, Gestion)')
check('Ma fiche' not in admin.derniere.text, "admin : pas de fiche par défaut")

# ---------------------------------------------------------------- utilisateurs
for p, n, i, e in [('Fabien', 'Test', 'FC', 'fabien@test.local'), ('David', 'Test', 'DP', 'david@test.local')]:
    r = admin.post(B + '?p=utilisateur_save', data={'csrf': admin.csrf, 'id': 0, 'prenom': p, 'nom': n, 'initiales': i,
                                                    'email': e, 'role': 'associe', 'coef_km': '0,382', 'actif': 1, 'password': 'associe12345'})
    check(e in r.text, f'création associé {p}')
r = admin.post(B + '?p=utilisateur_save', data={'csrf': admin.csrf, 'id': 0, 'prenom': 'X', 'nom': 'Y', 'initiales': 'XY',
                                                'email': 'fabien@test.local', 'role': 'associe', 'coef_km': '0,382', 'actif': 1, 'password': 'associe12345'})
check('déjà utilisée' in r.text, 'e-mail en double refusé')
r = admin.post(B + '?p=utilisateur_save', data={'csrf': admin.csrf, 'id': 0, 'prenom': 'Jeremy', 'nom': 'Test', 'initiales': 'JB',
                                                'email': 'jeremy@test.local', 'role': 'salarie', 'coef_km': '0,382', 'actif': 1, 'password': 'salarie12345'})
check('Choisissez le rôle' in r.text, 'rôle salarié refusé tant que le module est désactivé')
check('value="salarie"' not in admin.get(B, params={'p': 'utilisateur'}).text, 'rôle salarié absent du formulaire (module désactivé)')

fabien = session('fabien@test.local', 'associe12345')
david = session('david@test.local', 'associe12345')
check('Ma fiche' in fabien.derniere.text and 'Gestion' not in fabien.derniere.text, 'associé : navigation associé, sans Gestion')

html = fabien.get(B, params={'p': 'depense'}).text
ids = {v: k for k, v in re.findall(r'name="participants\[\]" value="(\d+)"[^>]*><span>([^ <]+)', html)}
FC, DP = ids['Fabien'], ids['David']
ordre = re.findall(r'name="participants\[\]" value="\d+"[^>]*><span>([^ <]+)', html)
check('Admin' not in ordre and 'Cédric' in ordre, 'compte admin absent des participants, membre « Cédric (CM) » conservé')
check(ordre[:2] == ['Fabien', 'David'], 'participants : Fabien (qui saisit) puis David en tête ' + str(ordre[:3]))


def ajout(s, date, cat, montant, paiement, parts=(), lieu='COMMERCE', ville='BAYONNE', objet='', midi=True, img=True,
          kms=None, extra=None):
    data = {'csrf': s.csrf, 'id': 0, 'date_depense': date, 'categorie': cat, 'paiement': paiement,
            'montant': str(montant).replace('.', ','), 'lieu': lieu, 'ville': ville, 'objet': objet}
    if midi:
        data['repas_midi'] = 1
    if kms is not None:
        data['kms'] = kms
    data['participants[]'] = list(parts)
    if extra:
        data.update(extra)
    files = {}
    if img:
        files['photo'] = ('t.jpg', ticket(lieu, ville, f'{date[8:]}/{date[5:7]}/{date[:4]}', [('Article', montant)], montant), 'image/jpeg')
    r = s.post(B + '?p=depense_save', data=data, files=files, headers={'X-Requested-With': 'fetch'})
    try:
        return r.json()
    except ValueError:
        return {'ok': False, 'error': r.text[:300]}


# ---------------------------------------------------------------- moyen de paiement par défaut
check('value="cb_perso" checked' in html, 'nouveau ticket : CB perso proposée par défaut')
r = david.post(B + '?p=compte_paiement', data={'csrf': david.csrf, 'paiement_defaut': 'cb_pro'})
check('CB entreprise' in r.text, 'associé : choisit « CB entreprise » par défaut dans son compte')
check('value="cb_pro" checked' in david.get(B, params={'p': 'depense'}).text, 'nouveau ticket de David : CB entreprise cochée')
r = david.post(B + '?p=compte_paiement', data={'csrf': david.csrf, 'paiement_defaut': 'cb_perso'})

# ---------------------------------------------------------------- septembre 2026
d = '2026-09-'
res = [
    ajout(fabien, d + '12', 'repas', 102.30, 'cb_perso', [FC], 'BRASSERIE LE BERRY', 'PAU', 'Restaurant + invité', midi=False,
          extra={'avec_invites': 1, 'invites': 'M. Client'}),
    ajout(fabien, d + '15', 'repas', 24.28, 'cb_perso', [FC], 'CARREFOUR', 'BAYONNE'),
    ajout(fabien, d + '21', 'repas', 20.00, 'cb_perso', [FC], 'JACQUES A DIT', 'ST PIERRE IRUBE'),
    ajout(fabien, d + '22', 'repas', 20.90, 'cb_perso', [FC], "A L'OMBRE DES PALMIERS", 'TARNOS'),
    ajout(fabien, d + '28', 'autres', 29.98, 'cb_perso', [], 'DARTY', 'BAYONNE', 'Câble réseau et chargeur', midi=False),
]
check(all(x['ok'] for x in res), 'tickets CB perso de Fabien ' + str([x.get('error') for x in res if not x['ok']]))
for j in ['01', '03', '04', '07', '28']:
    check(ajout(fabien, d + j, 'repas', 38.0 + int(j), 'cb_pro', [FC, DP], 'RESTAURANT LE MIDI')['ok'], f'repas CB entreprise FC+DP le {j}')
for j in ['08', '14', '16', '18', '24', '25']:
    check(ajout(fabien, d + j, 'repas', 15.0 + int(j) / 10, 'cb_pro', [FC], 'SNACK')['ok'], f'repas CB entreprise FC le {j}')
for j in ['02', '09', '11', '30']:
    check(ajout(david, d + j, 'repas', 14.5, 'cb_pro', [DP], 'BOULANGERIE')['ok'], f'repas CB entreprise DP le {j}')

# Contrôles de saisie
r = ajout(fabien, d + '21', 'repas', 20.00, 'cb_perso', [FC], 'JACQUES A DIT')
check(not r['ok'] and r.get('doublon'), 'doublon probable détecté')
r = ajout(fabien, d + '05', 'repas', 10, 'cb_perso', [FC], img=False)
check(not r['ok'] and 'photo' in r['error'], 'photo obligatoire')
r = ajout(fabien, d + '05', 'repas', 10, 'cb_perso', [FC], img=False, extra={'sans_justificatif': 1})
check(not r['ok'] and 'description' in r['error'], '« Pas de ticket » : description obligatoire')
r = ajout(fabien, '2026-12-01', 'repas', 10, 'cb_perso', [FC])
check(not r['ok'] and 'futur' in r['error'], 'date future refusée')
r = ajout(fabien, d + '05', 'repas', 10, '', [FC])
check(not r['ok'] and 'paiement' in r['error'], 'moyen de paiement obligatoire')
r = ajout(fabien, d + '05', 'repas', 10, 'cb_perso', [FC], ville='')
check(not r['ok'] and 'ville' in r['error'], 'ville obligatoire')
r = ajout(fabien, d + '05', 'repas', 10, 'cb_perso', [FC], lieu='')
check(not r['ok'] and 'commerçant' in r['error'], 'commerçant obligatoire')
r = ajout(fabien, d + '05', 'repas', 10, 'cb_perso', [FC], extra={'avec_invites': 1, 'invites': ''})
check(not r['ok'] and 'invité' in r['error'], "nom de l'invité obligatoire")

# Absences : import Odoo
csv = ("personne;date_debut;date_fin;type;duree\n"
       "Fabien Test;11/09/2026;;Formation;1\n"
       "fabien@test.local;30/09/2026;;Formation;1\n"
       "DP;14/09/2026;25/09/2026;Congés payés;10\n"
       "DP;29/09/2026;;Formation;1\n"
       "FC;08/09/2026;;Congé;0,5\n"
       "Inconnu;08/09/2026;;Congé;1\n")
r = admin.post(B + '?p=absences_import', data={'csrf': admin.csrf, 'a': 2026, 'm': 9},
               files={'fichier': ('conges.csv', csv.encode('utf-8'), 'text/csv')})
check('13 jour(s) importé(s)' in r.text, 'import Odoo : 13 jours ' + flashes(r.text))
check('Inconnu' in r.text, 'import Odoo : personne inconnue signalée')


def totaux(a=2026, m=9):
    env = dict(os.environ, TONTON_CONFIG=CONFIG)
    out = subprocess.run([PHP, os.path.join(ICI, 'fiche_totaux.php'), str(a), str(m)], capture_output=True, text=True, env=env, encoding='utf-8')
    return json.loads(out.stdout)


t = totaux()
check(abs(t['Fabien']['total'] - 225.96) < 0.001 and t['Fabien']['jours_primes'] == [2, 9, 10, 17, 23, 29],
      f"NON-RÉGRESSION Fabien 225,96 € (6 primes : 2, 9, 10, 17, 23, 29) -> {t['Fabien']['total']} {t['Fabien']['jours_primes']}")
check(abs(t['David']['total'] - 9.50) < 0.001 and t['David']['jours_primes'] == [8, 10],
      f"NON-RÉGRESSION David 9,50 € (2 primes : 8, 10) -> {t['David']['total']} {t['David']['jours_primes']}")

# La fiche affichée à Fabien montre le même total
f = fabien.get(B, params={'p': 'fiche', 'a': 2026, 'm': 9}).text
check('225,96' in f, 'fiche de Fabien affiche 225,96 €')

# ---------------------------------------------------------------- droits
dep_darty = re.search(r'p=depense&amp;id=(\d+)"(?:(?!</a>).)*?DARTY', fabien.get(B, params={'p': 'accueil', 'a': 2026, 'm': 9}).text, re.S)
id_darty = dep_darty.group(1) if dep_darty else '0'
check(david.get(B, params={'p': 'depense', 'id': id_darty}).status_code == 404, "David ne voit pas un ticket personnel de Fabien")
check(david.get(B, params={'p': 'fichier', 'id': id_darty}).status_code == 404, "David n'accède pas à la photo de ce ticket")
r = david.post(B + '?p=depense_suppr', data={'csrf': david.csrf, 'id': id_darty})
check('impossible' in r.text and fabien.get(B, params={'p': 'depense', 'id': id_darty}).status_code == 200,
      "David ne peut pas supprimer le ticket de Fabien")
for page in ['gestion', 'utilisateurs', 'utilisateur', 'absences', 'export_xlsx', 'export_zip', 'export_pdf', 'journal', 'diagnostic']:
    check(david.get(B, params={'p': page, 'a': 2026, 'm': 9}).status_code == 403, f"associé : accès refusé à « {page} »")
r = david.post(B + '?p=utilisateur_save', data={'csrf': david.csrf, 'id': 0, 'prenom': 'Pirate', 'nom': 'X', 'initiales': 'PX',
                                                'email': 'pirate@test.local', 'role': 'admin', 'coef_km': '1', 'actif': 1, 'password': 'pirate123456'})
check(r.status_code == 403, "associé : création d'un compte refusée")
f = david.get(B, params={'p': 'fiche', 'a': 2026, 'm': 9, 'u': 2}).text
check('9,50' in f and '225,96' not in f, "David ne peut pas afficher la fiche de Fabien (on lui montre la sienne)")
r = ajout(david, d + '05', 'peage', 12, 'cb_perso', [], 'AUTOROUTE', 'BIRIATOU', midi=False, extra={'payeur_id': 2})
dav = david.get(B, params={'p': 'accueil', 'a': 2026, 'm': 9}).text
check(r['ok'] and 'AUTOROUTE' in dav, "un associé ne peut pas saisir au nom d'un autre (payeur ignoré)")
check('RESTAURANT LE MIDI' in dav, 'David voit les repas réglés par Fabien auxquels il a participé')
r = admin.get(B, params={'p': 'fiche', 'a': 2026, 'm': 9, 'u': 2}).text
check('225,96' in r, "l'admin voit la fiche de Fabien")
r = requests.post(B + '?p=depense_save', data={'csrf': 'x'})
check(r.status_code in (302, 400) or 'Session' in r.text, 'action sans session refusée')
r = fabien.post(B + '?p=depense_save', data={'csrf': 'mauvais', 'id': 0})
check(r.status_code == 400, 'jeton anti-falsification (CSRF) vérifié')

# Kilométrique
r = ajout(fabien, '2026-10-06', 'km', 0, 'perso', [], img=False, midi=False, kms='100', extra={'lieu_km': 'Bayonne - Pau'})
check(r['ok'], 'frais kilométriques sans justificatif ' + str(r.get('error', '')))
t10 = totaux(2026, 10)
check(abs(t10['Fabien']['kms'] - 38.20) < 0.001, f"100 km x 0,382 = 38,20 € -> {t10['Fabien']['kms']}")

# ---------------------------------------------------------------- alerte 150 € HT
r = ajout(fabien, '2026-10-07', 'repas', 160, 'cb_perso', [FC], 'GRAND RESTAURANT', 'BIARRITZ')
check(r['ok'], 'repas 160 € TTC (≈ 145 € HT estimé à 10 %) : pas d’alerte')
r = ajout(fabien, '2026-10-08', 'repas', 170, 'cb_perso', [FC], 'GRAND RESTAURANT', 'BIARRITZ')
check(not r['ok'] and r.get('confirm150') and '150' in r['error'], 'repas 170 € TTC (≈ 154,55 € HT) : alerte et confirmation exigée')
r = ajout(fabien, '2026-10-08', 'repas', 170, 'cb_perso', [FC], 'GRAND RESTAURANT', 'BIARRITZ', extra={'confirm_150': 1})
check(r['ok'], 'enregistrement confirmé malgré l’alerte')
r = ajout(fabien, '2026-10-09', 'autres', 175, 'cb_pro', [], 'LEROY MERLIN', 'BAYONNE', midi=False, extra={'montant_tva': '29,17'})
check(r['ok'], 'achat 175 € TTC avec TVA 29,17 € (145,83 € HT) : pas d’alerte')
r = ajout(fabien, '2026-10-12', 'autres', 240, 'cb_pro', [], 'LEROY MERLIN', 'BAYONNE', midi=False, extra={'facture_demandee': 1})
check(r['ok'], 'achat 240 € TTC avec facture électronique demandée : pas d’alerte')
g = admin.get(B, params={'p': 'gestion', 'a': 2026, 'm': 10}).text
check('1 ticket(s) de 150' in g and 'Points d' in g, "point d'attention côté admin (1 ticket sans facture)")
acc = fabien.get(B, params={'p': 'accueil', 'a': 2026, 'm': 10}).text
check('badge-alerte' in acc and 'facture demandée' in acc, 'badges « facture > 150 € HT » et « facture demandée » dans la liste')

# ---------------------------------------------------------------- congés saisis par l'associé
def conge(s, u, du, au, type_='conge', action='ajouter'):
    return s.post(B + '?p=absence_perso', data={'csrf': s.csrf, 'u': u, 'a': du[:4], 'm': int(du[5:7]), 'du': du, 'au': au,
                                                 'type': type_, 'action': action})


avant = totaux(2026, 10)['Fabien']['nb_primes']
r = conge(fabien, 2, '2026-10-14', '2026-10-18')
check('3 jour(s) de congé' in r.text, "associé : ajout de ses congés du 14 au 18/10 (3 jours ouvrés, week-end exclu) " + flashes(r.text))
check(totaux(2026, 10)['Fabien']['nb_primes'] == avant - 3, 'congés : 3 primes repas retirées')
f = fabien.get(B, params={'p': 'fiche', 'a': 2026, 'm': 10}).text
check('ligne-conge' in f and 'du mer. 14/10/2026 au ven. 16/10/2026' in f, 'congés : notés sur la fiche mensuelle')
r = conge(david, 2, '2026-10-20', '2026-10-20')
check(r.status_code == 403, "un associé ne peut pas saisir les congés d'un autre")
r = conge(admin, 3, '2026-10-21', '', 'formation')
check('1 jour(s) de formation' in r.text and 21 not in totaux(2026, 10)['David']['jours_primes'], "admin : formation d'une journée pour David, sans prime")
r = conge(fabien, 2, '2026-10-15', '2026-10-15', action='retirer')
check(totaux(2026, 10)['Fabien']['nb_primes'] == avant - 2, 'associé : retrait d’un jour de congé (prime rétablie)')
m_lien = re.search(r'class="lien-ticket" href="index\.php\?p=depense&amp;id=(\d+)&amp;retour=fiche&amp;ru=2"', f)
check(m_lien is not None, 'fiche : les objets mènent au ticket')
if m_lien:
    pt = fabien.get(B, params={'p': 'depense', 'id': m_lien.group(1), 'retour': 'fiche', 'ru': 2}).text
    check('p=fiche&amp;a=2026&amp;m=10&amp;u=2">‹ Retour à la fiche' in pt and 'name="retour" value="fiche"' in pt,
          'ticket ouvert depuis la fiche : « Retour » ramène à la fiche')

# ---------------------------------------------------------------- cycle du mois
def action(act, a=2026, m=9, extra=None):
    data = {'csrf': admin.csrf, 'a': a, 'm': m, 'action': act}
    data.update(extra or {})
    return admin.post(B + '?p=gestion_action', data=data)


r = fabien.post(B + '?p=terminer', data={'csrf': fabien.csrf, 'a': 2026, 'm': 9})
check('administrateur sait' in r.text, '« J’ai terminé ma saisie »')
check('terminée' in admin.get(B, params={'p': 'gestion', 'a': 2026, 'm': 9}).text, 'admin : saisie de Fabien marquée terminée')
r = action('valider')
check('Mois validé' in r.text, 'validation du mois de septembre')
r = ajout(fabien, d + '05', 'peage', 8, 'cb_perso', [], 'AUTOROUTE', 'BAYONNE', midi=False)
check(not r['ok'] and 'validé' in r['error'], 'mois validé : ajout de ticket refusé')
r = fabien.post(B + '?p=depense_save', data={'csrf': fabien.csrf, 'id': id_darty, 'date_depense': d + '28', 'categorie': 'autres',
                                              'paiement': 'cb_perso', 'montant': '1', 'lieu': 'X', 'ville': 'Y'}, headers={'X-Requested-With': 'fetch'})
check(not r.json()['ok'], 'mois validé : modification refusée')
r = fabien.post(B + '?p=depense_suppr', data={'csrf': fabien.csrf, 'id': id_darty})
check('impossible' in r.text, 'mois validé : suppression refusée')
r = admin.post(B + '?p=absence_set', data={'csrf': admin.csrf, 'user_id': 2, 'date': d + '17', 'type': 'conge'}, headers={'X-Requested-With': 'fetch'})
check(r.status_code == 400, 'mois validé : absences verrouillées')
r = conge(fabien, 2, d + '29', d + '29')
check('régularisé' in r.text and 29 in totaux()['Fabien']['jours_primes'],
      'mois validé : congé du 29/09 régularisé sur octobre, fiche de septembre inchangée')
r = action('prime', extra={'prime_montant': '5'})
check('ne peut être changé' in r.text, 'mois validé : prime repas figée')
t = totaux()
check(t['Fabien']['definitif'] and abs(t['Fabien']['total'] - 225.96) < 0.001, 'totaux inchangés après validation')

# ---------------------------------------------------------------- régularisations (ticket / congé après clôture)
import openpyxl
oct_avant = totaux(2026, 10)
r = ajout(fabien, d + '17', 'repas', 30, 'cb_perso', [FC, DP], 'BRASSERIE TARDIVE', 'BAYONNE')
check(not r['ok'] and r.get('confirmRegul') and 'octobre 2026' in r['error'], 'ticket de septembre (clos) : confirmation « régularisé sur octobre »')
r = ajout(fabien, d + '17', 'repas', 30, 'cb_perso', [FC, DP], 'BRASSERIE TARDIVE', 'BAYONNE', extra={'confirm_regul': 1})
check(r['ok'] and 'm=10' in r.get('redirect', ''), 'ticket tardif enregistré, rattaché à octobre')
t9 = totaux(2026, 9)
check(abs(t9['Fabien']['total'] - 225.96) < 0.001 and 17 in t9['Fabien']['jours_primes'], 'septembre inchangé (225,96 €, prime du 17 conservée)')
t10 = totaux(2026, 10)
check(abs(t10['Fabien']['total'] - (oct_avant['Fabien']['total'] + 30 - 4.75)) < 0.001,
      f"octobre Fabien : + 30,00 € remboursés − 4,75 € de prime -> {oct_avant['Fabien']['total']} → {t10['Fabien']['total']}")
check(abs(t10['David']['total'] - oct_avant['David']['total']) < 0.001, 'octobre David : rien à déduire (en congé le 17/09, pas de prime versée)')
r = conge(fabien, 2, '2026-09-23', '2026-09-23')
t10b = totaux(2026, 10)
check('régularisé' in r.text and abs(t10b['Fabien']['total'] - (t10['Fabien']['total'] - 4.75)) < 0.001,
      'congé du 23/09 déclaré après clôture : − 4,75 € sur octobre')
check(23 in totaux(2026, 9)['Fabien']['jours_primes'], 'septembre inchangé après le congé tardif')
r = ajout(fabien, d + '23', 'repas', 18, 'cb_pro', [FC], 'SNACK TARDIF', 'PAU', extra={'confirm_regul': 1})
check(r['ok'] and abs(totaux(2026, 10)['Fabien']['total'] - t10b['Fabien']['total']) < 0.001,
      'deuxième élément tardif le même jour : pas de double déduction')
r = fabien.post(B + '?p=regul_choix', data={'csrf': fabien.csrf, 'u': 2, 'a': 2026, 'm': 10, 'date_ref': '2026-09-17'})
check(r.status_code == 403, "un associé ne peut pas décocher une déduction")
r = admin.post(B + '?p=regul_choix', data={'csrf': admin.csrf, 'u': 2, 'a': 2026, 'm': 10, 'date_ref': '2026-09-17'})
check(abs(totaux(2026, 10)['Fabien']['total'] - (t10b['Fabien']['total'] + 4.75)) < 0.001, 'admin : déduction du 17/09 décochée (+ 4,75 €)')
r = admin.post(B + '?p=regul_choix', data={'csrf': admin.csrf, 'u': 2, 'a': 2026, 'm': 10, 'date_ref': '2026-09-17', 'actif': 1})
check(abs(totaux(2026, 10)['Fabien']['total'] - t10b['Fabien']['total']) < 0.001, 'admin : déduction recochée')
r = david.post(B + '?p=regul_libre', data={'csrf': david.csrf, 'u': 3, 'a': 2026, 'm': 10, 'libelle': 'x', 'montant': '5', 'signe': 'plus'})
check(r.status_code == 403, 'un associé ne peut pas ajouter de ligne libre')
r = admin.post(B + '?p=regul_libre', data={'csrf': admin.csrf, 'u': 2, 'a': 2026, 'm': 10, 'libelle': 'Péage du 18/09 oublié',
                                           'montant': '12,50', 'signe': 'plus', 'date_ref': '2026-09-18'})
check(abs(totaux(2026, 10)['Fabien']['total'] - (t10b['Fabien']['total'] + 12.5)) < 0.001, 'admin : ligne libre + 12,50 €')
f10 = fabien.get(B, params={'p': 'fiche', 'a': 2026, 'm': 10}).text
check('id="regularisations"' in f10 and 'Brouillon' in f10 and 'BRASSERIE TARDIVE' in f10 and 'data-auto-submit' not in f10,
      "fiche d'octobre : section régularisations, statut Brouillon, sans case à cocher pour l'associé")
check('data-auto-submit' in admin.get(B, params={'p': 'fiche', 'a': 2026, 'm': 10, 'u': 2}).text, "fiche d'octobre côté admin : case « déduire »")
check('Validée' in fabien.get(B, params={'p': 'fiche', 'a': 2026, 'm': 9}).text, 'fiche de septembre : statut Validée')
check('régularisation septembre' in fabien.get(B, params={'p': 'accueil', 'a': 2026, 'm': 10}).text, "liste d'octobre : badge « régularisation septembre »")
z10 = zipfile.ZipFile(io.BytesIO(admin.get(B, params={'p': 'export_zip', 'a': 2026, 'm': 10}).content))
check(any('2026-09-17 BRASSERIE TARDIVE' in n and 'regularisation' in n for n in z10.namelist()), "ZIP d'octobre : contient le ticket du 17/09 (régularisation)")
x10 = openpyxl.load_workbook(io.BytesIO(admin.get(B, params={'p': 'export_xlsx', 'a': 2026, 'm': 10}).content))
check(str(x10['RÉCAP VIREMENTS'].cell(3, 5).value).startswith('Régularisations'), "Excel d'octobre : colonne Régularisations")
pdf10 = admin.get(B, params={'p': 'export_pdf', 'a': 2026, 'm': 10})
check(pdf10.content[:5] == b'%PDF-', "PDF d'octobre généré avec les régularisations")


# ---------------------------------------------------------------- exports
x = admin.get(B, params={'p': 'export_xlsx', 'a': 2026, 'm': 9})
ok_x = x.status_code == 200 and x.content[:2] == b'PK'
check(ok_x, 'export Excel téléchargé')
if ok_x:
    import openpyxl
    wb = openpyxl.load_workbook(io.BytesIO(x.content))
    check(len(wb.sheetnames) >= 3 and any('cap' in n.lower() for n in wb.sheetnames), 'Excel : feuilles ' + ', '.join(wb.sheetnames))
    open(os.path.join(ICI, 'out-paye-equipe.xlsx'), 'wb').write(x.content) if os.environ.get('GARDER') else None
p = admin.get(B, params={'p': 'export_pdf', 'a': 2026, 'm': 9})
check(p.status_code == 200 and p.content[:5] == b'%PDF-' and p.content.rstrip().endswith(b'%%EOF'), f'PDF complet ({len(p.content) // 1024} Ko)')
z = admin.get(B, params={'p': 'export_zip', 'a': 2026, 'm': 9})
ok_z = z.status_code == 200 and z.content[:2] == b'PK'
check(ok_z, 'ZIP Pennylane téléchargé')
if ok_z:
    zf = zipfile.ZipFile(io.BytesIO(z.content))
    noms = zf.namelist()
    check(zf.testzip() is None, f'ZIP intact ({len(noms)} fichiers)')
    pdf_tickets = [n for n in noms if re.search(r'/2026-09-\d\d .+ EUR .+\.pdf$', n)]
    check(len(pdf_tickets) >= 20, f'un PDF par ticket ({len(pdf_tickets)}), ex. : {pdf_tickets[0] if pdf_tickets else "-"}')
    check(any('FABIEN' in n.upper() for n in noms) and any('DAVID' in n.upper() for n in noms), 'un dossier par associé')
    check(not any('TARDIVE' in n for n in noms), 'ZIP de septembre : sans les tickets régularisés sur octobre')
    if pdf_tickets:
        contenu = zf.read(pdf_tickets[0])
        check(contenu[:5] == b'%PDF-', 'PDF de ticket valide')

r = action('payer')
check('payé' in r.text, 'mois marqué payé')
r = action('transmettre')
check('transmis' in r.text, 'mois marqué transmis')
r = action('rouvrir')
check('rouvert' in r.text, 'réouverture du mois')
r = ajout(fabien, d + '05', 'peage', 8, 'cb_perso', [], 'AUTOROUTE', 'BAYONNE', midi=False)
check(r['ok'], 'mois rouvert : saisie de nouveau possible')

# ---------------------------------------------------------------- salarié bloqué (module désactivé)
env = dict(os.environ, TONTON_CONFIG=CONFIG)
code = ("$_SERVER['SCRIPT_NAME']='/index.php'; require 'lib/bootstrap.php';"
        "q(\"INSERT INTO {users} (prenom, nom, initiales, email, password_hash, role, created_at) VALUES ('Jeremy','Test','JB','jeremy@test.local',?, 'salarie', UTC_TIMESTAMP())\", [password_hash('salarie12345', PASSWORD_DEFAULT)]);")
subprocess.run([PHP, '-r', code], cwd=os.path.dirname(ICI), env=env, check=True)
s = session('jeremy@test.local', 'salarie12345', attendu=False)
check('pas encore ouvert' in s.derniere.text and 'barre-compte' not in s.derniere.text, 'compte salarié : connexion refusée tant que le module est désactivé')
check(fabien.get(B, params={'p': 'fiche_rh'}).url.endswith('p=accueil'), 'pages salariés fermées (redirection)')

# ---------------------------------------------------------------- carnet des commerçants
def php_json(expr):
    code = "$_SERVER['SCRIPT_NAME']='/index.php'; require 'lib/bootstrap.php'; echo json_encode(" + expr + ");"
    out = subprocess.run([PHP, '-r', code], cwd=os.path.dirname(ICI), env=env, capture_output=True, text=True, encoding='utf-8')
    return json.loads(out.stdout or 'null')


SIRET_T = '12345678900012'
def com_siret():
    return php_json(f"q1('SELECT * FROM {{commercants}} WHERE siret = ?', ['{SIRET_T}'])")


c = php_json("q1(\"SELECT * FROM {commercants} WHERE cle = 'BRASSERIE TARDIVE'\")")
check(c and c['ville'] == 'BAYONNE' and int(c['nb']) >= 1 and c['categorie'] == 'repas', "carnet : commerçant appris à l'enregistrement d'un ticket")
r = ajout(fabien, d + '06', 'carburant', 61.30, 'cb_pro', [], 'STATION TOTAL', 'ANGLET', objet='Carburant véhicule', midi=False,
          extra={'siret': '123 456 789 00012'})
c = com_siret()
check(r['ok'] and c and c['nom'] == 'STATION TOTAL' and c['categorie'] == 'carburant' and c['objet'] == 'Carburant véhicule',
      'carnet : SIRET, catégorie et nature retenus')
r = ajout(david, d + '06', 'carburant', 55.10, 'cb_pro', [], 'Station Total Anglet', 'ANGLET', midi=False, extra={'siret': SIRET_T})
c = com_siret()
n_siret = php_json(f"q1('SELECT COUNT(*) n FROM {{commercants}} WHERE siret = ? OR cle LIKE ?', ['{SIRET_T}', '%STATION TOTAL%'])")['n']
check(r['ok'] and int(c['nb']) == 2 and c['nom'] == 'Station Total Anglet' and int(n_siret) == 1 and c['objet'] == 'Carburant véhicule',
      'carnet : même SIRET → même commerçant, nom corrigé retenu, nature habituelle gardée')
h = fabien.get(B, params={'p': 'depense'}).text
check('data-commercants=' in h and 'Station Total Anglet' in h and 'id="liste-commercants"' in h and SIRET_T in h,
      'saisie : carnet transmis au formulaire (reconnaissance et liste de choix)')
r = fabien.get(B, params={'p': 'commercants'})
check('Commerçants</h1>' not in r.text, 'carnet : page réservée à l\'administrateur')
r = admin.get(B, params={'p': 'commercants', 'q': 'total'})
check('Station Total Anglet' in r.text and 'BRASSERIE TARDIVE' not in r.text, 'admin : recherche dans le carnet')
r = admin.get(B, params={'p': 'commercants', 'q': '789 000'})
check('Station Total Anglet' in r.text, 'admin : recherche par SIRET')
cid = int(c['id'])
r = admin.post(B + '?p=commercant_save', data={'csrf': admin.csrf, 'id': cid, 'nom': 'Total Énergies Anglet', 'ville': 'ANGLET',
                                               'siret': SIRET_T, 'categorie': 'carburant', 'objet': 'Carburant'})
c = com_siret()
check(c['nom'] == 'Total Énergies Anglet' and int(c['manuel']) == 1, 'admin : commerçant modifié à la main')
ajout(fabien, d + '07', 'carburant', 40.20, 'cb_pro', [], 'TOTAL', 'ANGLET', midi=False, extra={'siret': SIRET_T})
c = com_siret()
check(c['nom'] == 'Total Énergies Anglet' and c['objet'] == 'Carburant' and int(c['nb']) == 3,
      'carnet : une fiche modifiée à la main n\'est plus changée par les tickets (compteur seulement)')
r = admin.post(B + '?p=commercant_save', data={'csrf': admin.csrf, 'id': 0, 'nom': 'Doublon', 'ville': '', 'siret': SIRET_T, 'categorie': '', 'objet': ''})
check('déjà celui de' in r.text, 'admin : SIRET déjà utilisé refusé')
r = admin.post(B + '?p=commercant_save', data={'csrf': admin.csrf, 'id': 0, 'nom': 'Boulangerie Etcheverry', 'ville': 'USTARITZ',
                                               'siret': '98765432100019', 'categorie': 'repas', 'objet': 'Déjeuner'})
check('Boulangerie Etcheverry' in r.text, 'admin : commerçant ajouté à la main')
r = admin.post(B + '?p=commercant_save', data={'csrf': admin.csrf, 'id': 0, 'nom': 'X', 'ville': '', 'siret': '123', 'categorie': '', 'objet': ''})
check('14 chiffres' in r.text, 'admin : SIRET incomplet refusé')
r = admin.post(B + '?p=commercant_suppr', data={'csrf': admin.csrf, 'id': cid})
check('supprimé du carnet' in r.text and com_siret() is None, 'admin : commerçant supprimé')
n_dep = php_json(f"q1('SELECT COUNT(*) n FROM {{depenses}} WHERE siret = ?', ['{SIRET_T}'])")['n']
check(int(n_dep) == 3, 'suppression du commerçant : les tickets ne sont pas touchés')
n = php_json("(function () { q('DELETE FROM {commercants}'); commercants_initialiser(); return q1('SELECT COUNT(*) n FROM {commercants}')['n']; })()")
c = com_siret()
check(int(n) >= 5 and c and int(c['nb']) == 3, f'mise à niveau : carnet reconstitué à partir des tickets existants ({n} commerçants)')

# ---------------------------------------------------------------- repas d'équipe : associé absent décochable
h = fabien.get(B, params={'p': 'depense'}).text
check(h.count('data-associe="1"') == 2, "formulaire : Fabien et David marqués comme associés (cochés par « Équipe »)")
r = ajout(fabien, d + '08', 'repas', 46.40, 'cb_pro', [FC], 'CHEZ ANDRE', 'BAYONNE', extra={'equipe': 1})
t = totaux()
check(r['ok'] and 8 in t['David']['jours_primes'], "repas d'équipe avec David décoché : David garde sa prime du 8")
r = ajout(fabien, d + '08', 'repas', 52.10, 'cb_pro', [FC, DP], 'CHEZ ANDRE', 'BAYONNE', extra={'equipe': 1})
t = totaux()
check(r['ok'] and 8 not in t['David']['jours_primes'], "repas d'équipe avec David coché : plus de prime le 8")
n = php_json("(function () {"
             " $id = (int)q1(\"SELECT id FROM {depenses} WHERE equipe = 1 AND montant = 46.40\")['id'];"
             " q('DELETE FROM {convives} WHERE depense_id = ?', [$id]);"
             " reglage_set('schema_version', '2'); maj_schema();"
             " return [(int)q1('SELECT COUNT(*) n FROM {convives} WHERE depense_id = ?', [$id])['n'], schema_version_installee()]; })()")
check(n == [2, 3], f"mise à niveau : un ancien repas d'équipe reçoit les associés comme participants ({n})")

# ---------------------------------------------------------------- pages admin
for page in ['journal', 'diagnostic', 'utilisateurs', 'absences', 'compte', 'commercants', 'commercant']:
    r = admin.get(B, params={'p': page, 'a': 2026, 'm': 9})
    check(r.status_code == 200 and 'Fatal' not in r.text and 'Warning' not in r.text, f'admin : page « {page} »')
check('Connexion' in admin.get(B, params={'p': 'journal'}).text, 'journal : connexions enregistrées')

print()
print('RÉSULTAT :', 'tout est OK' if not erreurs else f'{erreurs} erreur(s)')
sys.exit(1 if erreurs else 0)
