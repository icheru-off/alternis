# Alternis 2.4.1 — Extension prête pour les stores

## Conformité Firefox (AMO)
Le rapport de validation Firefox signalait deux points, désormais corrigés :

1. **Erreur bloquante** — la clé `data_collection_permissions` était absente.
   Mozilla l'exige depuis novembre 2025 pour toute nouvelle extension. Elle est
   maintenant déclarée honnêtement : l'extension transmet, vers votre seul
   compte Alternis, le contenu lu sur la page d'offre (`websiteContent`), l'URL
   de l'annonce (`browsingActivity`) et un jeton d'authentification
   (`authenticationInfo`). Aucune donnée vers un tiers.
2. **Avertissement** — `background.service_worker` était ignoré par Firefox. Le
   paquet Firefox utilise désormais `background.scripts`, sans avertissement.

## Deux paquets distincts
Pour éviter tout compromis entre navigateurs, le build génère maintenant :
- `alternis-extension-firefox.zip` (background.scripts + déclaration de données,
  Firefox 140+) ;
- `alternis-extension-chrome.zip` (service_worker, Chrome/Edge/Opera/Brave).

Les réglages de l'application proposent les deux téléchargements. Extension
version 1.2.0.
