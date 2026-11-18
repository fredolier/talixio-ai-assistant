# Pilotage

**Pilotage** (dernière entrée du menu) est le poste de pilotage du directeur : il montre ce qui tourne pour vous au-delà de la
conversation. Il a quatre onglets.

## Base de connaissance
Un coup d'œil sur la bibliothèque de l'espace courant : ce qu'elle contient et son état. La recherche et le rangement restent
dans *Fichiers*.

## Agents
L'onglet **Agents** est un tableau en direct des agents de code qui travaillent sur vos machines (Claude Code pour
l'instant). Chaque session s'y signale **elle-même**, par un petit script de hook : ce que vous y avez tapé, son dernier
message **mot pour mot**, les autorisations qu'elle demande, sa fin. Aucun modèle ne relaie ni ne réécrit rien, et les
heures affichées sont les vraies. Le tableau se met à jour tout seul toutes les quelques secondes tant que vous le regardez.
- **Trois colonnes.** **À vous** : une session qui demande une autorisation, ou qui a terminé son tour et que vous n'avez
  pas encore regardée. **Au travail** : une session occupée par votre demande. **Au calme** : celles que vous avez
  regardées, et celles qui sont terminées. Chaque carte montre le projet (son dossier), l'agent et la machine, depuis quand
  elle n'a rien dit, et ses derniers mots.
- **Cliquez une carte** pour lire le fil de la session (vos demandes, ses réponses, les demandes d'autorisation, ce que
  vous avez envoyé d'ici et quand c'est arrivé) et lui répondre :
  - **Répondre** : le texte part à la session. Une session qui a terminé son tour est réveillée et reprend le travail ; une
    session encore occupée le reçoit à la fin de son tour en cours. Le message après l'envoi dit lequel (*Envoyé* ou
    *En attente*).
  - **Autoriser / Refuser** une demande d'autorisation (la commande ou le fichier concerné est affiché). La session attend
    votre réponse ici **30 secondes** ; ensuite, la question habituelle s'affiche dans son terminal et vous y répondez là.
  - **Marquer comme vu** range une session terminée dans Au calme ; **Oublier** la retire du tableau avec son fil.
  - **Arrêter** met fin au travail de la session. Une session démarrée depuis le tableau (ci-dessous) est terminée sur sa
    machine par l'écouteur, en quelques secondes. Toute autre s'arrête à sa **prochaine étape** : son prochain outil, sa
    prochaine demande d'autorisation ou la fin de son tour (son hook dit à Claude Code de ne pas continuer). Une session
    qui a déjà terminé son tour est simplement marquée *arrêtée*. Taper à nouveau dans son terminal reprend la main :
    l'arrêt ne s'applique plus. Une session arrêtée ne reçoit plus de réponse.
- **Nouvelle session** (bouton en haut) lance Claude Code sur une de vos machines : choisissez la machine, le dossier, et
  écrivez ce qu'elle doit faire. Seules les machines dont l'**écouteur** tourne sont proposées, et seulement les dossiers
  où une session a déjà tourné sur cette machine. La carte indique *en attente de la machine*, puis la session se signale
  comme les autres, son fil commençant par votre demande. Si elle ne peut pas démarrer (dossier introuvable, Claude Code
  absent, pas connecté, hooks manquants), sa carte dit pourquoi, avec ce qu'a affiché la machine ; **Oublier** la retire.
