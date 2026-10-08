# Alternis 1.9 — Entreprises, relances, offres suggérées

Trois nouveautés. Aucune migration manuelle : les tables et colonnes se créent
au premier chargement, et les candidatures existantes sont rattachées automatiquement.

---

## 1. Fiches entreprises (1 entreprise → N candidatures)

Jusqu'ici, deux candidatures chez Orano donnaient deux lignes sans lien entre elles.
Désormais une **fiche entreprise** unique regroupe toutes les candidatures d'une même
société, avec un contact, des notes et un historique d'échanges consolidé.

- Menu **Entreprises** → liste des fiches, avec le nombre de candidatures et leur état.
- Clic sur une fiche → toutes les candidatures, le contact, les notes, tous les e-mails.
- Sur une candidature, un bandeau signale : *« Vous avez déjà postulé chez X »*.

### La migration, en détail

Le rapprochement se fait dans cet ordre :

1. **le domaine du site web** (`orano.group`), la donnée la plus fiable ;
2. **le nom normalisé** : accents, ponctuation et forme juridique retirés
   (`Orano SA` = `ORANO` = `Orano`) ;
3. **le nom compacté**, sans espaces (`Total Energies` = `TotalEnergies`).

**Ce que la migration ne fait pas, volontairement.** Elle ne fusionne jamais deux noms
distincts : `Bouygues` et `Bouygues Telecom` restent deux entreprises. Fusionner à tort
détruit de la donnée, et cela se répare mal. Si deux fiches devaient n'en faire qu'une,
l'action de fusion existe (`api/orgs.php?action=merge`) et reste sous votre contrôle.

La migration est **idempotente** : la relancer ne crée rien en double.

---

## 2. Relances assistées

Nouveau menu **Relances**, avec une pastille indiquant le nombre de candidatures
restées sans réponse au-delà du délai (10 jours par défaut, réglable de 3 à 60).

Pour chaque candidature, Alternis propose un brouillon adapté :
première relance, dernière relance, ou remerciement après entretien.

Vous pouvez alors :

- **Envoyer** le message directement depuis Alternis ;
- **Copier le texte** pour l'envoyer depuis votre propre messagerie ;
- **Enregistrer** une relance que vous avez déjà envoyée ailleurs ;
- **Reporter** de N jours, ou **ne plus proposer**.

Dans tous les cas, la relance est archivée dans la chronologie des échanges,
le statut passe à « Relancé » et la prochaine échéance est repoussée.

### Trois garde-fous

- **Aucun envoi automatique.** Un e-mail à un recruteur ne part que si vous avez relu et cliqué.
- **Pas de harcèlement.** Au-delà de deux relances, la candidature n'est plus proposée.
- **Réponse à la bonne personne.** Le message part de la boîte d'envoi d'Alternis,
  mais avec **votre adresse en `Reply-To`** : un recruteur qui répond vous écrit directement.

Les textes proposés sont volontairement **neutres en genre** : l'application ne connaît
pas votre genre et n'a pas à le deviner.

---

## 3. Offres suggérées

Nouveau menu **Offres suggérées**, alimenté côté serveur par :

- **France Travail** (API Offres d'emploi, contrats d'apprentissage et de professionnalisation) ;
- **La Bonne Alternance** (si vous renseignez des codes ROME).

Définissez vos critères — métier, ville, rayon — et les offres apparaissent.
Un bouton les ajoute directement à **Offres à faire**. Les offres déjà présentes
dans votre suivi sont signalées ; celles qui ne vous intéressent pas se masquent.

### Cette fonction dépend de services tiers

C'est la plus fragile des trois, et elle est écrite en conséquence :

- résultats **mis en cache 30 minutes**, pour ne pas marteler les API ;
- si une source tombe, **l'autre continue de répondre**, et l'erreur exacte est affichée ;
- jamais de page blanche : une panne se traduit par un bandeau explicite.

Les clés France Travail sont déjà dans `config/config.php`. Le jeton OAuth2 est
renouvelé automatiquement et mis en cache jusqu'à son expiration.

**Non testé de bout en bout :** l'environnement de développement n'a pas accès aux
serveurs de France Travail ni de La Bonne Alternance. La logique, le cache, le
dédoublonnage et la gestion d'erreur sont vérifiés ; la réponse réelle des API ne
peut l'être que depuis votre serveur. Si une source échoue, le bandeau vous donnera
le message exact.

---

## Mise à jour

1. Réenvoyez les fichiers.
2. Désinscrivez le service worker (F12 → Application → Service Workers → Unregister), puis Ctrl+Shift+R.
3. Ne remplacez pas `config/config.php` : il contient vos clés SMTP, VAPID et France Travail.

Aucun `upgrade.php` à lancer.

---

## Correctifs 1.9.1

