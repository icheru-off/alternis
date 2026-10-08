# Extension Alternis — Suivi de candidatures

Enregistrez vos candidatures d'alternance dans Alternis en un clic, depuis
n'importe quel site de recrutement (LinkedIn, Indeed, Welcome to the Jungle,
HelloWork, France Travail, APEC, Jobteaser, Monster, Glassdoor, Jooble,
La Bonne Alternance…) **et même les sites de recruteurs non répertoriés**,
grâce à une analyse automatique de la page.

## Deux paquets selon le navigateur

- **alternis-extension-firefox.zip** — pour Firefox (Manifest V3 avec
  `background.scripts` et la déclaration de collecte de données exigée par
  Mozilla).
- **alternis-extension-chrome.zip** — pour Chrome, Edge, Opera et Brave
  (Manifest V3 avec `service_worker`).

Le dossier `src/` contient les sources communes ; les deux paquets en sont
générés par :

    python3 extension/build.py      # → extension/dist/*.zip

## Se connecter

1. Sur Alternis : **Réglages → Extension → Générer** un mot de passe d'application.
   Copiez-le (il n'est affiché qu'une fois).
2. Dans l'extension : renseignez l'adresse de votre site Alternis
   (par ex. https://app.alternis.fixit-service.fr pour l’instance officielle, ou l’URL de votre propre installation), votre identifiant et ce mot de passe.

Le mot de passe d'application ne remplace pas votre mot de passe habituel et
peut être révoqué à tout moment.

## Utiliser

Sur une page d'offre, cliquez l'icône Alternis : l'extension pré-remplit
l'entreprise, le poste, la ville et la date. « Enregistrer » ajoute la
candidature, « Éditer » ouvre les champs pour corriger. Sur les plateformes
reconnues, elle peut aussi détecter automatiquement l'envoi d'une candidature.

Sur les pages d'offres, deux **boutons flottants** apparaissent en bas à droite :
« ✓ Ajouter en candidature » et « ＋ Ajouter aux offres à faire » (bouton
« × Masquer » pour les cacher sur la page). Ils se désactivent dans le popup :
*Afficher les boutons Alternis sur les pages d'offres*.

## Installation (test/développement)

### Chrome, Edge, Opera, Brave
1. Décompressez `dist/alternis-extension-chrome.zip`.
2. Ouvrez `chrome://extensions` (ou équivalent), activez le **mode développeur**.
3. **Charger l'extension non empaquetée** → choisissez le dossier décompressé.

### Firefox
1. Ouvrez `about:debugging#/runtime/this-firefox`.
2. **Charger un module complémentaire temporaire** → choisissez `manifest.json`.
> Firefox 140 minimum (requis pour le consentement intégré à la collecte de données).

## Collecte de données (transparence)

L'extension transmet uniquement, vers **votre propre compte Alternis** :
- le contenu lu sur la page d'offre (entreprise, poste, ville) — `websiteContent` ;
- l'URL de l'offre enregistrée — `browsingActivity` ;
- un jeton d'authentification pour votre compte — `authenticationInfo`.

Aucune donnée n'est envoyée à un tiers. Ces catégories sont déclarées dans le
manifest Firefox et présentées à l'installation.

## Publication sur les stores

- **Firefox (AMO)** : soumettez `alternis-extension-firefox.zip` sur
  addons.mozilla.org. Le manifest inclut `data_collection_permissions` (exigé
  pour les nouvelles extensions depuis novembre 2025).
- **Chrome Web Store** : soumettez `alternis-extension-chrome.zip`.