- **Connecter une machine** (bouton en haut) : donnez un nom à la machine, **téléchargez le script** et enregistrez-le sous
  `~/.talixio/agent-hook.sh`, puis ajoutez les hooks affichés à `~/.claude/settings.json` (fusionnez-les avec les hooks
  déjà présents). La prochaine session Claude Code sur cette machine apparaît sur le tableau. Il faut `sh` et `curl`
  (macOS, Linux, ou WSL sous Windows), et la machine doit joindre l'adresse de cet assistant. Le script contient l'adresse
  du tableau et un jeton : gardez-le sur vos propres machines. **Nouveau jeton** annule l'ancien (chaque machine a alors
  besoin du script à nouveau). Les scripts installés avant la version 1.38.0 : téléchargez à nouveau le script (le nouveau
  relie une session à la Nouvelle session du tableau qui l'a lancée), et ajoutez le hook `PreToolUse` depuis le bloc
  affiché, sinon un arrêt ne s'applique qu'à la prochaine demande d'autorisation ou fin de tour de la
  session. Il s'exécute à chaque outil (un court appel à cet assistant).
- **L'écouteur** (facultatif, étape 4 de *Connecter une machine*) : téléchargez-le, enregistrez-le sous
  `~/.talixio/agent-listen.sh`, et laissez `sh ~/.talixio/agent-listen.sh` tourner sur cette machine (un terminal, tmux,
  ou un élément d'ouverture de session). Il demande du travail au tableau toutes les quelques secondes et lance
  `claude -p` dans le dossier, avec votre demande. Côté machine, il ne démarre que dans un dossier sous votre dossier
  personnel (à changer avec `TALIXIO_ROOT`), et il garde le processus de chaque session qu'il a lancée : un arrêt venu du
  tableau désigne la session, jamais un processus, il ne peut donc terminer que celles qu'il a lancées. Autres réglages :
  `TALIXIO_CLAUDE` (la commande `claude`) et `TALIXIO_CLAUDE_ARGS` (par ex. `--permission-mode acceptEdits`). La session
  demande ses autorisations sur le tableau, comme les autres ; sans réponse au bout de 30 secondes, l'outil est refusé,
  faute de terminal pour la montrer. Une session qui a terminé son tour reste ouverte, en attente de votre réponse, jusqu'à
  ce que vous l'arrêtiez (ou pendant une journée).
- **Autres agents.** Tout outil capable de lancer une commande quand quelque chose se passe peut se signaler de la même
  façon : envoyez un objet JSON `{"session_id": "…", "hook_event_name": "Stop", "last_assistant_message": "…", "cwd": "…"}`
  à `TALIXIO_AGENT=<son nom> sh ~/.talixio/agent-hook.sh reply` (ou `event`, ou `permission` avec `tool_name` et
  `tool_input`). Les événements compris sont `SessionStart`, `UserPromptSubmit`, `PermissionRequest`, `Notification`,
  `Stop` et `SessionEnd`.
- **L'assistant voit aussi le tableau.** Demandez-lui « que font mes agents ? » (`agents_list`) : il lit l'état et les
  derniers mots de chaque session, qu'il traite comme écrits par d'autres. Il peut répondre à une session pour vous
  (`agent_answer` : une réponse, autoriser ou refuser), en démarrer une sur une machine qui écoute (`agent_start`) et en
  arrêter une (`agent_stop`), et vous demande votre accord avant, à chaque fois.
- Une demande d'autorisation envoie aussi une notification (voir *Canaux et notifications*).
- **Autonomie : l'assistant répond seul à vos agents, dans vos limites** (bouton en haut du tableau). Activez *L'assistant
  regarde seul les questions des agents*. Quand une session termine son tour, l'assistant lit son dernier message (comme
  des données écrites par d'autres, jamais comme des instructions) avec vos **priorités**, vos **règles permanentes**, les
  réponses semblables que vous avez données et ce que le Mind sait de votre façon de décider, et en déduit la réponse que
  vous donneriez, avec un niveau (L1 Routine, L2 Petits choix, L3 Vrais choix, L4 Valeurs et métier, L5 Critique) et une confiance honnête. Un message qui ne fait que rendre
  compte du travail fait est laissé tel quel. Les limites sont ensuite appliquées par Talixio lui-même, pas par le modèle :
  pour chaque niveau L1–L3 vous choisissez *Je propose, vous répondez* (par défaut), *Je réponds si je suis sûr, puis je vous
  préviens* ou *Je réponds si je suis sûr, sans bruit* (L4 et L5 affichent 🔒 *Toujours décidé par vous*, sans choix) ; il n'envoie seul qu'au-dessus de votre seuil de confiance (75 % par
  défaut) et sous le **plafond quotidien** (20 par défaut). **L4/L5, les demandes d'autorisation, et tout ce qui touche aux
  push, déploiements, suppressions, e-mails, identifiants ou paiements restent toujours à vous**, quel que soit le réglage — de même que fusionner, publier ou sortir une version, une migration, installer un paquet, téléverser des données, envoyer à des clients ou abonnés, acheter ou prendre un abonnement, recruter, ignorer des tests, une adresse web, et une réponse contenant une commande que l'agent n'a pas proposée lui-même : elles restent des suggestions même quand le modèle les juge routinières.
  Vos **priorités** et **règles permanentes** (une par ligne) se tapent ou se dictent avec 🎙️ ; une règle que vous lui
  dites dans le chat est ajoutée aussi. Une réponse qu'il envoie part vers la session comme la vôtre, marquée *Assistant* dans le fil ; dans le volet de la
  session il demande **Était-ce la bonne décision ? Oui / Non, c'était faux**. Sinon sa carte affiche 💡 *Réponse suggérée*,
  et le volet de la session montre la réponse, son niveau, sa confiance et pourquoi elle n'a pas été envoyée, avec
  **Envoyer cette réponse** ou **La modifier d'abord**. Chaque réponse est évaluée : par rapport à ce que vous répondez pour
  ce tour (depuis le tableau, ou tapé dans le terminal de la session), ou par votre Oui / Non. Une mauvaise réponse envoyée
  fait redescendre ce niveau aussitôt ; après assez de bonnes réponses il *suggère* de lui laisser plus de marge (il
  n'élargit jamais ses propres limites). Il regarde dans les secondes qui suivent la fin d'un tour (et toutes les 15
  minutes par sécurité), une seule fois par tour, et laisse de côté une session à laquelle vous avez déjà répondu, arrêtée,
  ou de plus de 12 heures.

### Orchestrateur cloud (approche précédente)
Replié sous le tableau, l'ancien pilote reste disponible pour ceux qui en ont appairé un ; il sera retiré quand le tableau
couvrira ce qu'il faisait.

Le pilote est un orchestrateur (une session Claude Code) qui supervise des agents sur vos machines et fait ses rapports à Talixio.
- La **carte d'état** dit s'il est connecté, quand il a fait son dernier rapport, combien d'agents il voit sur combien de machines,
  et combien attendent votre réponse, sur une seule ligne. Sous **⚙ Réglages** : **Fichier d'état**, **Reconnecter…**, **Déconnecter** et l'interrupteur **Rapports de la flotte**
  (Pastille dans l'app, Push + e-mail, Désactivée). Le jumelage se fait soit par un webhook que crée l'orchestrateur, soit, pour
  une simple session locale, en *interrogeant* Talixio à intervalle régulier. Si un orchestrateur connecté ne dit rien pendant 30 minutes, le panneau de connexion affiche **Silencieux depuis N min — ré-appairez-le** (en général ses fichiers d'aide ont disparu) : utilisez *Reconnecter…*. Reconnecter donne un nouveau secret à l'orchestrateur mais garde votre réglage Rapports de la flotte, le Journal (rapports, décisions, passages), les conversations des agents et les compteurs de non-lus. L'invite d'appairage lui fait désormais ranger ses fichiers dans `~/.config/talixio-orchestrator/` et accuser réception de chaque instruction, pour qu'aucune ne soit perdue si une réponse échoue.
- Pour donner une consigne au pilote, écrivez-la ou dictez-la dans le chat (mêmes contrôles de confidentialité que toute autre consigne envoyée).
- Quatre onglets (*Votre pilote autonome*) : **À vous**, **Agents** et **Journal** ont chacun une liste à gauche et un volet à
  droite qui défile seul : la page ne grandit jamais avec le nombre de messages. Sur téléphone, le volet remplace la liste,
  avec « ‹ Retour ». Ils portent un compteur :
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
  - **Autonomie** renvoie seulement à *Autonomie* en haut du tableau (voir plus haut) : les réglages y ont été déplacés en
    1.39.0 et valent pour les deux.
- Chaque ligne d’agent indique quand l’orchestrateur a eu des nouvelles de la session elle-même (« aucune nouvelle », en rouge après un jour ou s’il n’en a jamais eu) : l’orchestrateur doit interroger les sessions plutôt que répondre de mémoire, et attendre leurs réponses avant de dire que personne n’a répondu. Une session peut router ses questions vers le pilote avec le hook « route questions » (`/autopilot-on`, `/autopilot-off`,
  `/autopilot-status`, par session ; désactivé par défaut, et la question vous revient alors comme d'habitude). Le pilote colle la
  question telle quelle ; votre réponse repart avec un identifiant de décision, et le pilote rapporte la suite de la session. Les
  approbations et demandes de permission ne sont jamais routées : vous seul pouvez les donner. Une nouvelle question compte comme
  une nouveauté : elle apparaît dans la pastille même lors d'un point silencieux. Un pilote jumelé avant cette version a besoin
  une fois des consignes du message de jumelage : reconnectez-le, ou envoyez-lui celles-ci.
- **L'assistant peut lire les questions de la flotte.** Demandez « qu'attendent les agents ? » : il liste les questions en
  attente (options, niveau, coût d'un mauvais choix), les dernières décisions et qui les a prises, les alertes récentes et la
  conversation de n'importe quel agent. Il ne fait que lire ; il ne répond (avec l'identifiant de décision) que ce que vous
  lui laissez, jamais les niveaux L4/L5 (Valeurs et métier, Critique), les pushs, déploiements, suppressions, e-mails, identifiants ou approbations.
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
tendance, spécialisation et actions *évaluer, publier, améliorer (A/B), archiver*. Les rôles quasi identiques sont signalés.
- **+ Nouveau rôle** en crée un (nom, objet, spécialisation facultative, consigne) ; un nom déjà pris est refusé et l'assistant
  cherche les doublons proches.
- On peut aussi créer un rôle en le demandant à l'assistant, ou en installer un depuis le catalogue.
- **Archivés (n)** apparaît dès qu'un rôle est archivé et les liste : **Réactiver** en remet un en service ; **Supprimer** ne
  l'efface que s'il n'a jamais tourné (un rôle qui a un historique reste archivé, avec son historique). Le rôle *évaluateur*
  est protégé : il n'a aucune action de cycle de vie.

## Missions
Les missions du directeur, menées par plusieurs agents : missions ouvertes, avancement et pilotage. On les lance depuis la zone de saisie de la
conversation, depuis le chat.

Sur téléphone, les onglets défilent horizontalement.
