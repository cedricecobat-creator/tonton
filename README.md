# Tonton

Application interne de notes de frais d'ecoBAT, utilisable sur téléphone comme une appli.
Adresse : https://tonton.ecobat-habitat.com

Phase 1 (en cours) : administrateur + associés. L'espace salariés (phase 2) existe
mais reste **désactivé** tant que `module_salaries` vaut `false` dans la configuration.

---

## Le principe à retenir

Le dossier `tonton/` de l'hébergement est une **copie automatique de ce dépôt GitHub public**.
Tout ce qui est dans le dépôt est donc visible par tout le monde, et tout ce qui est
dans le dossier `tonton/` peut être écrasé à chaque mise à jour.

C'est pourquoi la configuration (mots de passe de la base) et les données (photos des
tickets, sessions) sont rangées **à côté** du dossier du site, jamais dedans :

```
racine FTP OVH
├── www/
├── public_html/
├── tonton/              ← le site (copie de GitHub, ne rien y déposer à la main)
├── tonton-config.php    ← la configuration (à créer une fois, par FTP)
└── tonton-data/         ← les données (créé automatiquement par Tonton)
```

---

## Installation sur OVH (une seule fois)

1. **Vérifier l'hébergement** (espace client OVH > Web Cloud > Hébergements) :
   - « Version PHP globale » : 8.1 ou plus récente ;
   - « Certificat SSL » : actif pour `tonton.ecobat-habitat.com` (sinon Let's Encrypt, gratuit) ;
   - onglet « Multisite » : le sous-domaine pointe bien vers le dossier `tonton`, la case HTTPS est active.
2. **Créer la configuration** : copier `tonton-config.exemple.php` sur votre ordinateur,
   le renommer `tonton-config.php`, le remplir (accès de la base : onglet « Bases de données » d'OVH ;
   inventer une longue `cle_installation`), puis le déposer par FTP **à la racine**, à côté du dossier `tonton`.
3. **Lancer l'installation** : ouvrir `https://tonton.ecobat-habitat.com/install.php`, saisir la clé
   d'installation et créer le compte administrateur.
   La page se bloque ensuite d'elle-même (elle n'a pas besoin d'être supprimée, et la synchronisation
   GitHub la remettrait de toute façon).
4. **Vérifier** : se connecter, puis Admin > Diagnostic. Tout doit être « OK »
   (la ligne « Connexion sécurisée » doit être OK en ligne).
5. **Créer les comptes** : Admin > Utilisateurs et membres > « + Ajouter » (rôle « Associé » pour Fabien et David).

## Mettre à jour

Publier les modifications sur GitHub (branche `main`) : OVH recopie le dépôt dans `tonton/`.
La base de données est mise à niveau automatiquement à la première visite.

## Installer l'appli sur un téléphone

- iPhone (Safari) : bouton Partager > « Sur l'écran d'accueil ».
- Android (Chrome) : menu ⋮ > « Ajouter à l'écran d'accueil ».

## Sauvegardes

À faire régulièrement : une sauvegarde de la base (OVH le fait aussi) et une copie du dossier
`tonton-data/` par FTP (il contient les photos des justificatifs).

---

## Pour le développement

- PHP 8.1+ et MySQL/MariaDB, sans aucune dépendance externe (PDF, Excel et ZIP sont générés en PHP).
- Lecture des tickets (OCR) sur le téléphone avec tesseract.js et pdf.js, servis par le site.
- La configuration est cherchée dans la variable d'environnement `TONTON_CONFIG`, sinon dans
  `../tonton-config.php`.
- Tests automatiques de la phase 1 sur une base **locale et vide** :

  ```
  TONTON_CONFIG=/chemin/tonton-config.php php -S 127.0.0.1:8080
  python tests/test_phase1.py http://127.0.0.1:8080 <clé d'installation> <php> /chemin/tonton-config.php
  ```

  Ils rejouent septembre 2026 (Fabien 225,96 €, David 9,50 €) et vérifient les droits,
  l'alerte des 150 € HT, le verrouillage du mois et les exports.
- Le dossier `tonton-kit/` (cahier des charges, code et documents de référence avec des données réelles)
  n'est **jamais** publié : il est exclu par `.gitignore`.
