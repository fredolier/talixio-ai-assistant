# Pilotage

**Pilotage** (dernière entrée du menu) est le poste de pilotage du directeur : il montre ce qui tourne pour vous au-delà de la
conversation. Il a quatre onglets.

## Base de connaissance
Un coup d'œil sur la bibliothèque de l'espace courant : ce qu'elle contient et son état. La recherche et le rangement restent
dans *Fichiers*.

## Votre pilote autonome
Le pilote est un orchestrateur (une session Claude Code) qui supervise des agents sur vos machines et fait ses rapports à Talixio.
- La **carte d'état** dit s'il est connecté, quand il a fait son dernier rapport, combien d'agents il voit sur combien de machines,
  et combien attendent votre réponse. **Reconnecter…** et **Déconnecter** y sont, avec l'interrupteur **Rapports de la flotte**
  (Pastille dans l'app, Push + e-mail, Désactivée). Le jumelage se fait soit par un webhook que crée l'orchestrateur, soit, pour
  une simple session locale, en *interrogeant* Talixio à intervalle régulier.
- **Parler au pilote** (sous les onglets) lui envoie une consigne, écrite ou dictée. Elle passe les mêmes contrôles de
  confidentialité que toute autre consigne envoyée.
- Trois onglets, chacun avec une liste à gauche et un volet à droite qui défile seul : la page ne grandit jamais avec le nombre de
  messages. Sur téléphone, le volet remplace la liste, avec « ‹ Retour ». Chaque onglet porte un compteur :
  - **À vous** : les sessions qui attendent votre réponse, chacune avec la question telle qu'elle a été posée, son niveau (L1–L5),
    les options en boutons et le coût d'un mauvais choix. Une **décision** se répond avec un bouton (ou *Répondre autrement…*, qui
    ouvre la conversation de l'agent) ; une **validation** (un clic que vous seul pouvez donner, dans cette session, par exemple un
    jeton révoqué) n'a pas de bouton : ouvrez la session ; une **instruction**, c'est à vous de dire à l'agent quoi faire ensuite.
    Sous *Décidées récemment*, vous voyez **qui a répondu quoi** : vous, ou l'assistant en votre nom.
  - **Agents** : le roster avec une recherche, les filtres En attente / Actifs / Inactifs / Hors ligne, et *Grouper par machine*.
    Choisissez-en un pour lire sa conversation : la question qu'il attend reste épinglée en haut, un message trop long est coupé
    avec *Voir tout*, les anciens messages se chargent avec « ↑ n messages plus anciens », et la zone du bas écrit à cet agent (ou
    dictez). Un nombre rouge sur une ligne compte ses réponses non lues ; il disparaît quand vous ouvrez la conversation. Les
    réponses de chaque agent restent dans sa propre conversation.
  - **Journal** : seulement les rapports d'ensemble, groupés par jour, filtrés Tout / Alertes / Bilans, avec une recherche ; un
    clic ouvre le rapport en entier. Une session qui se met à attendre votre réponse ajoute une alerte.
- Une session peut router ses questions vers le pilote avec le hook « route questions » (`/autopilot-on`, `/autopilot-off`,
  `/autopilot-status`, par session ; désactivé par défaut, et la question vous revient alors comme d'habitude). Le pilote colle la
  question telle quelle ; votre réponse repart avec un identifiant de décision, et le pilote rapporte la suite de la session. Les
  approbations et demandes de permission ne sont jamais routées : vous seul pouvez les donner. Une nouvelle question compte comme
  une nouveauté : elle apparaît dans la pastille même lors d'un point silencieux. Un pilote jumelé avant cette version a besoin
  une fois des consignes du message de jumelage : reconnectez-le, ou envoyez-lui celles-ci.
- **Rapports de la flotte** : comment les rapports du pilote vous parviennent (voir *Canaux et notifications*) : une pastille dans
  l'app par défaut, le Journal et les conversations, et en option push + e-mail.

## Rôles
Le catalogue des rôles que votre assistant peut endosser, groupés en une carte par spécialisation. Chaque rôle est un nœud dont
l'anneau montre le taux d'acceptation et dont la taille suit l'activité ; **cliquez-en un** pour ouvrir son détail : mesures,
tendance, spécialisation et actions *évaluer, publier, améliorer (A/B), archiver, supprimer*. Les rôles quasi identiques sont signalés.
- **+ Nouveau rôle** en crée un (nom, objet, spécialisation facultative, consigne) ; un nom déjà pris est refusé et l'assistant
  cherche les doublons proches.
- On peut aussi créer un rôle en le demandant à l'assistant, ou en installer un depuis le catalogue.
- **Archivés…** rouvre les rôles archivés. Le rôle *évaluateur* est protégé : il n'a aucune action de cycle de vie.

## Chantiers
Les directives du directeur : chantiers ouverts, avancement et pilotage. On les lance depuis la zone de saisie de la
conversation, avec *Parler au pilote*.

Sur téléphone, les onglets défilent horizontalement.
