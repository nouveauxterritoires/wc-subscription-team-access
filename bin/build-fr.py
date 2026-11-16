#!/usr/bin/env python3
"""Génère languages/wc-subscription-team-access-fr_FR.po depuis le .pot et le dictionnaire ci-dessous.

Usage : python3 bin/build-fr.py && msgfmt -o languages/wc-subscription-team-access-fr_FR.mo languages/wc-subscription-team-access-fr_FR.po
Toute chaîne du .pot absente du dictionnaire fait échouer le script.
"""
import re
from datetime import datetime, timezone
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
POT = ROOT / 'languages' / 'wc-subscription-team-access.pot'
PO = ROOT / 'languages' / 'wc-subscription-team-access-fr_FR.po'

FR = {
    'WC Subscription Team Access': 'WC Subscription Team Access',
    'https://github.com/nouveauxterritoires/wc-subscription-team-access': 'https://github.com/nouveauxterritoires/wc-subscription-team-access',
    'Lets a WooCommerce Subscriptions holder share their WooCommerce Memberships access with guests who use their own accounts.':
        "Permet au titulaire d’un abonnement WooCommerce Subscriptions de partager ses accès WooCommerce Memberships avec des invités disposant de leur propre compte.",
    'Nouveaux Territoires': 'Nouveaux Territoires',
    'https://nouveauxterritoires.fr': 'https://nouveauxterritoires.fr',
    'My guests': 'Mes invités',
    'Your session has expired. Please try again.': 'Votre session a expiré. Veuillez réessayer.',
    'An invitation has been sent to %s.': 'Une invitation a été envoyée à %s.',
    'The guest has been replaced. An invitation has been sent to %s.': 'L’invité a été remplacé. Une invitation a été envoyée à %s.',
    'The invitation has been sent again to %s.': 'L’invitation a été renvoyée à %s.',
    'The invitation sent to %s has been cancelled.': 'L’invitation envoyée à %s a été annulée.',
    'The access of %s has been revoked. The seat is available again.': 'L’accès de %s a été révoqué. La place est de nouveau disponible.',
    'Team access': 'Accès équipe',
    'You are not allowed to access this page.': 'Vous n’avez pas l’autorisation d’accéder à cette page.',
    'Shared subscriptions': 'Abonnements partagés',
    'Log': 'Journal',
    'Settings': 'Réglages',
    'The seat has been ended.': 'La place a été libérée.',
    'The invitation has been sent again.': 'L’invitation a été renvoyée.',
    'The invitation has been sent.': 'L’invitation a été envoyée.',
    'The subscription has been synchronized.': 'L’abonnement a été resynchronisé.',
    'A full synchronization has been scheduled. It runs in the background.': 'Une resynchronisation complète a été planifiée. Elle s’exécute en arrière-plan.',
    'Unknown action.': 'Action inconnue.',
    'Search': 'Rechercher',
    'Email or subscription number': 'E-mail ou numéro d’abonnement',
    'Subscription': 'Abonnement',
    'Holder': 'Titulaire',
    'Status': 'Statut',
    'Quota': 'Quota',
    'Used': 'Utilisées',
    'Available': 'Disponibles',
    'Pending / accepted / suspended': 'En attente / acceptées / suspendues',
    'Actions': 'Actions',
    'No subscription has guests yet.': 'Aucun abonnement n’a encore d’invités.',
    'deleted': 'supprimé',
    'forced': 'imposé',
    'Over quota: %d': 'Dépassement : %d',
    'Details': 'Détails',
    'Resynchronize': 'Resynchroniser',
    'A catch-up synchronization runs every day. You can start one now.': 'Une synchronisation de rattrapage s’exécute chaque jour. Vous pouvez en lancer une maintenant.',
    'Resynchronize all shared subscriptions': 'Resynchroniser tous les abonnements partagés',
    'All shared subscriptions': 'Tous les abonnements partagés',
    'This subscription no longer exists. Its seats have been ended.': 'Cet abonnement n’existe plus. Ses places ont été libérées.',
    'Subscription status': 'Statut de l’abonnement',
    'Guest access': 'Accès des invités',
    'Shared membership plans': 'Plans d’adhésion partagés',
    'Seats': 'Places',
    '%1$d used of %2$d (%3$d available)': '%1$d utilisée(s) sur %2$d (%3$d disponible(s))',
    'Resynchronize this subscription': 'Resynchroniser cet abonnement',
    'Guests': 'Invités',
    'Email': 'E-mail',
    'Account': 'Compte',
    'Invited on': 'Invité le',
    'Accepted on': 'Accepté le',
    'Memberships': 'Adhésions',
    'No guest.': 'Aucun invité.',
    'Expires on %s': 'Expire le %s',
    'Cancel invitation': 'Annuler l’invitation',
    'Revoke access': 'Révoquer l’accès',
    'Confirm?': 'Confirmer ?',
    'Resend': 'Renvoyer',
    'Invite on behalf of the holder': 'Inviter pour le compte du titulaire',
    'Email address': 'Adresse e-mail',
    'Send invitation': 'Envoyer l’invitation',
    'Recent events': 'Événements récents',
    'Forced quota': 'Quota imposé',
    'Save': 'Enregistrer',
    'Leave empty to use the quota recorded at purchase or at the last plan change (%s).': 'Laisser vide pour utiliser le quota enregistré à l’achat ou au dernier changement de formule (%s).',
    'Errors and warnings': 'Erreurs et avertissements',
    'All events': 'Tous les événements',
    'Date': 'Date',
    'Level': 'Niveau',
    'Event': 'Événement',
    'User': 'Utilisateur',
    'Message': 'Message',
    'Nothing to show.': 'Rien à afficher.',
    'The quota has been updated.': 'Le quota a été mis à jour.',
    "%s (covered by the guest's own membership)": '%s (couvert par l’adhésion personnelle de l’invité)',
    'None: this subscription does not grant any membership plan.': 'Aucun : cet abonnement n’accorde aucun plan d’adhésion.',
    'Active': 'Actif',
    'Active until %s': 'Actif jusqu’au %s',
    'Suspended': 'Suspendu',
    'None': 'Aucun',
    ('%d synchronization error in the last 7 days.', '%d synchronization errors in the last 7 days.'):
        ('%d erreur de synchronisation ces 7 derniers jours.', '%d erreurs de synchronisation ces 7 derniers jours.'),
    'See the log': 'Voir le journal',
    'Invitations': 'Invitations',
    'The maximum number of guests is set on each subscription product (General tab, or per variation). Shared subscriptions and the log are available in %s.':
        'Le nombre maximal d’invités se règle sur chaque produit d’abonnement (onglet Général, ou par variation). Les abonnements partagés et le journal sont consultables dans %s.',
    'WooCommerce > Team access': 'WooCommerce > Accès équipe',
    'Invitation validity (days)': 'Validité des invitations (jours)',
    'Invitation emails per holder per hour': 'E-mails d’invitation par titulaire et par heure',
    'Minimum delay between two sends of an invitation (minutes)': 'Délai minimal entre deux envois d’une invitation (minutes)',
    'Maximum number of resends per invitation': 'Nombre maximal de renvois par invitation',
    'Holder notification': 'Notification du titulaire',
    'Notify the holder when a guest accepts an invitation': 'Prévenir le titulaire lorsqu’un invité accepte une invitation',
    'When the quota decreases': 'Quand le quota diminue',
    'Guests are ranked by acceptance date (oldest first), then pending invitations by creation date. Nobody is deleted: suspended guests regain access automatically when a seat becomes available.':
        'Les invités sont classés par date d’acceptation (les plus anciens d’abord), puis les invitations en attente par date de création. Personne n’est supprimé : les invités suspendus retrouvent leur accès automatiquement dès qu’une place se libère.',
    'Suspend the most recent guests beyond the quota (default)': 'Suspendre les invités les plus récents au-delà du quota (par défaut)',
    'Keep all guests, only block new invitations': 'Conserver tous les invités et bloquer seulement les nouvelles invitations',
    'Memberships and privacy': 'Adhésions et confidentialité',
    'Memberships emails': 'E-mails Memberships',
    'Do not send the WooCommerce Memberships emails (activated, ending soon, ended, renewal reminder) for guest memberships':
        'Ne pas envoyer les e-mails WooCommerce Memberships (activation, fin prochaine, fin, rappel de renouvellement) pour les adhésions des invités',
    'Anonymize the email of ended invitations after (days, 0 = never)': 'Anonymiser l’e-mail des invitations terminées après (jours, 0 = jamais)',
    'Addresses': 'Adresses',
    'Invitation page slug': 'Slug de la page d’invitation',
    'My account endpoint slug': 'Slug de l’onglet Mon compte',
    '%1$d guest seat(s) used of %2$d.': '%1$d place(s) d’invité utilisée(s) sur %2$d.',
    '(forced quota)': '(quota imposé)',
    'Manage guests': 'Gérer les invités',
    'Team access invitation accepted': 'Invitation accès équipe acceptée',
    'Sent to the subscription holder when a guest accepts an invitation.': 'Envoyé au titulaire de l’abonnement lorsqu’un invité accepte une invitation.',
    '{guest_email} joined your subscription on {site_title}': '{guest_email} a rejoint votre abonnement sur {site_title}',
    'A guest joined your subscription': 'Un invité a rejoint votre abonnement',
    'Team access invitation': 'Invitation accès équipe',
    'Sent to a person invited by a subscription holder to share their premium access.': 'Envoyé à une personne invitée par le titulaire d’un abonnement à partager ses accès premium.',
    '{inviter_name} invites you to share their access on {site_title}': '{inviter_name} vous invite à partager son accès sur {site_title}',
    'You are invited': 'Vous êtes invité',
    'If you were not expecting this invitation, you can ignore this email: nothing will happen without your action.':
        'Si vous n’attendiez pas cette invitation, vous pouvez ignorer cet e-mail : rien ne se passera sans action de votre part.',
    'Invitation': 'Invitation',
    'Your password must contain at least %d characters.': 'Votre mot de passe doit contenir au moins %d caractères.',
    'The two passwords do not match.': 'Les deux mots de passe ne correspondent pas.',
    'An account may already exist for this email address: please log in to accept the invitation.':
        'Un compte existe peut-être déjà pour cette adresse e-mail : connectez-vous pour accepter l’invitation.',
    'Welcome! Your invitation is accepted: you now have access to the premium content.':
        'Bienvenue ! Votre invitation est acceptée : vous avez désormais accès aux contenus premium.',
    'Invitation pending': 'Invitation en attente',
    'Invitation accepted': 'Invitation acceptée',
    'Access suspended': 'Accès suspendu',
    'Access revoked': 'Accès révoqué',
    'Invitation cancelled': 'Invitation annulée',
    'Invitation expired': 'Invitation expirée',
    'The number of guests exceeds the subscription quota.': 'Le nombre d’invités dépasse le quota de l’abonnement.',
    'The subscription is on hold.': 'L’abonnement est suspendu.',
    'The subscription is no longer active.': 'L’abonnement n’est plus actif.',
    'The subscription does not grant any membership plan.': 'L’abonnement n’accorde aucun plan d’adhésion.',
    'Guest membership created by WC Subscription Team Access: access is shared by a subscription holder.':
        'Adhésion invitée créée par WC Subscription Team Access : l’accès est partagé par le titulaire d’un abonnement.',
    'Guest access activated: the sharing subscription grants access.': 'Accès invité activé : l’abonnement partagé donne accès.',
    'Guest access suspended: the sharing subscription is on hold or the seat is over quota.':
        'Accès invité suspendu : l’abonnement partagé est suspendu ou la place dépasse le quota.',
    'Guest access ended: no active sharing subscription for this member.': 'Accès invité terminé : aucun abonnement partagé actif pour ce membre.',
    "This membership is now linked to the member's own purchase and is no longer managed by WC Subscription Team Access.":
        'Cette adhésion est désormais liée à un achat du membre et n’est plus gérée par WC Subscription Team Access.',
    'WC Subscription Team Access is inactive: the following plugins are required: %s.': 'WC Subscription Team Access est inactif : les extensions suivantes sont requises : %s.',
    'Shared subscription access': 'Accès partagé à un abonnement',
    'Invited email address': 'Adresse e-mail invitée',
    'When a subscription holder invites someone to share their access, we store the invited email address, the date of the invitation and, once accepted, the account of the guest. This data is used only to send the invitation and to grant access to the content covered by the subscription. The subscription holder can see the email addresses they invited. The email addresses of ended invitations are anonymized automatically.':
        'Lorsque le titulaire d’un abonnement invite une personne à partager son accès, nous enregistrons l’adresse e-mail invitée, la date de l’invitation et, une fois celle-ci acceptée, le compte de l’invité. Ces données servent uniquement à envoyer l’invitation et à donner accès aux contenus couverts par l’abonnement. Le titulaire voit les adresses e-mail qu’il a invitées. Les adresses des invitations terminées sont anonymisées automatiquement.',
    'Maximum number of guests': 'Nombre maximal d’invités',
    'Number of people the buyer can invite, in addition to themselves, to share the premium access granted by this subscription. 0 disables the feature. For variable subscriptions, this is the default value for variations that leave their own field empty.':
        'Nombre de personnes que l’acheteur peut inviter, en plus de lui-même, à partager les accès premium de cet abonnement. 0 désactive la fonctionnalité. Pour un abonnement variable, c’est la valeur par défaut des variations dont le champ est vide.',
    'Leave empty to use the product value.': 'Laisser vide pour utiliser la valeur du produit.',
    'Subscription #%s': 'Abonnement n°%s',
    'Another operation is in progress on this subscription. Please try again in a moment.': 'Une autre opération est en cours sur cet abonnement. Veuillez réessayer dans un instant.',
    'This guest could not be found.': 'Cet invité est introuvable.',
    'You are not allowed to manage the guests of this subscription.': 'Vous n’êtes pas autorisé à gérer les invités de cet abonnement.',
    'This subscription does not allow inviting guests.': 'Cet abonnement ne permet pas d’inviter des personnes.',
    'The subscription that shares this access is not active.': 'L’abonnement qui partage cet accès n’est pas actif.',
    'Please enter a valid email address.': 'Veuillez saisir une adresse e-mail valide.',
    'The subscription holder already has access and cannot be invited.': 'Le titulaire de l’abonnement a déjà accès et ne peut pas être invité.',
    'This email address has already been invited to this subscription.': 'Cette adresse e-mail a déjà été invitée sur cet abonnement.',
    'This person already has access through this subscription.': 'Cette personne a déjà accès grâce à cet abonnement.',
    'No guest seat is available on this subscription.': 'Aucune place d’invité n’est disponible sur cet abonnement.',
    'The number of guests exceeds the quota of this subscription. Remove guests before inviting new ones.':
        'Le nombre d’invités dépasse le quota de cet abonnement. Retirez des invités avant d’en inviter de nouveaux.',
    'Too many invitations have been sent recently. Please try again later.': 'Trop d’invitations ont été envoyées récemment. Veuillez réessayer plus tard.',
    'This invitation was sent a few minutes ago. Please wait before sending it again.': 'Cette invitation a été envoyée il y a quelques minutes. Patientez avant de la renvoyer.',
    'This invitation has been sent too many times.': 'Cette invitation a été envoyée trop de fois.',
    'Only pending or expired invitations can be sent again.': 'Seules les invitations en attente ou expirées peuvent être renvoyées.',
    'This invitation link is no longer valid: it may have already been used, cancelled or replaced by a more recent invitation.':
        'Ce lien d’invitation n’est plus valide : il a peut-être déjà été utilisé, annulé ou remplacé par une invitation plus récente.',
    'This invitation has expired. Ask the person who invited you to send it again.': 'Cette invitation a expiré. Demandez à la personne qui vous a invité de vous la renvoyer.',
    'This invitation was sent to a different email address than the one of your account.': 'Cette invitation a été envoyée à une autre adresse e-mail que celle de votre compte.',
    'Please log in to accept this invitation.': 'Connectez-vous pour accepter cette invitation.',
    'The invitation was saved but the email could not be sent. Use \\"Resend\\" to try again.':
        'L’invitation a été enregistrée mais l’e-mail n’a pas pu être envoyé. Utilisez « Renvoyer » pour réessayer.',
    'An error occurred.': 'Une erreur est survenue.',
    'Hello,': 'Bonjour,',
    '%1$s accepted your invitation and now shares the premium access of your subscription %2$s.':
        '%1$s a accepté votre invitation et partage désormais les accès premium de votre abonnement %2$s.',
    'Manage my guests': 'Gérer mes invités',
    '%1$s invites you to share the premium access of their subscription %2$s on %3$s.':
        '%1$s vous invite à partager les accès premium de son abonnement %2$s sur %3$s.',
    'To accept, follow the link below. You will be able to log in to your account or create one with this email address. No password is sent by email.':
        'Pour accepter, suivez le lien ci-dessous. Vous pourrez vous connecter à votre compte ou en créer un avec cette adresse e-mail. Aucun mot de passe n’est envoyé par e-mail.',
    'This link is personal and can be used only once. It expires on %s.': 'Ce lien est personnel et ne peut servir qu’une fois. Il expire le %s.',
    '<strong>%1$s</strong> accepted your invitation and now shares the premium access of your subscription <strong>%2$s</strong>.':
        '<strong>%1$s</strong> a accepté votre invitation et partage désormais les accès premium de votre abonnement <strong>%2$s</strong>.',
    '<strong>%1$s</strong> invites you to share the premium access of their subscription <strong>%2$s</strong> on %3$s.':
        '<strong>%1$s</strong> vous invite à partager les accès premium de son abonnement <strong>%2$s</strong> sur %3$s.',
    'Accept the invitation': 'Accepter l’invitation',
    'Back to the website': 'Retour au site',
    '<strong>%1$s</strong> invites you to share the premium access of their subscription <strong>%2$s</strong>.':
        '<strong>%1$s</strong> vous invite à partager les accès premium de son abonnement <strong>%2$s</strong>.',
    'Invitation sent to: %s': 'Invitation envoyée à : %s',
    'Log out, then log in with the account that uses the invited email address, or create an account with it.':
        'Déconnectez-vous, puis connectez-vous avec le compte qui utilise l’adresse invitée, ou créez un compte avec cette adresse.',
    'Log out': 'Se déconnecter',
    'I already have an account': 'J’ai déjà un compte',
    'Log in with the account that uses the invited email address.': 'Connectez-vous avec le compte qui utilise l’adresse e-mail invitée.',
    'I am creating my account': 'Je crée mon compte',
    'First name': 'Prénom',
    'Last name': 'Nom',
    'Password': 'Mot de passe',
    'At least %d characters.': 'Au moins %d caractères.',
    'Confirm password': 'Confirmer le mot de passe',
    'Create my account and accept': 'Créer mon compte et accepter',
    'None of your subscriptions allows inviting guests.': 'Aucun de vos abonnements ne permet d’inviter des personnes.',
    'Invite people to share the premium access of your subscription. Each guest uses their own account. You can remove or replace a guest at any time.':
        'Invitez des personnes à partager les accès premium de votre abonnement. Chaque invité utilise son propre compte. Vous pouvez retirer ou remplacer un invité à tout moment.',
    'Maximum guests': 'Invités maximum',
    'Seats used': 'Places utilisées',
    'Seats available': 'Places disponibles',
    ('Your subscription now allows fewer guests: %d guest is over the quota and their access is suspended. Remove guests to free seats; no new invitation is possible until then.',
     'Your subscription now allows fewer guests: %d guests are over the quota and their access is suspended. Remove guests to free seats; no new invitation is possible until then.'):
        ('Votre abonnement autorise désormais moins d’invités : %d invité dépasse le quota et son accès est suspendu. Retirez des invités pour libérer des places ; aucune nouvelle invitation n’est possible d’ici là.',
         'Votre abonnement autorise désormais moins d’invités : %d invités dépassent le quota et leur accès est suspendu. Retirez des invités pour libérer des places ; aucune nouvelle invitation n’est possible d’ici là.'),
    'Your subscription is not active: guests cannot access premium content and new invitations are not possible.':
        'Votre abonnement n’est pas actif : vos invités n’ont pas accès aux contenus premium et aucune nouvelle invitation n’est possible.',
    'Guest': 'Invité',
    'Joined on %s': 'A rejoint le %s',
    'Invited on %s': 'Invité le %s',
    'Replace': 'Remplacer',
    'New guest email address': 'Adresse e-mail du nouvel invité',
    'Replace and invite': 'Remplacer et inviter',
    'Cancel this invitation?': 'Annuler cette invitation ?',
    'Remove this guest? They will immediately lose the access shared by your subscription.': 'Retirer cet invité ? Il perdra immédiatement l’accès partagé par votre abonnement.',
    'Cancel': 'Annuler',
    'Remove': 'Supprimer',
    'You have not invited anyone yet.': 'Vous n’avez encore invité personne.',
    'Invite a user': 'Inviter un utilisateur',
    'Email address of the person to invite': 'Adresse e-mail de la personne à inviter',
    ('The invitation link is valid for %d day. A pending invitation reserves a seat.', 'The invitation link is valid for %d days. A pending invitation reserves a seat.'):
        ('Le lien d’invitation est valable %d jour. Une invitation en attente réserve une place.', 'Le lien d’invitation est valable %d jours. Une invitation en attente réserve une place.'),
    'All the seats of your subscription are used. Remove or replace a guest to invite someone else.':
        'Toutes les places de votre abonnement sont utilisées. Retirez ou remplacez un invité pour inviter quelqu’un d’autre.',
    'Previous guests and invitations': 'Anciens invités et invitations',
}


