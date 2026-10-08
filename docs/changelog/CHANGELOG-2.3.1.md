# Alternis 2.3.1 — Extension : détection automatique + corrections

## Enregistrement automatique des candidatures
Sur les plateformes reconnues (HelloWork, LinkedIn, Indeed, WTTJ…), l'extension
détecte désormais l'envoi d'une candidature — via le clic sur « Postuler » suivi
d'un message de confirmation, ou l'arrivée sur une page de confirmation — et
enregistre la candidature dans Alternis automatiquement, avec une notification
à l'écran (« Candidature enregistrée » / erreur).

Un doublon est évité par offre. La détection est désactivable via la case
« Enregistrer automatiquement quand je postule » dans le popup.

## Corrections d'analyse des offres (ex. HelloWork)
- Le **nom de l'entreprise** ne capture plus un lien de menu (« Trouver mon
  entreprise ») : il lit le vrai lien de l'entreprise de l'offre.
- Le **poste** est correctement extrait et nettoyé (suppression de « H/F », des
  préfixes « Offre d'alternance », etc.).
- Le **champ Site web** ne reçoit plus l'URL de l'annonce : celle-ci est
  conservée séparément comme lien de l'offre ; le site web reste le domaine de
  l'entreprise (vide sur un job board, à compléter éventuellement).
- Analyse générique améliorée : reconnaît le format « … - Recrutement par
  <Entreprise> | <Site> » et ignore le nom du site quand il apparaît à la place
  de l'entreprise.
