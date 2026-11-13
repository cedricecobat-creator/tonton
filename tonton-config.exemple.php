<?php
/*
 * MODÈLE de configuration de Tonton.
 *
 * NE PAS remplir ce fichier-ci (il est public, sur GitHub).
 * Copiez-le sous le nom « tonton-config.php » À CÔTÉ du dossier du site
 * (sur OVH : à la racine de l'espace FTP, au même niveau que le dossier « tonton »),
 * puis renseignez vos accès. Ce fichier ne doit jamais être mis dans le dossier du site.
 *
 *   /  (racine FTP)
 *   ├── tonton/              ← le site (synchronisé depuis GitHub)
 *   ├── tonton-config.php    ← CE fichier, rempli
 *   └── tonton-data/         ← les données (créé automatiquement)
 */
return [
    'db' => [
        'host'   => 'xxxxxxx.mysql.db',   // Serveur MySQL OVH
        'name'   => 'nom_de_la_base',
        'user'   => 'utilisateur',
        'pass'   => 'mot_de_passe',
        'prefix' => 'tonton_',            // Préfixe des tables
    ],

    // Clé demandée une seule fois par la page d'installation (12 caractères minimum).
    // Inventez une longue phrase ; elle ne sert plus après l'installation.
    'cle_installation' => '',

    // Dossier des données : justificatifs, sessions, verrous. HORS du dossier du site.
    'data_dir' => __DIR__ . '/tonton-data',

    'societe'  => 'ecoBAT',
    'siren'    => '828 072 132',
    'adresse'  => '318 Lan Eremuko Bidea, 64990 Villefranque',
    'adresse_facturation_electronique' => '', // fournie par votre plateforme agréée

    // Coordonnées en pied des fiches PDF
    'coordonnees' => "ecoBAT - 318 Lan Eremuko Bidea, 64990 VILLEFRANQUE\n05 59 64 26 35 - ecobat-habitat.com - SIRET : 828 072 132 00032",

    // E-mails (phase 2) : l'adresse d'expédition doit être sur votre domaine
    'email_expediteur' => 'interne@ecobat-habitat.com',
    'url_app'          => 'https://tonton.ecobat-habitat.com/',

    // Règles
    'seuil_facture_ht' => 150,   // alerte facture obligatoire (€ HT)
    'prime_repas'      => 4.75,  // prime repas par défaut (modifiable chaque mois)
    'coef_km_defaut'   => 0.382, // barème km par défaut (modifiable par personne)
    // Jours fériés travaillés (donnent droit à la prime) : 'jour_an','lundi_paques',
    // 'fete_travail','victoire_1945','ascension','lundi_pentecote','fete_nationale',
    // 'assomption','toussaint','armistice','noel'
    'feries_travailles' => [],

    // Phase 2 : espace salariés (fiche RH). Laisser à false pendant les tests des associés.
    'module_salaries' => false,

    'timezone' => 'Europe/Paris',
];
