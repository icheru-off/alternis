<?php
/**
 * Alternis — Configuration (MODÈLE)
 * ---------------------------------
 * 1. Copiez ce fichier en config/config.php
 * 2. Remplacez toutes les valeurs « CHANGEZ_MOI » / example.com
 * 3. Générez APP_SECRET, CRON_TOKEN et les clés VAPID avec :
 *      php tools/generate-keys.php
 *
 * config/config.php est exclu du dépôt Git (.gitignore) : ne le commitez jamais.
 */

// ---- Base de données ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'alternis');
define('DB_USER', 'alternis_user');
define('DB_PASS', 'CHANGEZ_MOI');
define('DB_CHARSET', 'utf8mb4');

// ---- Application ----
define('APP_NAME', 'Alternis');
define('APP_TAGLINE', 'Pilotez votre recherche d\'alternance');

/**
 * URL de base. Laissez vide ('') pour une détection automatique.
 * Exemple si l'app est dans un sous-dossier : '/alternis'
 */
define('APP_BASE_URL', '');

// Fuseau horaire
date_default_timezone_set('Europe/Paris');

// Dossier des fichiers uploadés (avatars). Doit être accessible en écriture.
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads');
define('UPLOAD_URL', 'assets/uploads');

// Clé applicative secrète (sessions + chiffrement des mots de passe Gmail).
// ⚠ Ne la modifiez PLUS après la mise en service : les mots de passe Gmail
//   déjà enregistrés par les utilisateurs deviendraient illisibles.
define('APP_SECRET', 'CHANGEZ_MOI_chaine_aleatoire_64_caracteres');

// Nombre max de notifications générées par jour et par utilisateur
define('NOTIF_MAX_PER_DAY', 4);

// Notifications groupées d'ajout d'entreprises :
//  - QUIET : minutes sans nouvel ajout avant d'envoyer le récapitulatif
//  - MAX   : envoi forcé si le lot attend depuis trop longtemps
define('NOTIF_BATCH_QUIET_MIN', 1);
define('NOTIF_BATCH_MAX_MIN', 15);

// Version des assets (pour forcer le rafraîchissement du cache)
define('ASSET_VERSION', '2.9.2');

// ---------------------------------------------------------------------------
// SMTP — envoi d'e-mails (identifiants de compte, relances…)
// ---------------------------------------------------------------------------
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');            // ssl (465) ou tls (587)
define('SMTP_USER', 'no-reply@example.com');
define('SMTP_PASS', 'CHANGEZ_MOI');
define('SMTP_FROM', 'no-reply@example.com');
define('SMTP_FROM_NAME', 'Alternis');

// ---------------------------------------------------------------------------
// GMAIL PERSONNEL — pour l'ENVOI DES RELANCES uniquement.
//
// Les relances doivent partir de votre propre adresse : un recruteur qui
// répond doit vous joindre, pas la boîte no-reply du système. Les
// notifications et les identifiants continuent, eux, d'utiliser le SMTP
// ci-dessus.
//
// Utilisez un MOT DE PASSE D'APPLICATION Google (16 caractères), pas le mot
// de passe de votre compte. Pour en créer un :
//   1. Activez la validation en deux étapes sur votre compte Google.
//   2. Rendez-vous sur https://myaccount.google.com/apppasswords
//   3. Générez un mot de passe pour « Autre (Alternis) » et collez-le ici.
//
// Tant que ces deux lignes restent vides, le bouton « Envoyer » d'une relance
// est masqué (le bouton « Copier le texte » reste disponible).
// ---------------------------------------------------------------------------
define('GMAIL_USER', '');            // ex. 'prenom.nom@gmail.com'
define('GMAIL_APP_PASSWORD', '');    // ex. 'abcd efgh ijkl mnop'
define('GMAIL_FROM_NAME', '');       // ex. 'Prénom Nom' (facultatif)
// URL de base publique (pour les liens dans les e-mails)
define('APP_URL', 'https://alternis.example.com');

// ---------------------------------------------------------------------------
// WEB PUSH (VAPID) — notifications même application fermée.
// Générez-les avec tools/generate-keys.php. Une fois en production, ne les régénérez pas,
// sinon tous les appareils déjà abonnés devront se réabonner.
// ---------------------------------------------------------------------------
define('VAPID_SUBJECT', 'mailto:contact@example.com');
define('VAPID_PUBLIC_KEY', '');
define('VAPID_PRIVATE_PEM', '');  // PEM complet (-----BEGIN PRIVATE KEY----- …), voir tools/generate-keys.php

// Jeton secret pour la tâche planifiée (cron.php?token=...)
define('CRON_TOKEN', 'CHANGEZ_MOI_jeton_aleatoire');

// Environnement : 'production' masque les erreurs, 'dev' les affiche.
define('APP_ENV', 'production');

if (APP_ENV === 'dev') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
