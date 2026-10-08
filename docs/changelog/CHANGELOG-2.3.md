# Alternis 2.3 — Extension navigateur

## Une extension pour capturer vos candidatures en un clic
Nouvelle extension navigateur (Chrome, Edge, Opera, Brave et Firefox) qui
enregistre une candidature dans Alternis directement depuis une page d'offre.

### Connexion sécurisée par mot de passe d'application
Dans **Réglages → Extension**, générez un « mot de passe d'application » dédié :
- il ne remplace pas votre mot de passe habituel ;
- il est révocable à tout moment (chaque appareil peut avoir le sien) ;
- l'extension l'utilise pour obtenir un jeton d'accès (valable 90 jours).

Aucun cookie, aucun partage de votre mot de passe principal.

### Capture intelligente
Sur une page d'offre, l'extension pré-remplit l'entreprise, le poste, la ville,
la date et le type de candidature, puis vous propose :
- **Enregistrer** : ajout immédiat ;
- **Éditer** : correction avant enregistrement.

### Prise en charge très large + détection automatique
Parseurs dédiés pour LinkedIn, Indeed, Welcome to the Jungle, HelloWork,
France Travail, APEC, Jobteaser, Monster, Glassdoor, Jooble et La Bonne
Alternance. **Pour tout autre site** (y compris le site propre d'un recruteur),
l'extension analyse la page — données structurées schema.org, balises Open
Graph, titre — et remplit ce qu'elle trouve, en vous invitant à vérifier.
Elle ne bloque jamais : au pire, elle propose le nom de domaine comme
entreprise et le titre comme poste.

### Installation
Téléchargez l'extension depuis **Réglages → Extension**, décompressez, puis
chargez-la en mode développeur (voir le README inclus). Le paquet est prêt pour
une soumission aux stores officiels.

## Détails techniques
- Nouvelle API à jetons `api/ext.php` (login, capture, meta, logout), avec CORS.
- Tables créées automatiquement : `app_passwords`, `ext_tokens`.
- Nouvelle colonne `companies.source_url` (lien de l'offre d'origine), créée à
  la volée.
