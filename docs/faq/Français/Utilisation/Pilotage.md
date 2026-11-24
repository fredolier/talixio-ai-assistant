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
- **Deux volets** (depuis la 1.49.0). À gauche, la liste des sessions en trois groupes. **À vous** : une session qui
  demande une autorisation, ou qui a terminé son tour et que vous n'avez pas encore regardée. **Au travail** : une session
  occupée par votre demande. **Au calme** : celles que vous avez regardées, et celles qui sont terminées. Chaque carte
  montre le projet (son dossier), la machine, depuis quand elle n'a rien dit, et l'essentiel : la commande ou le fichier
  d'une demande d'autorisation, la fin de son dernier message (là où se trouve d'habitude sa question), ou ce qu'elle fait
  en ce moment. À droite, la session ouverte, ou l'outil ouvert par les boutons du haut (Nouvelle session, Connecter une
  machine, Autonomie, Ce que je sais ; ✕ le ferme). Sur téléphone, la liste prend l'écran, et ce que vous ouvrez la
  recouvre, avec ‹ pour revenir.
- **Autoriser / Refuser depuis la liste.** Une carte de demande d'autorisation porte ses propres boutons *Autoriser* et
  *Refuser* : inutile d'ouvrir la session.
- **Cliquez une carte** (ou Entrée dessus) pour lire le fil de la session à droite (vos demandes, ses réponses, mises en
  forme comme écrites, avec listes, titres et code ; les demandes d'autorisation ; ce que vous avez envoyé d'ici et quand
  c'est arrivé) et lui répondre dans la zone fixée en bas (sur ordinateur, Entrée envoie et Maj+Entrée va à la ligne). Une session ouverte
  depuis *À vous* y reste pendant que vous la lisez, au lieu de filer dans Au calme sous vos yeux.
  - **Répondre** : le texte part à la session. Une session qui a terminé son tour est réveillée et reprend le travail ; une
    session encore occupée le reçoit à la fin de son tour en cours. Le message après l'envoi dit lequel (*Envoyé* ou
    *En attente*).
    Dans le fil, votre réponse n'apparaît qu'une fois : *en attente*, puis *remis* dès que la session l'a reçue.
    **Pourquoi une session vous demande parfois de confirmer dans sa propre fenêtre.** Votre réponse arrive à Claude Code
    par son hook (le tableau ne peut pas taper dans sa fenêtre) : Claude Code la voit comme un message transmis par un
    hook, pas comme tapé par vous dans sa fenêtre. Pour une réponse ordinaire, cela ne change rien. Pour une étape
    sensible (pousser, modifier le code d'un autre projet, supprimer), les contrôles de sécurité de Claude Code peuvent ne
    pas la compter comme votre accord : la session vous demande alors de le taper dans sa fenêtre (ou dans la session
    concernée). Ce contrôle appartient à Claude Code ; le tableau ne le contourne pas et ne doit pas le contourner. Donnez
    ces accords dans la session elle-même.
    **Les sessions démarrées depuis le tableau font exception** (depuis la 1.50.0) : votre réponse y est leur prochaine
    demande, exactement comme la première, et compte donc comme la vôtre, comme si vous l'aviez tapée. Voir *Nouvelle
    session* ci-dessous.
  - **Autoriser / Refuser** une demande d'autorisation (la commande ou le fichier concerné est affiché). La session attend
    votre réponse ici **30 secondes** ; ensuite, la question habituelle s'affiche dans son terminal et vous y répondez là.
  - **Ce que fait la demande** (1.53.0) : le tableau lit la commande lui-même, sans modèle, et l'étiquette.
    *✓ Lecture seule : sans risque* quand chaque partie ne fait que lire (`ls`, `cat`, `head`, `grep`, `git status`,
    `git diff`…, même hors du projet). *⚠ À vérifier* avec la raison quand une partie supprime des fichiers, accède au
    réseau, pousse ou réécrit l'historique git, installe des paquets, déploie, modifie les autorisations de l'agent
    lui-même, ou lance un script ou du code que la carte ne montre pas (un script dans /tmp, `$(…)`, `sh -c`). Sans
    étiquette : elle modifie des choses dans son projet, à vous de juger. L'étiquette ne répond jamais à votre place.
  - **Toujours autoriser** (1.53.0) : quand Claude Code propose une règle pour la demande (par ex.
    `Bash(npx playwright test:*)`), le volet de la session l'affiche avec un bouton *Toujours autoriser*. Il autorise
    cette demande et fait enregistrer la règle par Claude Code dans le `.claude/settings.local.json` du projet (le vôtre,
    non versionné) : il ne demande plus pour cette commande. Seules les règles étroites sont proposées : jamais un outil
    entier, un interpréteur seul (`php`, `npx`, `sh`…), un lanceur nu (`npm run`), ce qui est étiqueté *À vérifier*, ni
    les réglages de l'agent lui-même. Pour retirer une règle, supprimez sa ligne de ce fichier.
  - **L'assistant répond aux autorisations pour vous** (1.55.0). *Autorisations*, en haut du tableau, choisit qui répond :
    - *L'assistant apprend de vous* (par défaut) : il autorise une demande qui ne fait que lire, et une commande que vous
      avez autorisée **3 fois** dans ce projet sans jamais la refuser. Aucune notification n'est envoyée pour ce qu'il
      autorise, et le fil indique *Autorisé par l'assistant* et pourquoi. Depuis la 1.56.0, il apprend aussi des demandes
      semblables, une fois que vous les avez autorisées 3 fois sous **au moins 2 formes différentes** :
      - un outil du projet : `./tools-workflow scenario:query` et `./tools-workflow check-gate` apprennent
        `./tools-workflow` (par chemin relatif ou absolu) ;
      - un script : `php tests/lib/a.php` et `php tests/lib/b.php` apprennent tous les scripts de `tests/lib` ;
      - `npm run build` et `npm run lint` apprennent `npm run` ;
      - les modifications de fichiers (`mv`, `cp`, `mkdir`, `touch`, `sed -i`…) forment une famille tant que tous les
        chemins restent dans le projet ;
      - les modifications de fichiers par l'éditeur : deux dossiers sous `packages/` apprennent `packages/`, deux dossiers
        de premier niveau apprennent tout le projet (jamais `.claude/` ni `.git/`).

      `git` (chaque sous-commande à part), `npx`, `make`, `docker` et les interpréteurs sans script ne s'élargissent
      jamais ; hors du projet, une commande est apprise par ses deux premiers mots. Dès que vous refusez une demande, ni
      elle ni rien de plus large n'est plus autorisé par l'assistant dans ce projet (ce qu'il avait appris plus étroit
      reste) ;
    - *L'assistant autorise la lecture seule* : seulement ce qui lit ;
    - *Toujours me demander* : comme avant la 1.55.0.

    Une demande étiquetée *À vérifier* (push, suppressions, réseau, installations, scripts, ses propres réglages) vous
    attend toujours.
  - **Marquer comme vu** range une session terminée dans Au calme ; **Oublier** la retire du tableau avec son fil (les
    deux dans le menu **⋯** en haut de la conversation).
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
  Une telle session tourne avec `claude -p`, qui s'arrête à la fin de son tour : la session vous attend alors sur le
  tableau (elle ne se termine pas). Votre réponse la reprend avec `claude -p --resume <session>`, lancé par l'écouteur :
  la réponse est sa demande suivante. Une réponse envoyée pendant qu'elle travaille part à la fin de son tour. Mettez
  l'écouteur à jour pour cela (*Connecter une machine*, étape 4, ou la commande d'installation à nouveau).
  Une réponse reste *en attente* tant que l'écouteur ne l'a pas vraiment relancée (1.52.1). Si le processus `claude -p`
  tourne encore après son tour (Claude Code peut attendre ses tâches d'arrière-plan), le fil le dit, et la réponse part
  dès qu'il se termine ; **Arrêter** le termine si vous préférez ne pas attendre. Si l'écouteur ne prend jamais la
  réponse (un écouteur antérieur à 1.50.0), le fil dit de le mettre à jour au bout de quelques minutes : la réponse
  attend, elle n'est pas perdue.
  Une telle session ne s'affiche plus *terminée* après chaque tour (1.52.2) : elle attend dans **À vous**, l'assistant
  la relit et ses options s'affichent en boutons. Elle se termine quand vous l'arrêtez, ou si son processus s'arrête en
  plein travail.
- **Reprendre et relire** (1.51.0) : une session Claude Code terminée (ou une session lancée du tableau puis arrêtée)
  affiche ce bouton à la place de la zone de réponse. La session vous attend de nouveau, l'assistant relit là où elle
  s'est arrêtée (sa tâche, les derniers échanges et son dernier message) et propose la suite, et votre réponse poursuit
  **cette même session** sur sa machine avec `claude -p --resume <session>`, avec tout son historique : inutile d'en
  lancer une nouvelle. L'écouteur de sa machine doit tourner. Une session tapée dans un terminal et arrêtée d'ici n'est
  pas reprise (elle y est peut-être encore ouverte).
- **Connecter une machine** (bouton en haut) : donnez un nom à la machine, cochez **Installer aussi l'écouteur** si
  vous voulez lancer des sessions d'ici, puis **Obtenir la commande d'installation** et lancez la ligne affichée dans un
  terminal sur cette machine : `curl -fsSL '<cet assistant>/api/agents/install?c=…' | sh`. Elle enregistre le script sous
  `~/.talixio/agent-hook.sh` (et l'écouteur à côté) et ajoute pour vous les hooks du tableau à `~/.claude/settings.json` :
  vos autres hooks et réglages restent, une copie du fichier est gardée avant sous `settings.json.talixio-backup`, et une
  nouvelle commande lancée plus tard remplace les hooks Talixio au lieu de les ajouter deux fois. Si le fichier n'est pas du
  JSON valide, il est laissé tel quel, et la commande le dit. La commande sert **une fois, pendant 15 minutes** : elle
  contient un code à usage unique, pas le jeton, on peut donc la coller sans risque ; prenez-en une nouvelle pour chaque
  machine. Il faut `sh`, `curl`, et `python3`, `node` ou `jq` pour fusionner les réglages (macOS, Linux, ou WSL sous
  Windows), et la machine doit joindre l'adresse de cet assistant. La prochaine session Claude Code sur cette machine
  apparaît sur le tableau. **Installer à la main** garde l'ancienne façon : téléchargez le script, enregistrez-le sous
  `~/.talixio/agent-hook.sh`, et fusionnez vous-même les hooks affichés dans `~/.claude/settings.json`. Le script contient
  l'adresse du tableau et un jeton : gardez-le sur vos propres machines. **Nouveau jeton** annule l'ancien (chaque machine a
  alors besoin d'une nouvelle commande d'installation).
- **Travailler sans demander** (1.54.0, coché par défaut dans *Connecter une machine*) : la commande d'installation active
  aussi le **mode auto** de Claude Code pour toutes les sessions de cette machine (`permissions.defaultMode` dans
  `~/.claude/settings.json`). C'est ainsi que fonctionnent les sessions cloud de l'assistant : au lieu de vous demander à
  chaque étape, un modèle vérifie chacune et refuse les risquées. Certaines étapes vous demandent toujours, quel que soit le
  mode, sur le tableau : `git push`, `git reset --hard`, `git clean`, `rm -rf`, `sudo`, `npm publish`, et les modifications
  des fichiers de réglages de Claude Code lui-même (la commande les ajoute comme règles *ask*, à côté des vôtres, qui
  restent). Un Claude Code trop ancien pour le mode auto reçoit *acceptEdits* à la place (il modifie les fichiers sans
  demander ; le reste demande toujours), et la commande le dit : mettez-le à jour et lancez une nouvelle commande. Pour
  revenir en arrière, décochez la case et lancez une nouvelle commande, puis remettez `defaultMode` à `default` dans ce
  fichier (une nouvelle commande ne le retire pas). Les scripts installés avant la version 1.38.0 : réinstallez, sinon
  un arrêt ne s'applique qu'à la prochaine demande d'autorisation ou fin de tour de la session.
- **L'écouteur** (facultatif, *Installer aussi l'écouteur* dans *Connecter une machine*, ou étape 4 d'*Installer à la
  main*) : laissez `sh ~/.talixio/agent-listen.sh` tourner sur cette machine (un terminal, tmux,
  ou un élément d'ouverture de session). Il demande du travail au tableau toutes les quelques secondes et lance
  `claude -p` dans le dossier, avec votre demande. Côté machine, il ne démarre que dans un dossier sous votre dossier
  personnel, sauf si vous en avez choisi d'autres dans **Dossiers où il peut lancer des sessions** à l'installation
  (plusieurs : séparez-les par `:`, par ex. `~:/app/projects`), ou si vous réglez `TALIXIO_ROOT` de la même façon en le
  lançant. Un dossier en dehors est refusé, et le refus donne la ligne exacte pour l'autoriser ; vérifiez-en un avant avec
  `sh ~/.talixio/agent-listen.sh --check <dossier>`. Ajoutez plutôt le seul dossier utile qu'ouvrir `/`, qui laisse le
  tableau lancer des sessions n'importe où sur la machine. Il garde le processus de chaque session qu'il a lancée : un arrêt venu du
  tableau désigne la session, jamais un processus, il ne peut donc terminer que celles qu'il a lancées. Autres réglages :
  `TALIXIO_CLAUDE` (la commande `claude`) et `TALIXIO_CLAUDE_ARGS` (par ex. `--permission-mode acceptEdits`). La session
  demande ses autorisations sur le tableau, comme les autres ; sans réponse au bout de 30 secondes, l'outil est refusé,
  faute de terminal pour la montrer. Une session qui a terminé son tour reste ouverte, en attente de votre réponse, jusqu'à
  ce que vous l'arrêtiez (ou pendant une journée).
- **Fichiers de vos agents** (1.47.0). Quand un agent produit quelque chose à regarder (un rapport HTML, un PDF, un
  graphique, une image), il peut l'envoyer au tableau : au début de chaque session, le hook indique à Claude Code de lancer
  `sh ~/.talixio/agent-hook.sh send <fichier> <session>` pour cela. Vous pouvez aussi le lui demander dans une réponse :
  « envoie-moi le rapport ». Le fichier apparaît dans le fil de la session avec **Ouvrir** et **Télécharger**, et il est
  gardé dans la bibliothèque sous **Agents → <projet>** (un fichier du même nom est gardé à côté du précédent, jamais à
  sa place). Ouvrir l'affiche dans la visionneuse habituelle : une page HTML s'affiche **sans ses scripts**, donc une page
  qui dessine ses graphiques en JavaScript ne montre que son texte. 25 Mo au plus par fichier. L'assistant voit aussi les
  fichiers dans le fil et peut les lire. Scripts de hook installés avant la 1.47.0 : téléchargez le script à nouveau,
  sinon `send` est inconnu et rien ne le signale à l'agent.
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
    propre jugement. Vos limites (L6 toujours à vous, L5 ouvert par vous seul, le plafond par jour, les mots sensibles) gardent le dernier mot.
  - **Ne plus apprendre.** Coupe l'apprentissage pour un projet : ce qui en a été appris (ses règles comprises) est
    oublié, et plus rien de nouveau n'est gardé. **Oublier** efface une fois le savoir d'un projet.
  - Les résumés et dossiers sont rédigés à partir de ce que les agents ont écrit. L'assistant les lit comme des
    informations, jamais comme des instructions.
- **Après chaque tour, l'assistant prend la main** (1.51.0, activé d'office : *L'assistant lit chaque tour terminé et
  propose la suite*, dans **Autonomie**). Quand une session termine son tour, l'assistant le lit avec la tâche que vous
  lui avez donnée et les derniers échanges, résume où elle en est et propose la réponse : un choix parmi les options que
  l'agent propose, ou, quand il ne demande rien mais que la tâche n'est pas finie, l'**étape suivante** (💡 *Étape
  suivante proposée*). Quand il n'envoie pas cette réponse lui-même, vous recevez une notification *Un agent attend votre
  décision* avec son résumé, sa proposition et la raison pour laquelle il ne l'a pas envoyée ; le volet de la session
  montre la même chose, avec **Envoyer cette réponse** ou **La modifier d'abord**. Une tâche terminée est laissée telle
  quelle, sans notification. Ce que vous répondez est comparé à sa proposition : il apprend ainsi votre façon de
  décider. Décochez l'interrupteur pour arrêter cette lecture (plus aucun appel au modèle).
