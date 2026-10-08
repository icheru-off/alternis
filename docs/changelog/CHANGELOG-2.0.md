# Alternis 2.0 — Simplification & édition groupée

## Offres suggérées : retirées
La page « Offres suggérées » et son entrée dans le menu ont été supprimées, avec
tout le code associé (page, script, API, intégrations France Travail / La Bonne
Alternance, fichier de diagnostic). Le reste de l'application est inchangé.

## Source et Type fusionnés
Il y avait deux champs qui se recouvraient : « Source » et « Type de candidature ».
« Source » a été supprimé. Les valeurs de Source déjà saisies sont **récupérées
automatiquement** dans « Type » (là où le Type était vide), au premier chargement
de la page Candidatures. Rien n'est perdu.

## Type de candidature enrichi
Le menu déroulant propose désormais : Spontanée, Site de l'entreprise,
La Bonne Alternance, France Travail, Jobteaser, Indeed, HelloWork, LinkedIn,
Jooble, Welcome to the Jungle, Monster, APEC, Glassdoor, Salon / forum,
Cooptation, École / CFA — et **« Autre… »** pour saisir une valeur libre.

## Édition groupée (sélection multiple, page Candidatures)
En plus du statut et de la priorité, la barre de sélection permet maintenant de
changer d'un coup, sur plusieurs candidatures :
- le **type de candidature** (liste + « Autre… ») ;
- la **date de candidature** et la **date de réponse**, via un bouton
  « Modifier les dates… » (avec possibilité d'effacer une date).

## Sélection multiple sur la page Entreprises
Un bouton « Sélectionner » active un mode sélection, avec **« Tout sélectionner »**.
On peut alors, sur les fiches choisies :
- **Fusionner** plusieurs fiches en une seule (utile pour dédoublonner) ;
- **Supprimer** (les fiches ayant encore des candidatures sont conservées).

## Exports
Les exports CSV et Excel gagnent une colonne **« Type de candidature »** et
perdent la colonne « Source » (désormais fusionnée).

## Mise à jour
Réenvoyez les fichiers, désinscrivez le service worker puis Ctrl+Shift+R.
Ne remplacez pas config.php. La récupération Source → Type se fait toute seule.

---

## 2.0.1 — Nettoyage + titre animé

### Nettoyage du code
Suppression des restes de la fonction « Offres suggérées » :
- clés France Travail et La Bonne Alternance retirées de config.php ;
- tables `offer_cache` et `offer_hidden` retirées du schéma d'installation.
La migration « Source → Type » est conservée volontairement (elle doit encore
s'exécuter une fois chez vous pour récupérer les anciennes valeurs).

### Titre de l'onglet animé
Quand l'onglet Alternis passe en arrière-plan, son titre fait défiler vos rappels,
en alternance avec le nom du site :

> Alternis — 9 nouvelles notifications → Candidatures · Alternis →
> Alternis — 3 relances à effectuer → Candidatures · Alternis →
> Alternis — 2 offres à faire → …

Seuls les compteurs non nuls apparaissent, et le titre reprend sa forme normale
dès que vous revenez sur l'onglet. S'il n'y a rien à signaler, rien ne bouge.
