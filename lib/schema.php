<?php
declare(strict_types=1);

/**
 * Structure de la base Tonton.
 * {nom} est remplacé par le nom de table préfixé (tonton_nom par défaut).
 *
 * Pour faire évoluer la base plus tard : augmenter SCHEMA_VERSION et ajouter
 * l'étape correspondante dans maj_schema(). La mise à niveau est faite
 * automatiquement à la première visite après le dépôt d'une nouvelle version.
 */
const SCHEMA_VERSION = 1;

const ROLES = [
    'admin'   => 'Administrateur',
    'associe' => 'Associé',
    'salarie' => 'Salarié',
];

/** Membres de l'entreprise créés à l'installation (modifiables ensuite). */
const MEMBRES_INITIAUX = [
    ['Fabien', 'FC'], ['David', 'DP'], ['Jeremy', 'JB'], ['Julien', 'JG'], ['Ludovic', 'LG'],
    ['Olivier', 'OA'], ['Xabi', 'XD'], ['Marina', 'MP'], ['Cédric', 'CM'],
];

function schema_sql(): array
{
    $opt = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS {reglages} (
            cle VARCHAR(60) NOT NULL PRIMARY KEY,
            valeur TEXT NULL
        ) $opt",

        // role : admin / associe / salarie.
        // admin_fiche : l'administrateur a aussi une fiche salarié (validée par un associé).
        "CREATE TABLE IF NOT EXISTS {users} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prenom VARCHAR(60) NOT NULL,
            nom VARCHAR(60) NOT NULL,
            initiales VARCHAR(6) NOT NULL,
            email VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(10) NOT NULL DEFAULT 'associe',
            admin_fiche TINYINT(1) NOT NULL DEFAULT 0,
            responsable_id INT UNSIGNED NULL,
            rembourseur_id INT UNSIGNED NULL,
            coef_km DECIMAL(6,3) NOT NULL DEFAULT 0.382,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            failed_logins INT NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            last_login DATETIME NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_email (email)
        ) $opt",

        "CREATE TABLE IF NOT EXISTS {depenses} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            date_depense DATE NOT NULL,
            categorie VARCHAR(12) NOT NULL,
            repas_midi TINYINT(1) NOT NULL DEFAULT 0,
            paiement VARCHAR(10) NOT NULL,
            montant DECIMAL(10,2) NOT NULL,
            kms DECIMAL(8,1) NULL,
            coef_km DECIMAL(6,3) NULL,
            objet VARCHAR(255) NOT NULL DEFAULT '',
            lieu VARCHAR(255) NOT NULL DEFAULT '',
            ville VARCHAR(120) NOT NULL DEFAULT '',
            description TEXT NULL,
            siret VARCHAR(14) NOT NULL DEFAULT '',
            montant_tva DECIMAL(10,2) NULL,
            facture_demandee TINYINT(1) NOT NULL DEFAULT 0,
            sans_justificatif TINYINT(1) NOT NULL DEFAULT 0,
            remb_mode VARCHAR(10) NULL,
            remb_par INT UNSIGNED NULL,
            remb_statut VARCHAR(10) NULL,
            remb_le DATETIME NULL,
            remb_note VARCHAR(255) NOT NULL DEFAULT '',
            invites VARCHAR(255) NOT NULL DEFAULT '',
            fichier VARCHAR(255) NULL,
            equipe TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY ix_date (date_depense),
            KEY ix_user (user_id),
            CONSTRAINT {fk}_dep_user FOREIGN KEY (user_id) REFERENCES {users}(id)
        ) $opt",

        "CREATE TABLE IF NOT EXISTS {absences} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            date_absence DATE NOT NULL,
            type VARCHAR(10) NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'manuel',
            UNIQUE KEY uq_user_date (user_id, date_absence),
            CONSTRAINT {fk}_abs_user FOREIGN KEY (user_id) REFERENCES {users}(id)
        ) $opt",

        "CREATE TABLE IF NOT EXISTS {mois} (
            annee SMALLINT UNSIGNED NOT NULL,
            mois TINYINT UNSIGNED NOT NULL,
            statut VARCHAR(10) NOT NULL DEFAULT 'ouvert',
            prime_montant DECIMAL(6,2) NOT NULL,
            valide_at DATETIME NULL,
            paye_at DATETIME NULL,
            transmis_at DATETIME NULL,
            PRIMARY KEY (annee, mois)
        ) $opt",

        "CREATE TABLE IF NOT EXISTS {soumissions} (
            user_id INT UNSIGNED NOT NULL,
            annee SMALLINT UNSIGNED NOT NULL,
            mois TINYINT UNSIGNED NOT NULL,
            soumis_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, annee, mois),
            CONSTRAINT {fk}_soum_user FOREIGN KEY (user_id) REFERENCES {users}(id)
        ) $opt",

        "CREATE TABLE IF NOT EXISTS {journal} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            action VARCHAR(40) NOT NULL,
            detail TEXT NULL,
            created_at DATETIME NOT NULL,
            KEY ix_date (created_at)
        ) $opt",

        "CREATE TABLE IF NOT EXISTS {membres} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prenom VARCHAR(60) NOT NULL,
            initiales VARCHAR(6) NOT NULL,
            user_id INT UNSIGNED NULL,
            ordre INT NOT NULL DEFAULT 0,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_user (user_id),
            CONSTRAINT {fk}_membre_user FOREIGN KEY (user_id) REFERENCES {users}(id)
        ) $opt",

        "CREATE TABLE IF NOT EXISTS {convives} (
            depense_id INT UNSIGNED NOT NULL,
            membre_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (depense_id, membre_id),
            KEY ix_membre (membre_id),
            CONSTRAINT {fk}_conv_dep FOREIGN KEY (depense_id) REFERENCES {depenses}(id) ON DELETE CASCADE,
            CONSTRAINT {fk}_conv_membre FOREIGN KEY (membre_id) REFERENCES {membres}(id)
        ) $opt",

        // --- Phase 2 : fiche mensuelle RH des salariés ---
        "CREATE TABLE IF NOT EXISTS {fiches_rh} (
            user_id INT UNSIGNED NOT NULL,
            annee SMALLINT UNSIGNED NOT NULL,
            mois TINYINT UNSIGNED NOT NULL,
            champ_libre TEXT NULL,
            prime_qualite TINYINT(1) NOT NULL DEFAULT 0,
            prime_contributions TINYINT(1) NOT NULL DEFAULT 0,
            commentaire_resp TEXT NULL,
            valide_collab_at DATETIME NULL,
            valide_resp_at DATETIME NULL,
            valide_resp_par INT UNSIGNED NULL,
            PRIMARY KEY (user_id, annee, mois),
            CONSTRAINT {fk}_frh_user FOREIGN KEY (user_id) REFERENCES {users}(id)
        ) $opt",
        "CREATE TABLE IF NOT EXISTS {heures_sup} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            date_hs DATE NOT NULL,
            motif VARCHAR(255) NOT NULL DEFAULT '',
            heures DECIMAL(5,2) NOT NULL,
            KEY ix_user_date (user_id, date_hs),
            CONSTRAINT {fk}_hs_user FOREIGN KEY (user_id) REFERENCES {users}(id)
        ) $opt",
        "CREATE TABLE IF NOT EXISTS {indic_tech} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            annee SMALLINT UNSIGNED NOT NULL,
            mois TINYINT UNSIGNED NOT NULL,
            client VARCHAR(255) NOT NULL,
            prestation VARCHAR(255) NOT NULL DEFAULT '',
            montant_ht DECIMAL(10,2) NULL,
            KEY ix_user_mois (user_id, annee, mois),
            CONSTRAINT {fk}_it_user FOREIGN KEY (user_id) REFERENCES {users}(id)
        ) $opt",
        "CREATE TABLE IF NOT EXISTS {contributions} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            annee SMALLINT UNSIGNED NOT NULL,
            mois TINYINT UNSIGNED NOT NULL,
            client VARCHAR(255) NOT NULL,
            panneaux TINYINT(1) NOT NULL DEFAULT 0,
            avis_google TINYINT(1) NOT NULL DEFAULT 0,
            autre TINYINT(1) NOT NULL DEFAULT 0,
            autre_texte VARCHAR(255) NOT NULL DEFAULT '',
            KEY ix_user_mois (user_id, annee, mois),
            CONSTRAINT {fk}_ct_user FOREIGN KEY (user_id) REFERENCES {users}(id)
        ) $opt",
    ];
}

