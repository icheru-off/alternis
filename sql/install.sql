-- ============================================================
--  Alternis — Schéma de base de données
--  Encodage : utf8mb4 / InnoDB
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Utilisateurs ----------
CREATE TABLE IF NOT EXISTS `users` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`         VARCHAR(60)  NOT NULL,
  `email`            VARCHAR(160) NOT NULL,
  `password_hash`    VARCHAR(255) NOT NULL,
  `full_name`        VARCHAR(120) NOT NULL DEFAULT '',
  `role`             ENUM('admin','student','parent') NOT NULL DEFAULT 'student',
  `avatar`           VARCHAR(255) NOT NULL DEFAULT '',
  `linked_student_id` INT UNSIGNED DEFAULT NULL,   -- pour les comptes parent : l'étudiant suivi
  `otp_enabled`      TINYINT(1)   NOT NULL DEFAULT 0,
  `otp_secret`       VARCHAR(64)  NOT NULL DEFAULT '',
  `theme_pref`       ENUM('auto','light','dark') NOT NULL DEFAULT 'auto',
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login`       DATETIME     DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email` (`email`),
  KEY `idx_linked` (`linked_student_id`),
  CONSTRAINT `fk_users_linked` FOREIGN KEY (`linked_student_id`)
      REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Entreprises / candidatures ----------
CREATE TABLE IF NOT EXISTS `companies` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_id`       INT UNSIGNED NOT NULL,          -- l'étudiant propriétaire de la donnée
  `org_id`         INT UNSIGNED NULL,              -- fiche entreprise rattachée
  `name`           VARCHAR(160) NOT NULL,
  `sector`         VARCHAR(120) NOT NULL DEFAULT '',
  `position`       VARCHAR(160) NOT NULL DEFAULT '', -- intitulé du poste visé
  `contact_name`   VARCHAR(120) NOT NULL DEFAULT '',
  `email`          VARCHAR(160) NOT NULL DEFAULT '',
  `phone`          VARCHAR(40)  NOT NULL DEFAULT '',
  `address`        VARCHAR(255) NOT NULL DEFAULT '',
  `city`           VARCHAR(120) NOT NULL DEFAULT '',
  `postal_code`    VARCHAR(20)  NOT NULL DEFAULT '',
  `website`        VARCHAR(200) NOT NULL DEFAULT '',
  `status`         ENUM('a_postuler','brouillon','envoye','relance','repondu','entretien','accepte','refuse')
                   NOT NULL DEFAULT 'brouillon',
  `source`         VARCHAR(80)  NOT NULL DEFAULT '', -- LinkedIn, spontanée, école...
  `apply_channel`  VARCHAR(60)  NOT NULL DEFAULT '', -- Indeed, HelloWork, spontanée, personnalisé
  `priority`       ENUM('basse','normale','haute') NOT NULL DEFAULT 'normale',
  `salary`         VARCHAR(60)  NOT NULL DEFAULT '',
  `applied_date`   DATE         DEFAULT NULL,
  `response_date`  DATE         DEFAULT NULL,
  `interview_date` DATE         DEFAULT NULL,
  `followup_date`  DATE         DEFAULT NULL,
  `notes`          TEXT         NULL,
  `created_by`     INT UNSIGNED DEFAULT NULL,       -- qui a saisi (étudiant ou parent)
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner_id`),
  KEY `idx_status` (`status`),
  KEY `idx_applied` (`applied_date`),
  CONSTRAINT `fk_comp_owner` FOREIGN KEY (`owner_id`)
      REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Notifications ----------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `type`       VARCHAR(40)  NOT NULL DEFAULT 'info',
  `title`      VARCHAR(160) NOT NULL,
  `body`       VARCHAR(400) NOT NULL DEFAULT '',
  `url`        VARCHAR(200) NOT NULL DEFAULT '',
  `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
  `group_key`  VARCHAR(80)  NOT NULL DEFAULT '',   -- pour regrouper / dédupliquer
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`,`is_read`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`)
      REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Journal d'activité (alimente les recommandations) ----------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `owner_id`   INT UNSIGNED DEFAULT NULL,
  `action`     VARCHAR(40)  NOT NULL,
  `entity`     VARCHAR(40)  NOT NULL DEFAULT '',
  `entity_id`  INT UNSIGNED DEFAULT NULL,
  `detail`     VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Suivi de génération des notifications (throttle) ----------
