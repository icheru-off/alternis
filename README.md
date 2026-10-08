<p align="center">
  <img src="site/assets/logo.png" alt="Alternis" width="320">
</p>

<p align="center">
  <strong>Pilote ta recherche d'alternance ou de stage, de la première candidature à la signature.</strong>
</p>

<p align="center">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white">
  <img alt="MySQL" src="https://img.shields.io/badge/MySQL-5.7%2B%20%2F%20MariaDB%2010.3%2B-4479A1?logo=mysql&logoColor=white">
  <img alt="PWA" src="https://img.shields.io/badge/PWA-installable-7C5CFC">
  <img alt="Extension" src="https://img.shields.io/badge/Extension-Firefox%20%7C%20Chrome%20%7C%20Edge%20%7C%20Opera-22D3EE">
  <img alt="Version" src="https://img.shields.io/badge/version-2.9.2-success">
</p>

---

Alternis est une application web (PHP / MySQL, sans framework ni dépendance Composer) qui centralise **toute** une recherche de contrat : entreprises, candidatures, relances, entretiens, documents et statistiques. Elle s'accompagne d'une **extension navigateur** qui capture les offres en un clic depuis LinkedIn, Indeed, Welcome to the Jungle, HelloWork, France Travail… et d'un **site vitrine** statique.

- 🌐 Instance officielle : **https://app.alternis.fixit-service.fr**
- 🏠 Site de présentation : **https://alternis.fixit-service.fr**

## Sommaire