/** Exécute une requête de structure ({fk} = préfixe des noms de contraintes) */
function schema_exec(string $sql): void
{
    // Les noms de contraintes doivent être uniques dans toute la base :
    // on les préfixe aussi, au cas où la base est partagée.
    $sql = str_replace('{fk}', rtrim(t(''), '_') ?: 'tt', $sql);
    q($sql);
}

function reglage(string $cle, ?string $defaut = null): ?string
{
    try {
        $r = q1('SELECT valeur FROM {reglages} WHERE cle = ?', [$cle]);
    } catch (Throwable $e) {
        return $defaut;
    }
    return $r ? $r['valeur'] : $defaut;
}

function reglage_set(string $cle, ?string $valeur): void
{
    q('INSERT INTO {reglages} (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)', [$cle, $valeur]);
}

function schema_version_installee(): int
{
    return (int)reglage('schema_version', '0');
}

/** Crée ou met à niveau la base (appelé à l'installation et après une mise à jour du code). */
function maj_schema(): void
{
    foreach (schema_sql() as $sql) {
        schema_exec($sql);
    }
    // Futures évolutions :
    // if (schema_version_installee() < 2) { ... ALTER TABLE ... }

    if (!(int)q1('SELECT COUNT(*) n FROM {membres}')['n']) {
        foreach (MEMBRES_INITIAUX as $i => [$prenom, $ini]) {
            q('INSERT INTO {membres} (prenom, initiales, ordre, actif) VALUES (?, ?, ?, 1)', [$prenom, $ini, ($i + 1) * 10]);
        }
    }
    foreach (qall('SELECT id FROM {users}') as $u) {
        lier_membre((int)$u['id']);
    }
    reglage_set('schema_version', (string)SCHEMA_VERSION);
}
