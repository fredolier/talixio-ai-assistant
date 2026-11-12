# Pilotage

**Pilotage** (dernière entrée du menu) est le poste de pilotage du directeur : il montre ce qui tourne pour vous au-delà de la
conversation. Il a quatre onglets.

## Base de connaissance
Un coup d'œil sur la bibliothèque de l'espace courant : ce qu'elle contient et son état. La recherche et le rangement restent
dans *Fichiers*.

## Votre pilote autonome
Le pilote est un orchestrateur (une session Claude Code) qui supervise des agents sur vos machines et fait ses rapports à Talixio.
- La **carte d'état** dit s'il est connecté, quand il a fait son dernier rapport, combien d'agents il voit sur combien de machines,
  et combien attendent votre réponse, sur une seule ligne. Sous **⚙ Réglages** : **Fichier d'état**, **Reconnecter…**, **Déconnecter** et l'interrupteur **Rapports de la flotte**
  (Pastille dans l'app, Push + e-mail, Désactivée). Le jumelage se fait soit par un webhook que crée l'orchestrateur, soit, pour
  une simple session locale, en *interrogeant* Talixio à intervalle régulier. Si un orchestrateur connecté ne dit rien pendant 30 minutes, le panneau de connexion affiche **Silencieux depuis N min — ré-appairez-le** (en général ses fichiers d'aide ont disparu) : utilisez *Re-pair…*. Reconnecter donne un nouveau secret à l'orchestrateur mais garde votre réglage Rapports de la flotte, le Journal (rapports, décisions, passages), les conversations des agents et les compteurs de non-lus. L'invite d'appairage lui fait désormais ranger ses fichiers dans `~/.config/talixio-orchestrator/` et accuser réception de chaque instruction, pour qu'aucune ne soit perdue si une réponse échoue.
- Pour donner une consigne au pilote, écrivez-la ou dictez-la dans le chat (mêmes contrôles de confidentialité que toute autre consigne envoyée). Dans l'onglet Autonomie, le 🎙️ de *Vos priorités* et de *Règles permanentes* permet de les dicter à voix haute.
- Trois onglets, chacun avec une liste à gauche et un volet à droite qui défile seul : la page ne grandit jamais avec le nombre de
  messages. Sur téléphone, le volet remplace la liste, avec « ‹ Retour ». Chaque onglet porte un compteur :
  - **À vous** (la question d'abord et en grand, puis ce que coûterait un mauvais choix, puis un gros bouton par option, la suggestion du pilote en tête quand il en a une, et les mots libres ou la voix dessous ; une validation montre un cadenas et deux étapes — ouvrir la session, valider là-bas) : les sessions qui attendent votre réponse, chacune avec la question telle qu'elle a été posée, son niveau (L1–L5),
    les options en boutons et le coût d'un mauvais choix. Une **décision** se répond avec un bouton (ou *Répondre autrement…*, qui
    ouvre la conversation de l'agent) ; une **validation** (un clic que vous seul pouvez donner, dans cette session, par exemple un
    jeton révoqué) n'a pas de bouton : ouvrez la session ; une **instruction**, c'est à vous de dire à l'agent quoi faire ensuite.
    Sous *Décidées récemment*, vous voyez **qui a répondu quoi** : vous, ou l'assistant en votre nom.
    Une question se lit dans cet ordre : **qui demande**, **la question** mot pour mot, **les options** en boutons à un seul appui
    (ou, s'il n'y en a pas, une zone pour répondre avec vos mots — ou à la voix), **ce que le pilote répondrait** avec sa confiance,
    et **ce que coûte un mauvais choix**. Quand l'orchestrateur n'a transmis qu'un résumé, ou que le texte dit *full text not
    retrieved yet*, un message apparaît avec **Demander la question complète à l'orchestrateur** : la demande part à l'agent sans
    compter comme une réponse. Quand rien n'attend, l'onglet le dit et indique quand l'orchestrateur a parlé pour la dernière fois.
  - **Agents** : le roster avec une recherche, les filtres En attente / Actifs / Inactifs / Hors ligne, et *Grouper par machine*.
    Choisissez-en un pour lire sa conversation : la question qu'il attend reste épinglée en haut, un message trop long est coupé
    avec *Voir tout*, les anciens messages se chargent avec « ↑ n messages plus anciens », et la zone du bas écrit à cet agent (ou
    dictez). Un nombre rouge sur une ligne compte ses réponses non lues ; il disparaît quand vous ouvrez la conversation. Les
    réponses de chaque agent restent dans sa propre conversation.
  - **Journal** : seulement les rapports d'ensemble, groupés par jour, filtrés Tout / Alertes / Bilans, avec une recherche ; un
    clic ouvre le rapport en entier. Une session qui se met à attendre votre réponse ajoute une alerte.
- Chaque ligne d’agent indique quand l’orchestrateur a eu des nouvelles de la session elle-même (« aucune nouvelle », en rouge après un jour ou s’il n’en a jamais eu) : l’orchestrateur doit interroger les sessions plutôt que répondre de mémoire, et attendre leurs réponses avant de dire que personne n’a répondu. Une session peut router ses questions vers le pilote avec le hook « route questions » (`/autopilot-on`, `/autopilot-off`,
  `/autopilot-status`, par session ; désactivé par défaut, et la question vous revient alors comme d'habitude). Le pilote colle la
  question telle quelle ; votre réponse repart avec un identifiant de décision, et le pilote rapporte la suite de la session. Les
  approbations et demandes de permission ne sont jamais routées : vous seul pouvez les donner. Une nouvelle question compte comme
  une nouveauté : elle apparaît dans la pastille même lors d'un point silencieux. Un pilote jumelé avant cette version a besoin
  une fois des consignes du message de jumelage : reconnectez-le, ou envoyez-lui celles-ci.
- **Onglet Autonomie : l'assistant décide dans vos limites.** Activez *L'assistant regarde seul les questions des agents* :
  quand un agent pose une question, il la lit avec les décisions semblables que vous avez prises, ce qu'il sait de votre façon
  de travailler et vos **priorités** (une par ligne, la plus importante d'abord), puis appelle `fleet_decide` avec sa réponse
  et une confiance honnête. Pour chaque niveau L1–L5 vous choisissez : *Je propose, vous répondez* (par défaut), *Je réponds si
  je suis sûr, puis je vous préviens* ou *Je réponds si je suis sûr, sans bruit* ; il ne répond seul qu'au-dessus de la
  confiance fixée (75 % par défaut). **Les niveaux L4/L5 (valeurs, métier), les approbations et tout ce qui touche aux pushs,
  déploiements, suppressions, e-mails, identifiants ou paiements restent toujours à vous**, quel que soit le réglage. Une
  proposition apparaît sur la question comme *Réponse suggérée*, avec un bouton **Envoyer cette réponse**. Chaque réponse est notée
  avec sa confiance puis évaluée : face à votre propre réponse, ou avec **Oui / Non, c'était faux** sur ce qu'il a envoyé.
  Une erreur ramène aussitôt le niveau en arrière ; après assez de bonnes réponses il *suggère* de lui laisser plus de place
  (il ne relève jamais seul ses limites). Les corrections apprennent aussi au Mind comment vous décidez. Plus bas, les **Règles permanentes** (une préférence durable par ligne, p. ex. *ne jamais toucher à la facturation sans me demander*) sont données à l'assistant avec chaque question ; dites-lui une règle dans le chat et il l'enregistre. Un **plafond quotidien** (20 par défaut) limite le nombre de réponses qu'il peut envoyer seul chaque jour ; au-delà, il se contente de proposer.
- **L'assistant peut lire les questions de la flotte.** Demandez « qu'attendent les agents ? » : il liste les questions en
  attente (options, niveau, coût d'un mauvais choix), les dernières décisions et qui les a prises, les alertes récentes et la
  conversation de n'importe quel agent. Il ne fait que lire ; il ne répond (avec l'identifiant de décision) que ce que vous
  lui laissez, jamais les niveaux L4/L5, les pushs, déploiements, suppressions, e-mails, identifiants ou approbations.
- **Fichier d'état** : le bouton **Fichier d'état** de la carte d'état affiche, copie ou télécharge `pilot-status.md`, un fichier
  Markdown que l'assistant tient à jour à chaque changement (un rapport, une réponse, une décision, un réglage) : l'orchestrateur
  est-il vivant et sa dernière nouvelle, une ligne par agent (état, tâche, date et source de sa dernière nouvelle, en attente ou
  non), les questions en attente mot pour mot avec leurs identifiants et options, les dernières décisions (qui a répondu quoi),
  et vos priorités, règles, plafond quotidien et niveaux d'autonomie. La carte indique où il se trouve (dans le dossier de données
  de l'assistant), pour le lire aussi depuis le serveur ou le synchroniser ailleurs. Il ne contient jamais de secret ni le webhook d'appairage.
- **Rapports de la flotte** : comment les rapports du pilote vous parviennent (voir *Canaux et notifications*) : une pastille dans
  l'app par défaut, le Journal et les conversations, et en option push + e-mail.

### Passages : ce que l'orchestrateur a fait, passage après passage
Dans **Journal**, le filtre **Passages** liste chaque passage de l'orchestrateur — y compris les passages silencieux, repliés en une
ligne (*« 12 passages calmes, rien de neuf »*) : agents vus, combien attendaient, rapports et instructions échangés, et ce qu'il doit
corriger. S'il ne passe plus, la ligne d'état en haut le dit (*« Aucun passage depuis 40 min »*) : un silence se voit. Dans la
conversation de chaque agent, ce qui revient est distingué : une **Question** ou une **Approbation** (marquées), une **Réponse** à
une décision, une **Alerte**, ou un simple statut (plus petit, plus discret).

L'orchestrateur fait désormais **un seul appel par passage** (agents, rapports et accusés de réception en entrée ; instructions et
points à corriger en sortie). Les points à corriger sont par exemple une session en attente envoyée sans sa question, ou une question
sans son texte complet : l'orchestrateur doit la récupérer auprès de la session avant le passage suivant. Reconnectez le pilote
(*Connecter…*) pour donner les nouvelles instructions à votre orchestrateur ; l'ancienne méthode continue de fonctionner.

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
conversation, depuis le chat.

Sur téléphone, les onglets défilent horizontalement.
