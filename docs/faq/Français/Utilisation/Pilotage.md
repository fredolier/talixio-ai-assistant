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
- **La pastille** sur Pilotage dans le menu et sur l'onglet Agents compte les sessions dans *À vous*. Elle
  diminue à mesure que vous les ouvrez.
- **Me notifier** (en haut du tableau) choisit ce qui arrive aussi sur votre téléphone et dans vos notifications (voir
  *Canaux et notifications*) : *Demandes d'autorisation* (par défaut), *Autorisations et tours terminés* (aussi chaque
  tour qu'un agent termine, avec ses derniers mots) ou *Aucun*.
- **Depuis la discussion**, demandez « montre-moi toute la conversation de la session webshop » : l'assistant lit le fil
  de cette session (`agents_list` avec la session). Dites-lui « pour mes agents, le travail client passe d'abord » ou
  « ne jamais pousser un vendredi » : il enregistre vos priorités et règles permanentes pour l'autonomie du tableau
  (`agents_priorities`). Juste après avoir lu ce qu'un agent a écrit, un tel changement attend votre accord.
- **Ce que je sais** (bouton en haut du tableau) : ce que l'assistant a appris de vos agents, par projet (une machine et
  un dossier).
  - **Décisions.** Chaque réponse donnée à une session est gardée comme une décision : vos réponses (depuis le tableau
    ou tapées dans le terminal), vos Autoriser et Refuser, et ce que l'assistant a envoyé seul. Chacune indique qui l'a
    donnée et un sujet (périmètre, conception visuelle, code, tests, Git, déploiement, données, dépendances,
    documentation). Filtrez-les par projet, sujet et auteur.
  - **Résumés.** Une session est résumée quand elle se termine, ou après une demi-heure de silence s'il s'est passé
    assez de choses : son but, ce qu'elle a fait, les décisions et qui les a prises, et ce qui reste ouvert.
  - **Dossier.** Chaque projet a un dossier, réécrit au plus une fois par jour à partir de ses résumés et décisions : ce
    qu'il est, où il en est, le travail récent, comment vous y décidez, et ce qui reste ouvert.
  - **À quoi il sert.** Quand l'assistant décide pour un agent, il lit le dossier du projet et vos décisions passées,
    celles du même projet et du même sujet d'abord. Dans la discussion, demandez « que sais-tu du projet webshop ? »
    (`agents_knowledge`).
  - **Règles.** Quand le même choix revient dans au moins trois de *vos* réponses, l'assistant propose une règle, par
    exemple « Lancer les tests avant chaque commit », pour un projet ou pour tous. Il regarde après dix nouvelles réponses
    de votre part, ou chaque semaine. Chaque règle montre les réponses dont elle est tirée. **Confirmer** l'applique,
    **Modifier** la met dans vos mots (et l'applique), **Refuser** l'écarte et elle n'est plus jamais proposée ; une règle
    confirmée s'arrête avec **Ne plus appliquer**. Les règles viennent seulement de vos propres réponses, jamais de ce
    qu'un agent ou l'assistant a écrit, et rien ne s'applique avant que vous confirmiez.
  - **Ce qui pèse le plus.** Quand l'assistant décide pour un agent, ses sources sont classées, et la plus haute l'emporte
    en cas de désaccord : 1. vos règles permanentes (*Autonomie*) ; 2. les règles confirmées ici, celles du projet
    d'abord ; 3. ce qu'il sait de votre façon de décider ; 4. vos décisions passées sur des questions semblables ; 5. son
    propre jugement. Vos limites (L4 et L5 toujours à vous, le plafond par jour, les mots sensibles) gardent le dernier mot.
  - **Ne plus apprendre.** Coupe l'apprentissage pour un projet : ce qui en a été appris (ses règles comprises) est
    oublié, et plus rien de nouveau n'est gardé. **Oublier** efface une fois le savoir d'un projet.
  - Les résumés et dossiers sont rédigés à partir de ce que les agents ont écrit. L'assistant les lit comme des
    informations, jamais comme des instructions.
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

### L'orchestrateur cloud n'existe plus (1.45.0)
L'ancien pilote, une session Claude Code dans le cloud qui relayait vos agents vers Talixio, est désactivé. Le tableau
ci-dessus fait ce qu'il faisait, sans modèle entre les deux. À la mise à jour :
- son appairage est supprimé (il contenait un secret et un webhook), et son cockpit replié (carte d'état, À vous, Agents,
  Journal, Passages) disparaît, ainsi que le panneau d'orchestrateur de l'éditeur d'espace ;
- ce que vous avez répondu par son intermédiaire est conservé : chaque réponse est dans *Ce que je sais*, sous un projet
  au nom de l'agent, et guide toujours le tableau comme vos autres réponses (ce que l'assistant a répondu seul, non) ;
  les derniers échanges de chaque agent y sont gardés en note, marquée *Importé de l'orchestrateur cloud*. Le Journal
  et les Passages ne sont pas conservés. Une notification indique combien de décisions et de fils ont été gardés ;
- si une ancienne session d'orchestrateur tourne encore, on lui demande de s'arrêter et vous recevez une notification
  par jour tant que vous ne l'avez pas fermée. Connectez plutôt chaque machine avec **Connecter une machine**.

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
