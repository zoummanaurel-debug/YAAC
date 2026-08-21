-- =========================================================================
-- YAAC — Base de données des membres
--
-- Source de vérité de l'adhésion. Remplace le tableau MEMBERS_DB codé en
-- dur dans l'ancienne page de vérification (5 membres sur 34) et la colonne
-- Excel `Paiement` mise à jour à la main.
--
-- Le numéro de membre est attribué ICI, par la base, et non par une lecture
-- « dernière ligne + 1 » : deux paiements simultanés ne peuvent plus recevoir
-- le même numéro.
--
-- Cible : MySQL 8 / MariaDB 10.4+ (Hostinger). utf8mb4 obligatoire — les noms
-- contiennent des accents et des caractères ouest-africains.
-- =========================================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------------------
-- Compteur de numéros de membre
--
-- Une ligne par année. `UPDATE ... SET dernier = LAST_INSERT_ID(dernier + 1)`
-- est atomique sous InnoDB : la ligne est verrouillée le temps de la
-- transaction, donc deux webhooks concurrents obtiennent deux numéros.
--
-- Le compteur NE se remet PAS à zéro chaque année : il continue la série
-- historique, conformément aux numéros déjà imprimés (YAAC-2024-0001…0005).
-- Seul le millésime dans le numéro change.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS compteur_membre (
  id       TINYINT      NOT NULL PRIMARY KEY DEFAULT 1,
  dernier  INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT compteur_unique CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO compteur_membre (id, dernier) VALUES (1, 0)
  ON DUPLICATE KEY UPDATE id = id;

-- -------------------------------------------------------------------------
-- Membres
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS membre (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  -- Numéro YAAC : format YAAC-AAAA-NNNN, imprimé sur la carte et encodé
  -- dans le QR code. C'est l'identifiant public.
  numero            VARCHAR(20)  NOT NULL,

  prenom            VARCHAR(120) NOT NULL,
  nom               VARCHAR(120) NOT NULL,
  -- Nullable à dessein : les membres historiques sont repris sans e-mail
  -- connu. MySQL autorise plusieurs NULL sous un index unique, alors qu'il
  -- rejetterait plusieurs chaînes vides.
  email             VARCHAR(190)     NULL,
  telephone         VARCHAR(40)      NULL,

  -- Champs recueillis par le formulaire d'adhésion, et par lui seul :
  -- le webhook Chariow ne fournit ni date de naissance, ni motivation, ni
  -- pays d'origine distinct du pays de résidence. C'est la raison d'être du
  -- formulaire dans le tunnel.
  date_naissance    DATE             NULL,
  pays_origine      VARCHAR(80)      NULL,
  pays_residence    VARCHAR(80)      NULL,
  motivation        TEXT             NULL,
  benevolat         VARCHAR(255)     NULL,
  source            VARCHAR(120)     NULL,
  statuts_acceptes  TINYINT(1)   NOT NULL DEFAULT 0,

  -- Rôle affiché sur la carte et sur la page de vérification.
  role              VARCHAR(80)  NOT NULL DEFAULT 'Membre',

  date_adhesion     DATE         NOT NULL,
  date_expiration   DATE         NOT NULL,

  -- Piloté par les événements de licence Chariow (license.expired,
  -- license.revoked). Aucune valeur n'est jamais saisie à la main ici.
  statut            ENUM('actif','expire','suspendu') NOT NULL DEFAULT 'actif',

  -- Traçabilité du paiement. `montant_centimes` évite les flottants.
  chariow_sale_id   VARCHAR(64)      NULL,
  chariow_license   VARCHAR(64)      NULL,
  montant_centimes  INT UNSIGNED     NULL,
  devise            CHAR(3)          NULL,

  -- État de l'envoi de la carte. Permet de rejouer un envoi manqué sans
  -- risquer un doublon, et de savoir qui n'a jamais reçu sa carte.
  carte_envoyee_le  DATETIME         NULL,
  carte_erreur      TEXT             NULL,

  cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  maj_le            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_membre_numero (numero),
  -- Un e-mail = une adhésion. Bloque le second numéro délivré à quelqu'un
  -- qui resoumet le formulaire.
  UNIQUE KEY uq_membre_email  (email),
  UNIQUE KEY uq_membre_sale   (chariow_sale_id),
  KEY idx_membre_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- Candidatures
--
-- Le formulaire du site écrit ici AVANT le paiement. C'est ce qui rend un
-- abandon visible : une candidature sans `membre_id` est quelqu'un qui a
-- rempli le formulaire et n'a pas payé. L'ancien tunnel (Microsoft Forms puis
-- rien) rendait ces gens invisibles.
--
-- Les champs libres viennent du formulaire et de lui seul : le webhook
-- Chariow ne renvoie ni date de naissance, ni motivation, ni pays d'origine
-- distinct du pays de résidence.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS candidature (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  -- Jeton opaque transmis à Chariow en `custom_metadata`, puis renvoyé par le
  -- webhook. C'est lui qui relie le paiement au formulaire avec certitude,
  -- là où un rapprochement sur l'e-mail dépendrait de la bonne volonté du
  -- visiteur à ressaisir la même adresse.
  jeton             CHAR(32)     NOT NULL,

  prenom            VARCHAR(120) NOT NULL,
  nom               VARCHAR(120) NOT NULL,
  email             VARCHAR(190) NOT NULL,
  telephone         VARCHAR(40)      NULL,
  tel_pays          CHAR(2)          NULL,
  date_naissance    DATE             NULL,
  pays_origine      VARCHAR(80)      NULL,
  pays_residence    VARCHAR(80)      NULL,
  motivation        TEXT             NULL,
  benevolat         VARCHAR(255)     NULL,
  source            VARCHAR(120)     NULL,
  statuts_acceptes  TINYINT(1)   NOT NULL DEFAULT 0,

  -- Rempli par le webhook quand la vente aboutit. NULL = pas encore payé.
  membre_id         INT UNSIGNED     NULL,

  ip                VARBINARY(16)    NULL,
  cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_candidature_jeton (jeton),
  KEY idx_candidature_email (email),
  KEY idx_candidature_membre (membre_id),
  KEY idx_candidature_cree (cree_le),
  CONSTRAINT fk_candidature_membre
    FOREIGN KEY (membre_id) REFERENCES membre (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- Limitation de débit de la vérification
--
-- Les numéros de membre sont séquentiels : balayer 001 à 999 est trivial. Le
-- jeton du QR code empêche d'en tirer une identité, mais rien n'empêcherait
-- de marteler le point d'entrée pour cartographier les adhésions actives.
--
-- Une ligne par (adresse IP, heure). La granularité horaire borne la taille
-- de la table et rend la purge triviale.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS verif_debit (
  ip       VARBINARY(16) NOT NULL,
  fenetre  DATETIME      NOT NULL,
  -- Comptés séparément : scanner de vraies cartes en série est légitime
  -- (un stand à un événement), balayer des numéros ne l'est pas.
  avec_jeton  INT UNSIGNED NOT NULL DEFAULT 0,
  sans_jeton  INT UNSIGNED NOT NULL DEFAULT 0,

  PRIMARY KEY (ip, fenetre),
  KEY idx_verif_fenetre (fenetre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- Journal des webhooks Chariow
--
-- `delivery_id` est l'en-tête x-pulse-delivery-id. Chariow réémet un Pulse
-- tant qu'il n'a pas reçu de 200 : sans cette table, une réémission créerait
-- un second membre. L'insertion sert de verrou — si elle échoue en doublon,
-- l'événement a déjà été traité.
--
-- On journalise AUSSI les livraisons rejetées (signature invalide), sans
-- quoi on n'a aucun moyen de diagnostiquer un secret mal configuré.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhook_livraison (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  delivery_id   VARCHAR(128) NOT NULL,
  evenement     VARCHAR(64)      NULL,
  resultat      ENUM('traite','ignore','rejete','erreur') NOT NULL,
  detail        TEXT             NULL,
  charge_utile  MEDIUMTEXT       NULL,
  recu_le       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_livraison (delivery_id),
  KEY idx_livraison_recu (recu_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- Reprise de l'existant
--
-- Ce fichier n'insère AUCUN membre, volontairement.
--
-- Deux sources contradictoires existaient : le tableau `MEMBERS_DB` codé en
-- dur dans l'ancienne page de vérification (5 personnes, numéros
-- YAAC-2024-000N) et le classeur « Membres.xlsx » (18 personnes, numéros
-- YAAC-2026-00N). Elles ne se recoupent que sur une personne, avec DEUX
-- numéros différents. Amorcer les deux créerait des doublons.
--
-- Le classeur fait foi. L'import se génère à part :
--
--   node db/import-membres.mjs "C:/chemin/Membres.xlsx" > db/import-membres.local.sql
--
-- puis on exécute ce .sql après le présent schéma. Le fichier produit contient
-- des données personnelles et n'est pas versionné (le dépôt est public).
--
-- Le bloc ci-dessous cale le compteur au-dessus du plus grand numéro présent.
-- Il est rejoué par le script d'import ; le laisser ici rend le schéma
-- utilisable seul, sur une base vide.
-- -------------------------------------------------------------------------
UPDATE compteur_membre
   SET dernier = GREATEST(
     dernier,
     COALESCE((SELECT MAX(CAST(SUBSTRING_INDEX(numero, '-', -1) AS UNSIGNED)) FROM membre), 0)
   )
 WHERE id = 1;
