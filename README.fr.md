# WC Subscription Team Access

> *English version: [README.md](README.md).*

Extension WordPress qui permet au titulaire d'un abonnement WooCommerce Subscriptions de partager les accès premium accordés par WooCommerce Memberships avec des invités, chacun disposant de son propre compte WordPress. Les invités ne sont pas facturés.

- Slug et text domain : `wc-subscription-team-access`
- Prérequis : WordPress 6.4+, PHP 8.1+, WooCommerce 8+, WooCommerce Subscriptions, WooCommerce Memberships
- Testé avec : WooCommerce 11.3, Subscriptions 9.3, Memberships 1.26.10, PHP 8.2 et 8.4
- Langues : anglais (langue source), français (`languages/`)
- Compatible HPOS, aucune dépendance externe en production

La documentation est aussi disponible dans l'administration WordPress, dans l'onglet **Aide** (en haut à droite) de WooCommerce > Accès équipe, WooCommerce > Réglages > Accès équipe et des écrans d'édition des produits et des abonnements.

## Sommaire

1. [Installation](#installation)
2. [Configuration](#configuration)
3. [Utilisation](#utilisation)
4. [Modèle des droits (Memberships)](#modèle-des-droits-memberships)
5. [Synchronisation avec les abonnements](#synchronisation-avec-les-abonnements)
6. [Quotas](#quotas)
7. [Invitations et sécurité](#invitations-et-sécurité)
8. [Administration](#administration)
9. [WP-CLI](#wp-cli)
10. [Tests](#tests)
11. [Crochets pour les développeurs](#crochets-pour-les-développeurs)
12. [Limites connues](#limites-connues)

## Installation

1. Copier le dossier `wc-subscription-team-access` dans `wp-content/plugins/` (ou téléverser le zip produit par `bin/build-zip.sh`, sans les tests ni les fichiers de développement).
2. Activer l'extension. Les tables `{prefix}wcsta_seats`, `{prefix}wcsta_grants` et `{prefix}wcsta_log` sont créées, et les règles de réécriture sont régénérées au chargement suivant.
3. Si WooCommerce, Subscriptions ou Memberships manque, l'extension reste inactive et affiche un avertissement dans l'administration.

Le dossier `tests/` n'est pas nécessaire en production. Ne le déployez pas dans un dossier accessible par le web : la suite d'intégration crée et supprime des comptes.

## Configuration

### Sur les produits

Dans l'onglet Général d'un produit d'abonnement, renseigner **Nombre maximal d'invités** :

| Valeur | Effet |
|---|---|
| vide ou `0` | Fonctionnalité désactivée |
| `5` | L'acheteur peut inviter 5 personnes en plus de lui-même (6 accès au total) |

Pour un **abonnement variable**, le champ du produit sert de valeur par défaut, et chaque variation a son propre champ. Une variation laissée vide hérite du produit ; `0` désactive explicitement la fonctionnalité pour cette variation.

Le produit doit aussi accorder un plan WooCommerce Memberships (plan « à l'achat » contenant ce produit). C'est ce plan, et lui seul, qui est partagé avec les invités.

### Réglages

**WooCommerce > Réglages > Accès équipe** :

| Réglage | Défaut |
|---|---|
| Validité des invitations (jours) | 7 |
| E-mails d'invitation par titulaire et par heure | 20 |
| Délai minimal entre deux envois d'une invitation (minutes) | 5 |
| Nombre maximal de renvois par invitation | 5 |
| Prévenir le titulaire lorsqu'un invité accepte | oui |
| Quand le quota diminue | suspendre les invités les plus récents |
| Ne pas envoyer les e-mails Memberships aux invités | oui |
| Anonymiser l'e-mail des invitations terminées après (jours) | 90 |
| Slug de la page d'invitation | `invitation-equipe` |
| Slug de l'onglet Mon compte | `mes-invites` |

Les e-mails « Invitation accès équipe » et « Invitation accès équipe acceptée » se configurent dans WooCommerce > Réglages > E-mails. Leurs gabarits peuvent être surchargés dans le thème (`woocommerce/emails/wcsta-team-invitation.php`, etc.).

## Utilisation

### Titulaire : Mon compte > Mes invités

Pour chaque abonnement partageable : nom, statut, nombre maximal d'invités, places utilisées et disponibles, liste des invités avec leur statut, et actions :

- **Inviter un utilisateur** (par e-mail) ;
- **Renvoyer** une invitation (un nouveau lien est généré, l'ancien cesse de fonctionner) ;
- **Annuler** une invitation en attente ;
- **Remplacer** un invité ou une invitation (la place est réattribuée) ;
- **Supprimer** un invité (il perd immédiatement l'accès partagé).

Statuts affichés : *Invitation en attente*, *Invitation acceptée*, *Accès suspendu* (avec la raison), *Accès révoqué*. L'historique liste aussi les invitations annulées et expirées.

### Invité : page d'invitation

Le lien reçu par e-mail ouvre `/invitation-equipe/`. Le jeton est aussitôt retiré de l'URL et conservé dans un cookie `HttpOnly`.

- **Sans compte** : l'invité choisit un mot de passe. L'adresse e-mail est imposée par l'invitation. Le compte est créé, connecté, et l'invitation acceptée. Cette création fonctionne même si l'inscription depuis Mon compte est désactivée sur le site : l'invitation vaut autorisation.
- **Avec un compte** : il se connecte (formulaire WooCommerce). L'adresse du compte doit être celle de l'invitation. Il clique ensuite sur « Accepter l'invitation ».
- **Connecté avec un autre compte** : refus, avec une invitation à se déconnecter.

Aucun mot de passe n'est jamais envoyé par e-mail. Un compte existant ne peut jamais recevoir de mot de passe via une invitation : un lien transféré ne permet donc pas de prendre la main sur un compte.

Si l'extension **Restricted Site Access** est active, la page d'invitation reste accessible aux personnes déconnectées qui présentent un jeton. Rien d'autre n'est ouvert.

## Modèle des droits (Memberships)

L'extension réutilise exclusivement le moteur de WooCommerce Memberships : elle ne restreint aucun contenu elle-même.

### Contrainte de Memberships

Memberships ne connaît qu'**une adhésion « logique » par utilisateur et par plan** : `wc_memberships_get_user_membership()` et `wc_memberships_is_user_active_member()` ne lisent que l'adhésion la plus récente. Un achat ultérieur réutilise (renouvelle) cette même adhésion. Plusieurs sources de droits ne peuvent donc pas être isolées en créant plusieurs adhésions sur le même plan.

### Architecture retenue

- Un **registre des sources** (`wcsta_grants`) contient une ligne par place acceptée et par plan partagé, avec l'état conféré (actif avec date de fin éventuelle, ou suspendu).
- L'extension crée **au plus une adhésion « gérée »** par invité et par plan, marquée par la méta `_wcsta_managed`, sans commande ni abonnement liés. Son état est l'agrégat de toutes ses sources : actif l'emporte sur suspendu ; entre deux sources actives, la fin la plus tardive l'emporte, et une source sans fin rend l'accès illimité.
- Une adhésion indépendante (achetée, offerte par un administrateur, importée) n'est **jamais** créée, modifiée ni supprimée par l'extension. Si l'invité en a une active sur le plan, aucune adhésion gérée n'existe. Si elle s'interrompt (abonnement personnel suspendu, par exemple), l'adhésion gérée prend le relais. Quand elle redevient active, l'adhésion gérée est retirée.
- Si un invité **achète lui-même** un abonnement donnant le plan, Memberships réutilise nativement l'adhésion gérée et la lie à son achat. L'extension la « libère » : elle devient personnelle et n'est plus jamais touchée.
- La révocation d'une invitation retire uniquement la source correspondante. L'adhésion gérée n'est supprimée que s'il ne reste aucune source.

### Compromis documentés

- **Pas de lien natif `_subscription_id`** entre l'adhésion de l'invité et l'abonnement du titulaire. Ce lien ne permettrait qu'une seule source. Il afficherait aussi dans l'espace membre de l'invité l'abonnement du titulaire (prix, actions). Le rattachement passe par le registre, et la méta `_wcsta_sources` mémorise les abonnements sources pour l'affichage.
- **Suppression plutôt qu'annulation** d'une adhésion gérée devenue inutile. Une adhésion annulée plus récente masquerait, dans `wc_memberships_is_user_active_member()`, une adhésion personnelle active plus ancienne. Avant la suppression, l'adhésion est annulée via `cancel_membership()` pour que Memberships recalcule le rôle du membre (option « attribuer des rôles aux membres »). L'historique reste consultable dans le journal de l'extension.
- **Ancienneté** : l'adhésion gérée commence à la date d'acceptation. Les contenus programmés (« drip ») se calculent donc depuis cette date, et non depuis l'achat du titulaire.
- **Rôles** : si Memberships attribue un rôle aux membres actifs (par exemple `membre` pour les membres actifs et `subscriber` pour les inactifs), les invités reçoivent et perdent ce rôle exactement comme les autres membres.
- Les **e-mails natifs de Memberships** (activation, fin prochaine, fin, rappel de renouvellement) sont désactivés pour les adhésions gérées (réglage). Les actions « Annuler » et « Renouveler » sont retirées de l'espace membre de l'invité.

## Synchronisation avec les abonnements

L'accès des invités suit le statut de l'abonnement du titulaire, lu via les API publiques de Subscriptions :

| Statut de l'abonnement | Accès des invités |
|---|---|
| `active` | Actif |
| `on-hold` (y compris renouvellement échoué) | Suspendu (adhésion en pause) |
| `pending-cancel` | Actif jusqu'à la fin de la période payée (date de fin reportée sur l'adhésion), révoqué dès qu'elle est dépassée |
| `cancelled` | Actif tant que la date de fin réelle est future, révoqué ensuite |
| `expired`, `trash`, supprimé | Révoqué |
| Réactivation | Restauré pour les invités toujours dans le quota |
| Changement de formule | Nouveau quota et réévaluation des plans |

Chaque événement (`woocommerce_subscription_status_updated`, changement de date de fin, `woocommerce_subscriptions_switch_completed`, mise à la corbeille ou suppression, changement de statut ou suppression d'une adhésion indépendante) déclenche une **synchronisation complète et idempotente** de l'abonnement. Elle recalcule l'état cible à partir de l'état courant : rejouer un événement ne crée ni doublon ni droit supplémentaire.

Une **synchronisation de rattrapage** quotidienne (Action Scheduler, groupe `wc-subscription-team-access`) traite tous les abonnements partagés par lots de 100. Elle expire les invitations périmées, anonymise les anciennes invitations, purge le journal et corrige les adhésions gérées orphelines ou modifiées à la main. L'extension fait autorité sur les adhésions gérées : passez par ses écrans pour les modifier.

## Quotas

- Le quota est **figé dans l'abonnement à l'achat** (méta `_wcsta_quota`). Modifier ensuite le produit n'a aucun effet rétroactif.
- Calcul : somme, pour chaque ligne, du quota du produit ou de la variation multiplié par la quantité.
- Il est **recalculé uniquement lors d'un changement de formule** (switch). Un administrateur peut aussi imposer un quota par abonnement. Chaque changement est historisé.
- Le titulaire ne consomme pas de place. Les invitations en attente et les invités acceptés en consomment une. Annuler, supprimer ou laisser expirer libère la place.
- **Atomicité** : inviter, remplacer, renvoyer une invitation expirée et accepter s'exécutent sous un verrou MySQL (`GET_LOCK`) propre à l'abonnement. Une clé unique empêche en plus deux invitations vivantes pour la même adresse. Testé avec 8 invitations simultanées pour 1 place : 1 acceptée, 7 refusées.

### Diminution du quota (règle déterministe)

Les places sont classées ainsi :

1. les invités acceptés, du plus ancien au plus récent (date d'acceptation) ;
2. puis les invitations en attente, de la plus ancienne à la plus récente ;
3. à date égale, l'identifiant le plus petit l'emporte.

Les `quota` premières places restent actives. Les suivantes passent en **accès suspendu (dépassement)**, sans suppression, et leurs invitations en attente ne peuvent plus être acceptées. Les nouvelles invitations sont bloquées tant que le quota est dépassé. Dès qu'une place se libère ou que le quota remonte, les invités suspendus retrouvent automatiquement leur accès.

Le réglage « Conserver tous les invités » ne suspend personne : il signale seulement le dépassement et bloque les nouvelles invitations.

## Invitations et sécurité

- **Jetons** : 32 octets issus de `random_bytes()`, seul leur SHA-256 est stocké. Ils sont à usage unique (effacés à l'acceptation, remplacés à chaque renvoi) et expirent après 7 jours par défaut.
- **Vérifications avant envoi** : propriété de l'abonnement (ou capacité `manage_woocommerce`), quota positif, abonnement actif, adresse valide différente de celle du titulaire, adresse non déjà invitée ni membre, place disponible.
- **Limitation des envois** : e-mails par titulaire et par heure, délai minimal et nombre maximal de renvois, tentatives de jeton invalide et créations de compte limitées par adresse IP (hachée).
- **Formulaires** : nonces partout, validation et nettoyage des entrées, échappement des sorties, requêtes SQL préparées.
- **Confidentialité** : un abonnement inexistant et un abonnement d'autrui renvoient le même message. L'adresse invitée est masquée sur la page d'invitation, sauf pour pré-remplir le formulaire de création de compte du porteur du lien. La page n'est pas indexable (`noindex`, `Referrer-Policy: no-referrer`).
- **RGPD** : exportateur et effaceur WordPress (*Outils > Exporter / Effacer les données personnelles*), texte suggéré pour la politique de confidentialité, anonymisation automatique des adresses des invitations terminées. Seules l'adresse invitée, le compte invité et les dates sont conservés.

## Administration

**WooCommerce > Accès équipe** (capacité `manage_woocommerce`) :

- liste des abonnements partagés : titulaire, statut, quota (imposé ou non), places utilisées et disponibles, dépassement ;
- détail d'un abonnement : droits effectifs, plans partagés, invités et adhésions Memberships associées (liens), quota imposé, invitation pour le compte du titulaire, révocation, renvoi, resynchronisation, événements récents ;
- journal : erreurs de synchronisation et événements, avec un bandeau en cas d'erreur récente ;
- resynchronisation complète en tâche de fond ;
- onglet **Aide** avec la documentation.

Un encadré **Accès équipe** apparaît aussi sur l'écran d'édition de chaque abonnement.

## WP-CLI

```bash
wp team-access status <subscription_id>      # quota, droits, plans, places
wp team-access invite <subscription_id> <email> [--as=<user_id>]
wp team-access revoke <seat_id> [--as=<user_id>]
wp team-access resend <seat_id> [--as=<user_id>]
wp team-access sync [<subscription_id>]      # tous les abonnements partagés si aucun identifiant
wp team-access schedule                      # resynchronisation complète en tâche de fond
wp team-access maintenance                   # expirations, anonymisation, purge du journal
wp team-access snapshot <subscription_id>    # recalcule le quota depuis le catalogue (à utiliser avec précaution)
```

Exemple de recette manuelle :

```bash
wp team-access invite 1234 jeanne@example.org --as=56
wp team-access status 1234
wp eval 'var_dump( wc_memberships_is_user_active_member( get_user_by( "email", "jeanne@example.org" )->ID, <plan_id> ) );'
wp team-access revoke 78
```

## Tests

### Tests unitaires (logique métier, sans WordPress)

```bash
composer install
composer test
```

46 tests couvrent la traduction des statuts d'abonnement en droits, l'agrégation multi-sources, la règle de dépassement, le calcul du quota, les jetons et les adresses.

### Suite d'intégration (WordPress, WooCommerce, Subscriptions et Memberships réels)

Elle s'exécute sur un site de recette où l'extension est active. Elle crée un plan, des produits, des comptes et des abonnements de test (préfixe `wcsta-it-`), capture les e-mails (aucun n'est envoyé), puis nettoie tout.

```bash
# depuis la racine WordPress du site de recette
WP="wp" bash /chemin/vers/wc-subscription-team-access/tests/integration/run.sh
# KEEP=1 pour conserver les données de test
```

Scénarios couverts : abonnement sans place, abonnement à 5 places et quota figé, invitation d'un nouvel utilisateur et d'un utilisateur existant, adresse différente, invitation expirée puis relancée, invitation déjà utilisée ou remplacée par un renvoi, critères 1 à 6 (5 invités puis sixième refusée), place réservée par une invitation en attente, révocation (avec retrait du rôle), remplacement, suspension et réactivation, renouvellement échoué puis réussi, annulation avec période payée restante, expiration, changement de formule (baisse puis hausse du quota, variation héritant du parent), quota imposé, adhésion personnelle préexistante (préservée, relais, non-doublon), invité achetant ensuite son propre abonnement, invité de plusieurs abonnements, tentative sur l'abonnement d'autrui, exécution répétée d'un même événement, rattrapage après adhésion supprimée ou modifiée à la main, limitation des envois, validation des adresses, espace Mon compte, RGPD, e-mails Memberships désactivés, et **invitations concurrentes** (8 processus pour 1 place).

Dernier résultat sur le site de recette (16/11/2026, WooCommerce 11.3, Subscriptions 9.3, Memberships 1.26.10) : 28 scénarios sur 28 et test de concurrence OK.

### Traductions

Les chaînes sources sont en anglais. Le catalogue français se génère avec :

```bash
wp i18n make-pot . languages/wc-subscription-team-access.pot --exclude=vendor,tests,node_modules,build,bin
python3 bin/build-fr.py   # échoue si une chaîne n'est pas traduite
msgfmt -o languages/wc-subscription-team-access-fr_FR.mo languages/wc-subscription-team-access-fr_FR.po
```

## Crochets pour les développeurs

| Crochet | Type | Usage |
|---|---|---|
| `wcsta_computed_quota` | filtre | Modifier le quota calculé d'un abonnement (`$quota, $subscription`) |
| `wcsta_subscription_plan_ids` | filtre | Plans partagés avec les invités (`$plan_ids, $subscription`) |
| `wcsta_subscription_effective_access` | filtre | Droit effectif conféré par un abonnement (`AccessState $state, $subscription`) |
| `wcsta_after_accept_redirect` | filtre | Page de destination après acceptation (`$url, Seat $seat`) |
| `wcsta_client_ip` | filtre | Adresse IP du client pour la limitation de débit, à fournir derrière un proxy inverse ou un CDN (`$ip`) |

Gabarits surchargeables dans le thème : `wc-subscription-team-access/my-account/team-access.php`, `wc-subscription-team-access/invitation/accept.php`, et les e-mails dans `woocommerce/emails/`.

## Limites connues

- Changement de formule : les places restent sur l'abonnement modifié. Si Subscriptions crée un *nouvel* abonnement lors d'un changement (abonnement à plusieurs lignes avec cycles différents), les invités de l'ancien abonnement ne sont pas transférés automatiquement. Un administrateur peut les réinviter.
- L'extension fait autorité sur les adhésions gérées : une modification manuelle dans l'écran Memberships est corrigée à la synchronisation suivante.
- La page d'invitation utilise l'en-tête et le pied de page du thème (thèmes classiques et thèmes blocs).