### Fiche entreprise — retouches visuelles
- Ajout du **logo** de l'entreprise dans l'en-tête (même cascade que la fiche candidature : Clearbit → favicon → initiale).
- Le nom de l'entreprise n'apparaît plus **qu'une seule fois** dans le corps (il restait affiché à la fois dans la barre supérieure et dans le titre).
- Bouton retour refait : un vrai lien « ← Toutes les entreprises » au lieu du bouton carré.

### Relances envoyées depuis votre Gmail personnel
Les relances partent désormais de **votre adresse Gmail**, et non plus de la boîte
système : un recruteur qui répond vous joint directement. Les notifications et les
e-mails d'identifiants continuent d'utiliser le SMTP habituel — les deux flux sont séparés.

**À configurer vous-même** dans `config/config.php`, avec un **mot de passe d'application Google** :

```php
define('GMAIL_USER', 'prenom.nom@gmail.com');
define('GMAIL_APP_PASSWORD', 'xxxx xxxx xxxx xxxx');
define('GMAIL_FROM_NAME', 'Prénom Nom'); // facultatif
```

Pour créer ce mot de passe : activez la validation en deux étapes sur votre compte Google,
puis rendez-vous sur https://myaccount.google.com/apppasswords.

Tant que ces champs restent vides, le bouton **Envoyer** d'une relance est **masqué** ;
le bouton **Copier le texte** reste disponible, et un message détaillé explique
précisément ce qu'il manque. En cas d'échec d'envoi (mot de passe refusé, par exemple),
l'erreur exacte de Gmail est affichée.

---

## Correctifs 1.9.2 — Offres suggérées

### France Travail : le HTTP 400 est corrigé
Trois causes se cumulaient dans la requête :
- `sort=1` (tri par pertinence) était envoyé **même sans mots-clés**, ce que l'API refuse. Désormais `sort=1` seulement avec mots-clés, sinon `sort=0` (tri par date).
- Les **mots-clés** pouvaient contenir des virgules et caractères spéciaux (« développeur, cybersécurité & réseau ») qui cassaient la requête. Ils sont maintenant nettoyés.
- Le paramètre `distance` n'a de sens qu'avec une **commune INSEE valide** à 5 caractères ; il n'est plus envoyé seul.

Le motif exact d'un refus API est désormais **remonté et affiché** (au lieu d'un simple « HTTP 400 »).

### La Bonne Alternance : remise sur la bonne API
L'ancienne URL (`api/v1/jobs`) était obsolète. La nouvelle API `api/job/v1/search`
**agrège déjà France Travail, Météojobs, Enedis, Engie et de nombreux ATS**
(~325 000 offres en 2025) — c'est « plusieurs sources au même endroit », légalement
et gratuitement. Elle nécessite un **jeton gratuit** à créer sur
https://api.apprentissage.beta.gouv.fr/fr/compte/profil, à coller dans `config.php` :

```php
define('LBA_API_TOKEN', 'votre_jeton');
```

Sans jeton, cette source est simplement ignorée — France Travail continue de fonctionner.

### Nouveaux critères de recherche
Type de contrat (apprentissage / professionnalisation), expérience demandée,
ancienneté de publication (24 h à 1 mois), en plus du métier, de la ville, du rayon
et des codes ROME.

### Indeed, LinkedIn, Google for Jobs, HelloWork : pourquoi ils n'y sont pas
J'ai vérifié, et aucun n'est intégrable légalement et durablement :
- **Indeed** : API publique fermée en 2023 (renvoie 410) ; celle qui reste est réservée aux annonceurs payants.
- **LinkedIn** : aucune API publique de recherche d'offres ; réservé aux partenaires Talent Solutions (entreprises), et l'API « Jobs » sert à *publier*, pas à *chercher*.
- **Google for Jobs** : n'est pas une API — Google indexe les autres sites, sans point d'accès interrogeable.
- **HelloWork** : pas d'API publique.

La seule façon de les agréger serait le **scraping**, interdit par leurs conditions,
qui casse toutes les ~8 semaines et exposerait l'hébergement à un blocage.
Je ne l'ai pas embarqué : ce serait une panne programmée.
**La bonne nouvelle** : La Bonne Alternance regroupe déjà la plupart de ces offres
via ses partenaires, légalement.

### Script de diagnostic : `debug_offres.php`
À la racine. Il teste France Travail et La Bonne Alternance **depuis votre serveur**
(où les API sont joignables) et affiche la réponse exacte de chaque service.

Usage en ligne de commande :
```
php debug_offres.php > diagnostic.txt
```
ou par navigateur : `https://votre-site/debug_offres.php?token=VOTRE_CRON_TOKEN`.

Envoyez-moi la sortie, puis **supprimez ce fichier** du serveur.

### À faire côté France Travail (dashboard)
D'après votre capture, votre application n'a **aucune API ajoutée**. Il faut, sur
https://francetravail.io, ouvrir votre application « SCRAPER OFFRES » et cliquer
« Ajouter » sur au minimum **« Offres d'emploi v2 »** (et éventuellement « La Bonne Boîte »).
Sans cela, l'authentification ou la recherche est refusée même avec de bons identifiants.

