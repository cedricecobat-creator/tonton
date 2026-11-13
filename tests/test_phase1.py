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
r = s0.post(BASE + '/install.php', data={'csrf': tok(html), 'cle': 'mauvaise-cle-123', 'prenom': 'Cédric', 'nom': 'Admin',
                                         'initiales': 'CM', 'email': 'admin@test.local', 'password': 'motdepasse123', 'password2': 'motdepasse123'})
check('Clé d' in r.text and 'incorrecte' in r.text, "installation refusée sans la bonne clé")
r = s0.post(BASE + '/install.php', data={'csrf': tok(html), 'cle': CLE, 'prenom': 'Cédric', 'nom': 'Admin',
                                         'initiales': 'CM', 'email': 'admin@test.local', 'password': 'motdepasse123', 'password2': 'motdepasse123'})
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
r = action('prime', extra={'prime_montant': '5'})
check('ne peut être changé' in r.text, 'mois validé : prime repas figée')
t = totaux()
check(t['Fabien']['definitif'] and abs(t['Fabien']['total'] - 225.96) < 0.001, 'totaux inchangés après validation')

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

# ---------------------------------------------------------------- pages admin
for page in ['journal', 'diagnostic', 'utilisateurs', 'absences', 'compte']:
    r = admin.get(B, params={'p': page, 'a': 2026, 'm': 9})
    check(r.status_code == 200 and 'Fatal' not in r.text and 'Warning' not in r.text, f'admin : page « {page} »')
check('Connexion' in admin.get(B, params={'p': 'journal'}).text, 'journal : connexions enregistrées')

print()
print('RÉSULTAT :', 'tout est OK' if not erreurs else f'{erreurs} erreur(s)')
sys.exit(1 if erreurs else 0)
