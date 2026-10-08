# Alternis 2.9.1 — Corrections personnalisation, PDF et notifications

## Corrections
- **Personnalisation** : le contenu d'autres sections (Collaboration)
  s'affichait par erreur sous l'onglet Personnalisation. Corrigé — chaque
  onglet n'affiche plus que son propre contenu.
- **PDF sans accents** : les accents étaient supprimés à l'export (é → e). La
  conversion de texte a été corrigée : les accents sont désormais préservés.
- **Cohérence PDF / tableau de bord** : le taux de réponse du PDF ne se
  calculait pas comme celui du tableau de bord (candidatures « à postuler »
  comptées différemment, et arrondi à l'entier au lieu d'une décimale). Les deux
  affichent maintenant exactement la même valeur.

## Améliorations
- **Aperçu en temps réel** dans la personnalisation : un aperçu du tableau de
  bord se met à jour instantanément quand vous cochez, décochez ou réordonnez
  les widgets.
- **Notifications (bulles) regroupées** : les messages en bas de l'écran ne
  s'empilent plus à l'infini. Trois au maximum sont visibles, les messages
  identiques sont regroupés avec un compteur, et l'ensemble reste compact.
