-- ============================================================
--  Alternis — Mise à jour : clés d'accès (WebAuthn) + ressources
--  Sûr à exécuter plusieurs fois (CREATE TABLE IF NOT EXISTS).
-- ============================================================
SET NAMES utf8mb4;

-- ---------- Clés d'accès (passkeys / WebAuthn) ----------
CREATE TABLE IF NOT EXISTS `webauthn_credentials` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `credential_id` VARCHAR(512) NOT NULL,          -- identifiant (base64url)
  `public_key`    TEXT         NOT NULL,          -- clé publique au format PEM
  `sign_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `transports`    VARCHAR(120) NOT NULL DEFAULT '',
  `label`         VARCHAR(120) NOT NULL DEFAULT '',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used`     DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cred` (`credential_id`),
  KEY `idx_wa_user` (`user_id`),
  CONSTRAINT `fk_wa_user` FOREIGN KEY (`user_id`)
      REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Ressources (CV, lettre de motivation, etc.) ----------
CREATE TABLE IF NOT EXISTS `resources` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_id`      INT UNSIGNED NOT NULL,
  `kind`          ENUM('cv','lettre','autre') NOT NULL DEFAULT 'autre',
  `title`         VARCHAR(160) NOT NULL DEFAULT '',
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name`   VARCHAR(255) NOT NULL,
  `mime`          VARCHAR(120) NOT NULL DEFAULT '',
  `size`          INT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_by`   INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_res_owner` (`owner_id`),
  CONSTRAINT `fk_res_owner` FOREIGN KEY (`owner_id`)
      REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- File d'attente des ajouts d'entreprises (notifications groupées) ----------
CREATE TABLE IF NOT EXISTS `company_add_queue` (
  `owner_id`   INT UNSIGNED NOT NULL,
  `count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `actor_id`   INT UNSIGNED DEFAULT NULL,
  `actor_name` VARCHAR(120) NOT NULL DEFAULT '',
  `first_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`owner_id`),
  CONSTRAINT `fk_caq_owner` FOREIGN KEY (`owner_id`)
      REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== Lot v1.1 : avatars en base, appareils, e-mails, canaux, logos =====
SET NAMES utf8mb4;

-- Photo de profil stockée en base (survit aux mises à jour de fichiers)
CREATE TABLE IF NOT EXISTS `user_avatars` (
  `user_id`    INT UNSIGNED NOT NULL,
  `mime`       VARCHAR(60)  NOT NULL DEFAULT 'image/png',
  `data`       LONGBLOB     NOT NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_av_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Appareils / sessions (« se souvenir de moi » + mes appareils connectés)
CREATE TABLE IF NOT EXISTS `user_devices` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `selector`   CHAR(24)     NOT NULL,
  `validator`  CHAR(64)     NOT NULL,
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `last_active` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_selector` (`selector`),
  KEY `idx_dev_user` (`user_id`),
  CONSTRAINT `fk_dev_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Échanges d'e-mails avec l'entreprise
CREATE TABLE IF NOT EXISTS `company_emails` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `owner_id`   INT UNSIGNED NOT NULL,
  `direction`  ENUM('recu','envoye') NOT NULL DEFAULT 'recu',
  `subject`    VARCHAR(200) NOT NULL DEFAULT '',
  `body`       TEXT         NOT NULL,
  `email_date` DATE         DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mail_company` (`company_id`),
  CONSTRAINT `fk_mail_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Canal de candidature (Indeed, HelloWork, spontanée, LinkedIn, personnalisé)
-- Exécuté à part car ALTER échoue si la colonne existe déjà : voir upgrade.php.