CREATE TABLE IF NOT EXISTS `notif_meta` (
  `user_id`         INT UNSIGNED NOT NULL,
  `day`             DATE NOT NULL,
  `count`           INT UNSIGNED NOT NULL DEFAULT 0,
  `last_generated`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`user_id`,`day`),
  CONSTRAINT `fk_meta_user` FOREIGN KEY (`user_id`)
      REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Abonnements Web Push (optionnel, PWA) ----------
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `endpoint`   VARCHAR(500) NOT NULL,
  `p256dh`     VARCHAR(255) NOT NULL DEFAULT '',
  `auth`       VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`)
      REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Clés d'accès (passkeys / WebAuthn) ----------
CREATE TABLE IF NOT EXISTS `webauthn_credentials` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `credential_id` VARCHAR(512) NOT NULL,
  `public_key`    TEXT         NOT NULL,
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


-- ---------- File d'attente des ajouts d'entreprises (notifications groupees) ----------
CREATE TABLE IF NOT EXISTS `company_add_queue` (
  `owner_id`   INT UNSIGNED NOT NULL,
  `count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `actor_id`   INT UNSIGNED DEFAULT NULL,
  `actor_name` VARCHAR(120) NOT NULL DEFAULT '',
  `first_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`owner_id`),
  CONSTRAINT `fk_caq_owner` FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_avatars` (
  `user_id`    INT UNSIGNED NOT NULL,
  `mime`       VARCHAR(60)  NOT NULL DEFAULT 'image/png',
  `data`       LONGBLOB     NOT NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_av_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_devices` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `selector`    CHAR(24)     NOT NULL,
  `validator`   CHAR(64)     NOT NULL,
  `user_agent`  VARCHAR(255) NOT NULL DEFAULT '',
  `ip`          VARCHAR(45)  NOT NULL DEFAULT '',
  `last_active` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_selector` (`selector`),
  KEY `idx_dev_user` (`user_id`),
  CONSTRAINT `fk_dev_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `company_emails` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `owner_id`   INT UNSIGNED NOT NULL,
  `direction`  ENUM('recu','envoye') NOT NULL DEFAULT 'recu',
  `subject`    VARCHAR(200) NOT NULL DEFAULT '',
  `body`       TEXT         NOT NULL,
  `email_date` DATE         DEFAULT NULL,
  `attachment_name` VARCHAR(200) DEFAULT NULL,
  `attachment_file` VARCHAR(120) DEFAULT NULL,
  `attachment_mime` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mail_company` (`company_id`),
  CONSTRAINT `fk_mail_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;


-- Liens de partage en lecture seule (vue publique des candidatures)
CREATE TABLE IF NOT EXISTS `share_links` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token`        VARCHAR(64)  NOT NULL,
  `owner_id`     INT UNSIGNED NOT NULL,
  `created_by`   INT UNSIGNED NOT NULL,
  `label`        VARCHAR(120) NOT NULL DEFAULT '',
  `statuses`     VARCHAR(255) NOT NULL DEFAULT '',
  `fields`       VARCHAR(500) NOT NULL DEFAULT '',
  `show_contact` TINYINT(1)   NOT NULL DEFAULT 0,
  `show_notes`   TINYINT(1)   NOT NULL DEFAULT 0,
  `expires_at`   DATETIME     NULL,
  `revoked`      TINYINT(1)   NOT NULL DEFAULT 0,
  `views`        INT UNSIGNED NOT NULL DEFAULT 0,
  `last_view`    DATETIME     NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  KEY `idx_share_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================================
-- Entreprises : une fiche par société, N candidatures rattachées
-- =====================================================================
CREATE TABLE IF NOT EXISTS `orgs` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_id`     INT UNSIGNED NOT NULL,
  `name`         VARCHAR(160) NOT NULL,
  `name_key`     VARCHAR(160) NOT NULL,
  `name_compact` VARCHAR(160) NOT NULL DEFAULT '',
  `domain`       VARCHAR(160) NOT NULL DEFAULT '',
  `sector`       VARCHAR(120) NOT NULL DEFAULT '',
  `website`      VARCHAR(200) NOT NULL DEFAULT '',
  `city`         VARCHAR(120) NOT NULL DEFAULT '',
  `postal_code`  VARCHAR(20)  NOT NULL DEFAULT '',
  `address`      VARCHAR(255) NOT NULL DEFAULT '',
  `contact_name` VARCHAR(120) NOT NULL DEFAULT '',
  `email`        VARCHAR(160) NOT NULL DEFAULT '',
  `phone`        VARCHAR(40)  NOT NULL DEFAULT '',
  `notes`        TEXT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_owner_key` (`owner_id`,`name_key`),
  KEY `idx_org_owner` (`owner_id`),
  KEY `idx_org_domain` (`domain`),
  KEY `idx_org_compact` (`owner_id`,`name_compact`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
