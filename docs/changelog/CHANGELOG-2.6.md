# Alternis 2.6 — Relances depuis votre Gmail + revue de code

## Nouveau : envoyez vos relances depuis votre propre Gmail
Chaque utilisateur peut désormais relier son adresse Gmail dans
Réglages → Relances. Vos relances partent alors directement de votre boîte,
et les réponses des recruteurs arrivent chez vous.

- Connexion par **mot de passe d'application** Google (jamais votre mot de passe
  principal, révocable à tout moment).
- Le mot de passe est stocké **chiffré** (AES-256-GCM) — jamais en clair.
- Bouton **« Envoyer un test »** pour vérifier la configuration.
- **Relances automatiques** (optionnelles) : quand une candidature attend une
  réponse depuis trop longtemps, Alternis peut relancer à votre place depuis
  votre Gmail. Activable/désactivable d'un interrupteur. (Nécessite la tâche
  cron côté serveur ; sinon, les relances restent en envoi manuel.)

La configuration Gmail n'est plus dans config.php : elle est propre à chaque
utilisateur, directement dans les paramètres d'Alternis.

## Revue de code & correctifs
- Correction d'un défaut de style : une ombre (`--shadow-sm`) n'était pas
  définie et ne s'appliquait pas.
- Nettoyage : suppression d'un ancien point d'entrée d'API devenu inutile.
- Vérification complète : toutes les pages se rendent sans erreur, aucune
  fonction manquante, aucune fuite d'information en cas d'erreur.