- [Fonctionnalités](#fonctionnalités)
- [Stack technique](#stack-technique)
- [Structure du projet](#structure-du-projet)
- [Installation](#installation)
- [Configuration détaillée](#configuration-détaillée)
- [Tâche planifiée (cron)](#tâche-planifiée-cron)
- [Extension navigateur](#extension-navigateur)
- [Site vitrine](#site-vitrine)
- [Mise à jour](#mise-à-jour)
- [Sécurité](#sécurité)
- [Dépannage](#dépannage)
- [Licence](#licence)

## Fonctionnalités

### Suivi de recherche
- **Tableau de bord personnalisable** : widgets (candidatures, taux de réponse, envoyées cette semaine, taux de réussite…), activation / réordonnancement par glisser-déposer avec **aperçu en temps réel**, bouton « Vue par défaut ».
- **Candidatures** : statuts (à postuler, envoyée, relancée, entretien, acceptée, refusée…), filtres, recherche, **sélection multiple** et actions groupées, import de liste (collage ou CSV).
- **Offres à faire** : file d'attente des offres repérées, à transformer en candidature en un clic.
- **Fiches entreprise** : contact, historique, e-mails échangés, plusieurs candidatures par société, regroupement par organisation.
- **Mode alternance ou stage** choisi à l'inscription : libellés, objectifs et exports s'adaptent.
- **Recherche d'entreprise** pré-remplie via les API publiques de l'État (`recherche-entreprises.api.gouv.fr`, `geo.api.gouv.fr`, sans clé).

### Relances et notifications
- **Relances automatiques depuis le Gmail de l'utilisateur** : chaque utilisateur relie son adresse Gmail dans *Paramètres* avec un mot de passe d'application Google (chiffré en AES-256-GCM en base). Les réponses des recruteurs arrivent directement dans sa boîte.
- **Notifications intelligentes** (relances à faire, entretiens à préparer, objectifs…), regroupées et plafonnées par jour.
- **Web Push (VAPID)** : notifications reçues même application fermée (Android, desktop, iOS en PWA).

### Collaboration et partage
- **Mode collaboratif** : invitez un proche (parent, tuteur, ami) qui consulte et aide à gérer le suivi.
- **Lien de partage public** en lecture seule, révocable.

### Exports
- **PDF** (rapport complet ou chiffres clés, graphiques), **Excel `.xlsx`** (feuille *Données* + *Synthèse* statut × mois) et **CSV** (UTF-8, `;`).

### Compte et sécurité
- Inscription avec e-mail, réinitialisation du mot de passe.
- **Double authentification TOTP** (Google Authenticator, Authy…) et **clés d'accès / passkeys** (WebAuthn : Face ID, Touch ID, Windows Hello, FIDO2).
- Gestion des appareils connectés, espace **Ressources** (CV, lettres de motivation).
- Administration : gestion des comptes, mode maintenance, notes de version intégrées.

### Interface
- Design glassmorphism / bento, thème clair-sombre automatique, **responsive** PC et mobile.
- **PWA** installable (icône sur l'écran d'accueil, service worker).

## Stack technique

| Couche | Choix |
|---|---|
| Back-end | PHP 8 « vanilla », PDO MySQL, sessions natives |
| Base de données | MySQL 5.7+ / MariaDB 10.3+ (`utf8mb4`) |
| Front-end | HTML / CSS / JavaScript sans framework, Chart.js |
| PDF | FPDF (inclus dans `lib/fpdf`) |
| Push | Implémentation Web Push / VAPID autonome (`lib/webpush.php`, OpenSSL) |
| Passkeys | Implémentation WebAuthn autonome (CBOR, COSE, OpenSSL) |
| E-mails | Client SMTP intégré (`includes/mailer.php`) |
| Extension | WebExtension Manifest V3 (Firefox + Chromium) |

Aucune dépendance à installer : tout est embarqué.

## Structure du projet

```
.
├── index.php             # Routeur principal (index.php?page=…)
├── install.php           # Assistant d'installation (à supprimer ensuite)
├── upgrade.php           # Migrations de base pour les mises à jour (à supprimer ensuite)
├── cron.php              # Tâche planifiée (notifications, push, relances)
├── share.php             # Vue publique en lecture seule
├── manifest.php, sw.js   # PWA
├── api/                  # Endpoints JSON (candidatures, push, extension, relances…)
├── auth/                 # Connexion, inscription, réinitialisation
├── pages/                # Pages de l'application
├── includes/             # Logique métier (auth, mailer, crypto, notifications…)
├── export/               # Exports PDF / XLSX / CSV
├── lib/                  # FPDF, Web Push
├── assets/               # CSS, JS, images, uploads (non versionnés)
├── sql/                  # Schéma (install.sql) et migrations (upgrade.sql)
├── config/
│   └── config.example.php   # Modèle de configuration → à copier en config.php
├── tools/
│   └── generate-keys.php    # Génère APP_SECRET, CRON_TOKEN et les clés VAPID
├── extension/
│   ├── src/              # Sources communes de l'extension
│   └── build.py          # Génère les paquets Firefox et Chromium
├── site/                 # Site vitrine statique
└── docs/                 # Notes de version, guide des notifications
```

## Installation

### Prérequis

- **PHP 8.0+** avec les extensions `pdo_mysql`, `mbstring`, `openssl`, `zip`, `gd`, `iconv`, `fileinfo`.
- **MySQL 5.7+** ou **MariaDB 10.3+**.
- Apache avec les fichiers `.htaccess` autorisés (hébergement mutualisé cPanel, VPS…).
- **HTTPS obligatoire** en production (OTP, passkeys, Web Push et PWA l'exigent).
- Une boîte e-mail SMTP pour les e-mails système (inscription, réinitialisation).

### Étapes

1. **Cloner ou télécharger** le dépôt et envoyer son contenu dans le dossier web (ex. `public_html/` ou `public_html/alternis/`).

   ```bash
   git clone https://github.com/<votre-compte>/alternis.git
   ```

2. **Créer une base MySQL** et un utilisateur ayant tous les droits dessus.

3. **Créer la configuration** à partir du modèle :

   ```bash
   cp config/config.example.php config/config.php
   ```

   puis renseigner la base de données, le SMTP et `APP_URL` (voir [Configuration détaillée](#configuration-détaillée)).

4. **Générer les secrets** et coller la sortie dans `config/config.php` (en remplaçant les lignes correspondantes) :

   ```bash
   php tools/generate-keys.php
   ```

5. Ouvrir **`https://votre-domaine/install.php`** : l'assistant crée les tables et le **compte administrateur**.

6. **Supprimer `install.php`** (et `upgrade.php`) du serveur.

7. Vérifier que `assets/uploads/` est accessible en **écriture** (`755` ou `775`).

8. Configurer la **[tâche cron](#tâche-planifiée-cron)**.

## Configuration détaillée

Toutes les options sont dans `config/config.php` (jamais versionné, voir `.gitignore`).

| Constante | Rôle |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Connexion MySQL |
| `APP_URL` | URL publique complète, utilisée dans les e-mails (ex. `https://alternis.example.com`) |
| `APP_BASE_URL` | Sous-dossier si la détection automatique échoue (ex. `'/alternis'`) |
| `APP_SECRET` | Clé secrète des sessions **et** du chiffrement des mots de passe Gmail |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM` | E-mails système (`ssl` + 465 ou `tls` + 587) |
| `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_PEM` | Web Push. Laissés vides, le push serveur est simplement désactivé |
| `CRON_TOKEN` | Jeton exigé par `cron.php` |
| `NOTIF_MAX_PER_DAY` | Plafond de notifications par utilisateur et par jour |
| `NOTIF_BATCH_QUIET_MIN`, `NOTIF_BATCH_MAX_MIN` | Regroupement des notifications d'ajout d'entreprises |
| `ASSET_VERSION` | Version des assets (cache navigateur) |
| `APP_ENV` | `production` (erreurs masquées) ou `dev` |
| `GMAIL_*` | Ancien mode global, **facultatif** : chaque utilisateur configure désormais son Gmail dans *Paramètres* |

> ⚠️ **Ne changez plus `APP_SECRET` une fois en production** : les mots de passe Gmail déjà enregistrés deviendraient illisibles (chaque utilisateur devrait les ressaisir).
>
> ⚠️ **Ne régénérez pas les clés VAPID** en production : tous les appareils abonnés devraient se réabonner.

### Relances Gmail (côté utilisateur)

Chaque utilisateur, dans **Paramètres → Envoyer vos relances depuis votre Gmail** :

1. active la validation en deux étapes sur son compte Google ;
2. crée un mot de passe d'application sur <https://myaccount.google.com/apppasswords> ;
3. colle son adresse et ce mot de passe dans Alternis.

Le mot de passe est chiffré (AES-256-GCM, clé dérivée de `APP_SECRET`) et révocable à tout moment côté Google.

## Tâche planifiée (cron)

Le cron envoie les notifications programmées, les push et les relances automatiques, même quand personne n'a l'application ouverte. À lancer **toutes les 5 minutes** :

```cron
*/5 * * * * /usr/bin/php -q /chemin/vers/alternis/cron.php token=VOTRE_CRON_TOKEN
```

ou par URL :

```bash
curl -s "https://alternis.example.com/cron.php?token=VOTRE_CRON_TOKEN" > /dev/null
```

Réponse attendue : `OK — notifications creees: N | push envoyes: N | XXms`. Guide complet (iOS, dépannage) : [`docs/NOTIFICATIONS.md`](docs/NOTIFICATIONS.md).

## Extension navigateur

Sources dans [`extension/src`](extension/src) (Manifest V3). Elle :

- pré-remplit entreprise, poste, ville et source depuis la page d'offre (popup) ;
- affiche sur les pages d'offres des **boutons flottants** « ✓ Ajouter en candidature » et « ＋ Ajouter aux offres à faire » (désactivables dans le popup) ;
- peut **détecter automatiquement** l'envoi d'une candidature sur les plateformes reconnues.

### Construire les paquets

```bash
python3 extension/build.py
```

Produit dans `extension/dist/` :

| Paquet | Navigateurs |
|---|---|
| `alternis-extension-firefox.zip` | Firefox 140+ (`background.scripts` + `data_collection_permissions`) |
| `alternis-extension-chrome.zip` | Chrome, Edge, Opera, Brave (`background.service_worker`) |

### Connexion à une instance

1. Dans Alternis : **Paramètres → Extension → Générer** un mot de passe d'application.
2. Dans le popup de l'extension : adresse de l'instance, identifiant et ce mot de passe.

> Pour une instance auto-hébergée, adaptez l'URL par défaut (`DEFAULT_BASE` dans `popup.js`, valeur de repli dans `content.js`, placeholder de `popup.html`) et l'identifiant `browser_specific_settings.gecko.id` du manifest avant de publier votre propre version sur les stores.

Détails (installation de test, collecte de données, stores) : [`extension/README.md`](extension/README.md).

## Site vitrine

Le dossier [`site/`](site) est un site statique (HTML/CSS/JS) indépendant de l'application : il se déploie tel quel sur n'importe quel hébergement ou sur GitHub Pages.

## Mise à jour

1. Sauvegarder la base et `config/config.php`.
2. Envoyer les nouveaux fichiers (sans écraser `config/config.php` ni `assets/uploads/`).
3. Ouvrir `upgrade.php` connecté en administrateur, puis le supprimer.
4. Incrémenter `ASSET_VERSION` (config) et `CACHE` dans `sw.js` pour forcer le rafraîchissement des clients.

Historique des versions : [`docs/changelog`](docs/changelog).

## Sécurité

- `config/`, `includes/`, `lib/`, `sql/`, `docs/`, `tools/` sont inaccessibles depuis le web (`.htaccess`) ; `assets/uploads/` interdit l'exécution de scripts.
- Jetons **CSRF** sur toutes les écritures, mots de passe hachés avec `password_hash()` (bcrypt), requêtes préparées PDO.
- Mots de passe Gmail chiffrés en AES-256-GCM, jetons d'extension révocables, lien de partage à jeton imprévisible.
- `config/config.php` est exclu du dépôt : **ne commitez jamais vos secrets**. Si une clé a fuité, régénérez-la immédiatement.

Vous avez trouvé une faille ? Merci de la signaler en privé plutôt que par une issue publique.

## Dépannage

| Problème | Solution |
|---|---|
| « Configuration absente » | Copier `config/config.example.php` en `config/config.php` |
| Page blanche / erreur 500 | Passer temporairement `APP_ENV` à `'dev'` |
| « La connexion à la base de données a échoué » | Vérifier `DB_*` et les droits de l'utilisateur MySQL |
| Liens ou assets cassés | Renseigner `APP_BASE_URL` avec le sous-dossier exact |
| « Envoi de l'e-mail impossible » | Vérifier `SMTP_*` (port/chiffrement cohérents, adresse d'envoi existante) |
| Pas de notifications push | HTTPS, clés VAPID renseignées, cron actif ; sur iPhone, installer la PWA |
| Relances Gmail refusées | Mot de passe d'application Google (pas le mot de passe du compte), 2FA activée |

## Licence

© Alternis — tous droits réservés. Le code est publié pour consultation ; aucune licence de réutilisation n'est accordée tant qu'un fichier `LICENSE` n'est pas ajouté au dépôt.
#   a l t e r n i s  
 