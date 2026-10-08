# Notifications — mise en route (à faire une fois)

Avant cette version, les notifications n'apparaissaient **que si un onglet Alternis
était ouvert** : le navigateur interrogeait le serveur toutes les 90 secondes.
Dès que l'app était fermée (ou l'onglet en veille sur mobile), plus rien n'arrivait.
D'où l'impression que « ça fonctionne de manière aléatoire ».

Désormais Alternis utilise le **Web Push (VAPID)** : le serveur pousse la notification
vers Apple/Google/Mozilla, qui la remettent à l'appareil **même application fermée**.

Deux réglages sont nécessaires.

---

## 1. Activer la tâche planifiée (cron) — INDISPENSABLE

Sans cron, une notification programmée à 9 h ne partira que lorsque
quelqu'un ouvrira le site. Le cron résout définitivement ce point.

Dans cPanel → **Tâches Cron** → ajouter une tâche **toutes les 5 minutes** :

**Réglage courant :** `*/5 * * * *`

**Commande :**

    /usr/local/bin/php -q /home/UTILISATEUR/public_html/cron.php token=VOTRE_TOKEN

Adaptez le chemin (`/home/UTILISATEUR/public_html/`) à celui de votre installation.

Le jeton se trouve dans `config/config.php`, constante `CRON_TOKEN`.

**Variante par URL** si vous préférez :

    curl -s "https://alternis.example.com/cron.php?token=VOTRE_TOKEN" >/dev/null

Pour vérifier, ouvrez l'URL dans le navigateur : la page doit répondre
`OK — notifications creees: N | push envoyes: N | XXms`.
Sans le bon jeton, elle répond `Interdit.`

---

## 2. Autoriser les notifications sur chaque appareil

Dans **Paramètres → Apparence & notifications** :

1. Cliquer sur **Activer** (le navigateur demande l'autorisation).
2. Cliquer sur **Tester** : une vraie notification push doit arriver.

La ligne « État des notifications » indique en clair si l'appareil est abonné.

### Cas particulier : iPhone / iPad

Apple **n'autorise pas** le Web Push depuis Safari en navigation normale.
Il faut installer l'app :

1. Ouvrir l’adresse de votre instance Alternis dans **Safari**.
2. Bouton **Partager** → **Sur l'écran d'accueil**.
3. Ouvrir Alternis **depuis cette nouvelle icône**.
4. Aller dans Paramètres → **Activer** → **Tester**.

Tant que ce n'est pas fait, l'écran affiche un avertissement explicite.
Cette contrainte vient d'iOS, pas d'Alternis.

---

## Notes techniques

- Les clés VAPID sont dans `config/config.php` (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_PEM`).
  **Ne les régénérez pas** : tous les appareils déjà abonnés devraient se réabonner.
- Les abonnements sont stockés dans la table `push_subscriptions` (créée automatiquement).
- Un abonnement expiré (HTTP 404/410) est purgé automatiquement.
- La colonne `notifications.pushed_at` empêche qu'une notification parte deux fois.
- Si le cron n'est pas configuré, un filet de sécurité envoie quelques notifications
  en attente à chaque chargement de page — mais cela ne couvre pas « app fermée ».

---

## Dépannage

Ouvrez **Paramètres → Apparence & notifications**. La ligne « État des notifications »
croise désormais l'état du navigateur **et** celui du serveur.

| Message affiché | Signification | Action |
|---|---|---|
| ✅ Cet appareil est abonné (N appareils) | Tout est en place | Rien |
| ⚠️ Abonné ici, mais rien enregistré côté serveur | L'enregistrement en base a échoué | Cliquer « Activer » ; le message d'erreur SQL exact s'affiche |
| ⛔ Le serveur ne gère pas le Web Push : … | PHP trop ancien ou openssl incomplet | Passer en PHP ≥ 7.3 dans cPanel |
| ⚠️ La tâche cron n'est pas configurée | Notifications programmées bloquées | Voir section 1 |

Le bouton **Tester** ne dit plus « aucun appareil abonné » à tort : il affiche
le **code HTTP et le message exact** renvoyés par Apple / Google / Mozilla.

### Bug corrigé dans cette version

Une erreur de chiffrement côté serveur était interprétée comme « abonnement expiré »,
ce qui **supprimait l'abonnement en base** dès le premier envoi. L'appareil se croyait
donc abonné alors que le serveur n'avait plus rien — exactement le symptôme observé.
Désormais, seule une réponse **HTTP 404 ou 410** du service push purge un abonnement.

De plus, la colonne `endpoint` est passée de 500 à 1000 caractères : les points de
terminaison Apple pouvaient être tronqués silencieusement, provoquant un 404 puis,
là encore, la suppression de l'abonnement.
