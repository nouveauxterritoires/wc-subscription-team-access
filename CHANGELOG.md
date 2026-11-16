# Changelog

## 1.0.0 — 2026-11-16

Première version.

- Nombre maximal d'invités par produit d'abonnement (simple, variable et par variation), figé dans l'abonnement à l'achat.
- Espace client « Mon compte > Mes invités » : inviter, annuler, renvoyer, remplacer, supprimer.
- Page publique d'invitation : connexion ou création de compte (adresse imposée), acceptation.
- Adhésions WooCommerce Memberships « gérées » pour les invités, synchronisées avec le statut de l'abonnement principal (actif, suspendu, annulation programmée, annulé, expiré, réactivation, renouvellement, changement de formule).
- Gestion des droits multi-sources (plusieurs invitations, adhésions personnelles préservées, reprise par un achat de l'invité).
- Quotas atomiques (verrou MySQL), règle déterministe de dépassement, quota imposé par l'administrateur.
- Administration : abonnements partagés, détail, actions, journal ; réglages dans WooCommerce > Réglages > Accès équipe.
- Synchronisation de rattrapage quotidienne (Action Scheduler), commandes WP-CLI `wp team-access`.
- E-mails WooCommerce (invitation, acceptation), traductions françaises, export/effacement RGPD, anonymisation automatique.
