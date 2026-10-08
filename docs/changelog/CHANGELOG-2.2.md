# Alternis 2.2 — Comptes, collaboration, onboarding

## Création de compte ouverte (activation par e-mail)
N'importe qui peut créer un compte depuis la page de connexion (« Créer un compte »).
L'inscription se fait en deux temps : informations + questions sur la recherche,
puis saisie d'un **code à 6 chiffres** reçu par e-mail (valable 20 min).

## Identifiant automatique « pnom »
Plus besoin de choisir un identifiant : il est généré à partir du nom, au format
première lettre du prénom + nom, collés, sans accent (« Marie Dupont » → `mdupont`).
En cas de doublon, un chiffre est ajouté (`mdupont2`). Cela évite les identifiants
interdits ou déjà pris.

## Questions sur la recherche d'alternance
À l'inscription : poste visé, secteur, ville, rythme, durée, date de début.
Modifiables ensuite dans Réglages → Profil → « Ma recherche d'alternance ».

## Réinitialisation du mot de passe
Lien « Mot de passe oublié ? » sur la page de connexion : saisie de l'e-mail,
code reçu, nouveau mot de passe. Aucune information n'est divulguée sur
l'existence ou non d'un compte pour une adresse donnée.

## Tutoriel d'accueil
À la première connexion, un tutoriel en 5 étapes présente Alternis. Il est
**ignorable** et **revisitable** à tout moment depuis Réglages → Préférences.

## Mode « Collaborer »
Depuis Réglages → Collaboration, générez un **lien d'invitation** pour qu'un autre
étudiant vous aide. **Vous choisissez les droits** : consultation seule, ou
consultation + modification. Vous voyez qui a accès, pouvez révoquer à tout moment,
et basculer vers les espaces auxquels on vous a donné accès (un bandeau « Vue »
indique alors clairement de quelles données il s'agit, avec un bouton « Revenir à
moi »). Le mode lecture seule empêche réellement toute modification côté serveur.

## Suggestions d'entreprise (rappel 2.1)
Le bouton « Compléter » a disparu : les suggestions apparaissent pendant la frappe
et complètent la fiche au clic. Désactivable dans Réglages → Préférences.

## Corrections
- Message « Offres à faire » vide : ne mentionne plus le Scraper supprimé.
- Suggestions d'entreprise : positionnement corrigé (elles tombaient en bas).
- Sécurité : la page de connexion n'affiche plus le nombre de comptes/candidatures.

## Configuration
Le mot de passe SMTP a été mis à jour. **Pensez à le changer depuis cPanel**
puisqu'il a transité en clair. Les nouvelles tables (codes e-mail, invitations,
préférences) se créent automatiquement au premier usage.
