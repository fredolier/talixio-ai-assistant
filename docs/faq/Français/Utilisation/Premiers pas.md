# Premiers pas

## Où parler à l'assistant ?
- **L'app web** (ordinateur et téléphone), à l'adresse de votre serveur. Installez-la comme une app :
  Chrome/Edge, icône d'installation dans la barre d'adresse ; Android, menu ⋮ → *Installer l'app* ;
  iPhone, Partager → *Sur l'écran d'accueil*. Il faut une adresse en HTTPS.
- **Telegram, Signal, e-mail** et le terminal (`sudo talixio chat`) : c'est la même conversation partout.

## Ce qu'il sait faire
- Répondre en direct, chercher sur le web, lire des pages.
- Se souvenir de vous (mémoire modifiable dans la page Mémoire) et retrouver ce qui a été dit.
- Ranger et retrouver vos documents (Fichiers), en citant la page.
- Gérer vos tâches, rappels et routines (briefing du matin…).
- Transcrire et résumer vos réunions et notes vocales.
- Lire votre messagerie et votre agenda, préparer des réponses ; tout ce qui agit à l'extérieur
  (envoyer un e-mail, ajouter un événement…) attend votre **approbation**.

## Raccourcis utiles
- Commencer un message par `/deep` pour un raisonnement plus poussé, `/fast` pour une réponse rapide.
- Sous chaque réponse : le modèle, les jetons, le coût estimé, et où sont allées vos données.
- **Arrêter** une réponse en cours : le bouton d'envoi devient *Arrêter la réponse*.
- **Écrire le message suivant sans attendre.** Pendant qu'une réponse arrive, tapez et envoyez comme d'habitude (Entrée,
  ou le bouton d'envoi, qui n'affiche *Arrêter la réponse* que lorsque la zone est vide) : le message attend son tour au-dessus de la zone
  et part tout seul quand la réponse est terminée, un à la fois et dans l'ordre. Un enregistrement terminé entre-temps attend
  de la même façon. ✎ reprend un message en attente dans la zone, ✕ l'annule. La file est gardée dans ce navigateur si vous
  fermez la page, par espace. Si un message en attente ne peut pas partir, la file s'arrête là et propose *Envoyer
  maintenant*. Arrêter une réponse n'annule pas la file.
- **Modifier** votre dernier message ou demander une **autre réponse**.

## Rechercher dans la conversation
Le bouton 🔍 en haut du chat (*Rechercher dans la conversation*) cherche dans les messages de l'espace courant. Tapez au
moins deux lettres : les messages qui contiennent vos mots viennent d'abord, puis, après quelques secondes, ceux qui ont un
sens proche. Cliquez un résultat pour aller à ce message ; *Fermer* (ou Échap) masque la recherche.

## La zone de message
Entrée envoie ; Maj+Entrée passe à la ligne. La zone s'agrandit avec ce que vous écrivez, jusqu'à 40 % de la fenêtre (au
moins une dizaine de lignes), pour que vous voyiez l'essentiel d'un long message ; au-delà, elle défile.

Les réponses avec un tableau ou du code sont plus larges que les autres (sur un grand écran, jusqu'à environ 1100px). Un
tableau garde ses mots entiers : s'il reste trop large, faites-le défiler sur le côté.

## Dicter
Le bouton 🎙️ à côté de la zone de message (*Dicter*) écrit ce que vous dites : appuyez, parlez, appuyez à nouveau. Ce que
vous avez dit est transcrit et ajouté dans la zone, après ce qui s'y trouve déjà, pour que vous le relisiez avant
d'envoyer ; l'enregistrement n'est pas gardé. (Le bouton micro d'à côté est différent : il enregistre une note vocale ou
une réunion.) Le même 🎙️ se trouve sur d'autres zones de texte, comme les priorités de l'autonomie dans Pilotage. Il faut un
navigateur récent en HTTPS, l'accès au micro, et la transcription activée (voir *Audio et réunions*).

## Le menu
Sur ordinateur, le menu à gauche a huit entrées : **Chat**, **Tâches**, **Fichiers** (la bibliothèque), **Apps**,
**Notifications**, **Mails**, **Contacts** et, en dernier, **Pilotage** (voir *Pilotage*).
- **⋯ Plus** contient les **Automatisations** (onglets **Routines** et **Missions** : tâches planifiées et travail en
  arrière-plan) et **Activité et coûts**.
- Votre nom en bas ouvre le **menu du compte** : Réglages, Utilisation (Activité et coûts), Confidentialité, Mémoire, Langue,
  Thème, *Installer l'app* si votre navigateur le propose, *Obtenir de l'aide* (cette FAQ) et Se déconnecter.

Sur téléphone, la barre du bas affiche **Chat**, **Tâches**, **Fichiers**, **Notifications** et **Pilotage**. **⋯ Plus**
contient le reste : Automatisations, Activité et coûts, **Apps**, **Mails à traiter**, **Contacts**, **Mémoire**,
**Confidentialité**, **Réglages**, *Installer l'app* (si proposé) et **Se déconnecter**. Il n'y a pas de menu du compte sur
téléphone : le thème est aussi dans **Réglages → Essentiel → Apparence** ; Langue et *Obtenir de l'aide* ne sont que dans
le menu du compte, sur ordinateur.

## Mails à traiter
**Mails** (*Mails à traiter*) range vos nouveaux e-mails en **À répondre**, **À faire**, **À lire** et **Priorité faible**.
Il faut votre boîte mail connectée (Réglages → Comptes connectés → *Votre boîte mail*) avec *Trier les nouveaux e-mails*
activé. **Vérifier maintenant** trie tout de suite ; ensuite, la vérification a lieu toutes les 15 minutes. Chaque e-mail
montre son expéditeur, son objet et un court résumé, et une échéance s'il en a une.
- Changez un e-mail de groupe avec son sélecteur : il s'en souvient, et range les e-mails semblables de la même façon.
- **Préparer une réponse** (dans *À répondre*) demande à l'assistant, dans le chat, d'en rédiger une et de l'enregistrer en
  brouillon.
- **→ Tâche** ouvre une nouvelle tâche remplie à partir de l'e-mail, et liée à lui.
- **Terminé** le range ; cochez *afficher les traités* pour les revoir, et **Rouvrir** l'un d'eux.

## Contacts
**Contacts** liste les personnes dont vous recevez des e-mails, et ce que l'assistant apprend sur chacune. Cherchez par nom,
entreprise ou notes, ou ajoutez quelqu'un avec **Nouveau contact**. Cliquez une personne pour modifier son nom, son e-mail,
son téléphone, son entreprise et *Ce que vous savez*, puis **Enregistrer**. La fiche montre aussi ses e-mails récents, où
elle apparaît dans vos documents et dans vos discussions. **Poser une question sur cette personne** commence une question
dans le chat ; **Supprimer** demande confirmation.

## L'interface est en anglais ?
Menu du compte (sur ordinateur) → **Langue** (Automatique / Français / English) : le choix est mémorisé dans ce navigateur.
L'assistant, lui, répond dans la langue où vous lui écrivez. L'entrée **Thème** fait défiler Système → Clair → Sombre.