---

## Correctifs 1.9.3 — Retour du diagnostic

Votre diagnostic a montré deux choses :

**La Bonne Alternance fonctionne** (HTTP 200, 18 offres + 150 recruteurs). La page
« Offres suggérées » affiche donc déjà des offres — et comme cette source agrège
France Travail, vous avez déjà les offres FT par ce biais.

**France Travail échoue à l'authentification** avec `invalid_client`. Ce message
signifie que le couple identifiant client / clé secrète est **rejeté** — ce n'est
pas un problème d'API non ajoutée. La cause la plus probable est une **clé secrète
erronée ou régénérée** (elle n'est affichée qu'à la création sur le dashboard).

Changements de cette version :
- Les identifiants France Travail sont désormais **nettoyés** (espaces / sauts de
  ligne invisibles) avant envoi — une cause fréquente d'`invalid_client`.
- Le **diagnostic** détecte maintenant les anomalies d'identifiants (espaces,
  longueur, caractères invisibles) et donne, pour `invalid_client`, la marche à
  suivre exacte.
- Le bandeau d'erreur des offres est **adouci** : si des offres remontent malgré
  tout via une autre source, on affiche une note d'information discrète plutôt
  qu'un avertissement.

### Pour réparer France Travail
1. Connectez-vous sur https://francetravail.io → votre application « SCRAPER OFFRES ».
2. **Régénérez la clé secrète** (ou vérifiez que celle de config.php est bien la dernière).
3. Recopiez identifiant client ET clé secrète dans config.php, sans espace.
4. Vérifiez que l'API « Offres d'emploi v2 » est bien **souscrite/ajoutée**.
5. Relancez `php debug_offres.php` : l'étape 2 doit passer à `[OK] Jeton obtenu`.

En attendant, La Bonne Alternance vous fournit déjà des offres.

---

## Correctifs 1.9.4 — France Travail : le HTTP 400 « typeContrat » corrigé

Nouvelle erreur remontée par le diagnostic : `typeContrat incorrect`. En cause,
j'utilisais `typeContrat=E2,FS`, or ces codes ne sont pas des valeurs de ce
paramètre (typeContrat attend CDD, CDI…). L'alternance se filtre par
**`natureContrat`** (E2 = apprentissage, FS = professionnalisation).

Corrections :
- La requête utilise désormais `natureContrat` au lieu de `typeContrat`.
- **Repli automatique** : si un code de contrat était malgré tout refusé, l'app
  relance la requête **sans filtre de contrat** puis ne conserve que l'alternance
  en se basant sur le libellé (apprentissage / professionnalisation). La recherche
  ne peut donc plus être cassée par un code de contrat.
- Le diagnostic teste maintenant **trois variantes** (natureContrat=E2,FS ; =E2 ;
  sans filtre) et indique laquelle passe.

**Bon à savoir** : l'authentification France Travail fonctionne désormais chez vous
(le `invalid_client` a disparu avec vos nouveaux identifiants). La Bonne Alternance
fonctionne aussi (jeton valide jusqu'en juillet 2027). Après cette mise à jour, les
deux sources devraient remonter des offres.

**Sécurité** : votre jeton La Bonne Alternance est une clé personnelle. Il est bien
placé dans config.php ; si vous l'avez transmis ailleurs, vous pouvez le régénérer
sur votre espace développeur pour invalider l'ancien.

---

## Correctifs 1.9.5 — Commune + La Bonne Alternance enfin visible

Deux problèmes réglés d'un coup.

### « commune incorrecte » (HTTP 400)
France Travail veut un code **INSEE** (77288 pour Melun), pas un code **postal**
(77000). Or ces deux formats se ressemblent et passaient tous deux la validation.
Désormais :
- le code est **résolu en INSEE** via geo.api.gouv (un code postal est converti en
  code commune, la plus peuplée s'il y en a plusieurs) ;
- si la résolution échoue, la commune est **retirée** et la recherche s'élargit,
  au lieu de renvoyer une erreur.

### La Bonne Alternance ne s'affichait pas
Elle n'était interrogée que si vous saisissiez des **codes ROME** — que personne ne
connaît par cœur. Résultat : le champ restait vide, LBA n'était jamais appelée, et
vous n'aviez que des offres France Travail.

Désormais, les codes ROME sont **déduits automatiquement de votre métier**
(ex. « technicien informatique » → M1810, M1801, M1802). La Bonne Alternance est
donc interrogée systématiquement, et ses offres (qui agrègent aussi France Travail,
Météojobs, Enedis, Engie…) apparaissent enfin. Vous pouvez toujours saisir des codes
ROME à la main pour affiner.

La déduction couvre les métiers courants (informatique, commerce, gestion, RH,
logistique, BTP, etc.). Pour un métier non couvert, la saisie manuelle reste possible.