def unquote(block):
    return ''.join(re.findall(r'^"(.*)"$', block, re.M))


def quote(text):
    return '"' + text.replace('\\"', '\x00').replace('"', '\\"').replace('\x00', '\\"') + '"'


def main():
    pot = POT.read_text(encoding='utf-8')
    header, *entries = pot.split('\n\n')
    header = header.replace('Content-Type: text/plain; charset=UTF-8', 'Content-Type: text/plain; charset=UTF-8')
    header = re.sub(r'"Language-Team: .*\\n"', '"Language-Team: Français\\\\n"', header)
    header = re.sub(r'"Last-Translator: .*\\n"', '"Last-Translator: Nouveaux Territoires\\\\n"', header)
    header = re.sub(r'"PO-Revision-Date: .*\\n"', '"PO-Revision-Date: ' + datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M+0000') + '\\\\n"', header)
    header += '\n"Language: fr_FR\\n"\n"Plural-Forms: nplurals=2; plural=(n > 1);\\n"'
    out = [header]
    missing = []
    for entry in entries:
        if not entry.strip():
            continue
        m_id = re.search(r'^msgid ("(?:.*)"(?:\n".*")*)', entry, re.M)
        m_pl = re.search(r'^msgid_plural ("(?:.*)"(?:\n".*")*)', entry, re.M)
        msgid = unquote(m_id.group(1))
        prefix = entry[: m_id.start()]
        if m_pl:
            plural = unquote(m_pl.group(1))
            tr = FR.get((msgid, plural))
            if tr is None:
                missing.append(msgid)
                continue
            out.append(prefix + f'msgid {quote(msgid)}\nmsgid_plural {quote(plural)}\nmsgstr[0] {quote(tr[0])}\nmsgstr[1] {quote(tr[1])}')
        else:
            tr = FR.get(msgid)
            if tr is None:
                missing.append(msgid)
                continue
            out.append(prefix + f'msgid {quote(msgid)}\nmsgstr {quote(tr)}')
    if missing:
        sys.exit('Traductions manquantes :\n- ' + '\n- '.join(missing))
    PO.write_text('\n\n'.join(out) + '\n', encoding='utf-8')
    print(f'{len(out) - 1} chaînes traduites -> {PO.relative_to(ROOT)}')


if __name__ == '__main__':
    main()