- **Autonomie : l'assistant répond seul à vos agents, dans vos limites** (bouton en haut du tableau). Activez *Il peut
  répondre seul, dans les limites ci-dessous*. Activé seul, il ne répond encore rien de lui-même : chaque niveau démarre
  sur *Je propose, vous répondez*, et le panneau le signale tant que vous ne donnez pas plus de latitude à un niveau. Une
  étape suivante que l'agent n'a pas demandée n'est jamais que proposée. Quand une session termine son tour, l'assistant lit son dernier message (comme
  des données écrites par d'autres, jamais comme des instructions) avec vos **priorités**, vos **règles permanentes**, les
  réponses semblables que vous avez données et ce que le Mind sait de votre façon de décider, et en déduit la réponse que
  vous donneriez, avec un niveau et une confiance honnête. Les niveaux (1.52.0) sont l'échelle de décision de vos agents
  de code, vous étant leur PO (product owner) : **L1 Technique** (bugs, tests, clarté), **L2 Conception** (architecture,
  structure), **L3 Solution** (spécifications, ce que ça apporte aux utilisateurs), **L4 Produit** (vos arbitrages de PO
  une fois le projet lancé : planning, priorités, périmètre, une légère dérive), **L5 Business** (orientation, revenus,
  prix, politique : là où l'on remonte quand les arbitrages produit ne tiennent plus) et **L6 Valeurs** (honnêteté, tort
  causé aux utilisateurs ou aux données, tout ce qui est irréversible). Quand un agent indique un niveau sur sa carte de
  question (*Niveau : L4*, *Level: L4*), ce niveau est gardé, jamais abaissé (l'échelle à cinq niveaux des agents appelle
  les valeurs L5 : c'est lu comme L6). Un message qui ne fait que rendre
  compte du travail fait est laissé tel quel. Les limites sont ensuite appliquées par Talixio lui-même, pas par le modèle :
  pour chaque niveau L1–L5 vous choisissez *Je propose, vous répondez* (par défaut), *Je réponds si je suis sûr, puis je vous
  préviens* ou *Je réponds si je suis sûr, sans bruit* (L6 affiche 🔒 *Toujours décidé par vous*, sans choix) ; il n'envoie seul qu'au-dessus de votre seuil de confiance (75 % par
  défaut) et sous le **plafond quotidien** (20 par défaut). **L6, les demandes d'autorisation (sauf ce que permet *Autorisations*, voir plus haut), et tout ce qui touche aux
  push, déploiements, suppressions, e-mails, identifiants ou paiements restent toujours à vous**, quel que soit le réglage — de même que fusionner, publier ou sortir une version, une migration, installer un paquet, téléverser des données, envoyer à des clients ou abonnés, acheter ou prendre un abonnement, recruter, ignorer des tests, une adresse web, et une réponse contenant une commande que l'agent n'a pas proposée lui-même : elles restent des suggestions même quand le modèle les juge routinières.
  Vos **priorités** et **règles permanentes** (une par ligne) se tapent ou se dictent avec 🎙️ ; une règle que vous lui
  dites dans le chat est ajoutée aussi. Une réponse qu'il envoie part vers la session comme la vôtre, marquée *Assistant* dans le fil ; dans le volet de la
  session il demande **Était-ce la bonne décision ? Oui / Non, c'était faux**. Sinon sa carte affiche 💡 *Réponse suggérée*,
  et le volet de la session montre la réponse, son niveau, sa confiance et pourquoi elle n'a pas été envoyée, avec
  **Envoyer cette réponse** ou **La modifier d'abord**. Chaque réponse est évaluée : par rapport à ce que vous répondez pour
  ce tour (depuis le tableau, ou tapé dans le terminal de la session), ou par votre Oui / Non. Une mauvaise réponse envoyée
  fait redescendre ce niveau aussitôt.
- **Il gagne sa propre latitude** (1.52.0) : une fois qu'il peut répondre seul, L1 à L3 montent d'eux-mêmes quand ses
  réponses rejoignent assez souvent les vôtres : au moins 85 % (au choix 80, 85, 90 ou 95 %) sur les 10 dernières
  réponses fait passer *Je propose* à *Je réponds si je suis sûr, puis je vous préviens*, puis sur les 20 suivantes à
  *sans bruit*. Vous recevez une notification *Flotte : autonomie augmentée*, et une erreur le fait redescendre aussitôt.
  Cochez *L4 Produit aussi* pour qu'il reprenne de la même façon vos arbitrages de PO. L5 Business ne reçoit plus de
  latitude que de vous (il le *suggère* après assez de bonnes réponses) ; L6 Valeurs jamais. Décochez *Il se donne plus
  de latitude* pour garder chaque changement en main.
- **Rien n'attend oublié** (1.52.0) : une proposition que vous n'avez pas tranchée revient en *Toujours en attente de
  votre décision*, une heure après, 4 heures plus tard, puis chaque jour, tant que sa session attend à ce tour.
- **Options en un clic** (1.52.0) : quand le message d'un agent liste des options (*A) …*, *B) …*, ou *1.*, *2.* …), le
  volet de la session les affiche en boutons sous *Répondre avec une de ses options :*. Un clic envoie cette option. Il regarde dans les secondes qui suivent la fin d'un tour (et toutes les 15
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
