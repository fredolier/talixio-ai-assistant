# Pilotage

**Pilotage** (dernière entrée du menu) est le poste de pilotage du directeur : il montre ce qui tourne pour vous au-delà de la
conversation. Il a quatre onglets.

## Base de connaissance
Un coup d'œil sur la bibliothèque de l'espace courant : ce qu'elle contient et son état. La recherche et le rangement restent
dans *Fichiers*.

## Votre pilote autonome
Le pilote est un orchestrateur (une session Claude Code) qui supervise des agents sur vos machines et fait ses rapports à Talixio.
- La **carte d'état** dit s'il est connecté, quand il a fait son dernier rapport et combien d'agents il voit sur combien de
  machines. **Reconnecter…** et **Déconnecter** y sont. Le jumelage se fait soit par un webhook que crée l'orchestrateur, soit,
  pour une simple session locale, en *interrogeant* Talixio à intervalle régulier.
- **Parler au pilote** lui envoie une consigne, écrite ou dictée. Elle passe les mêmes contrôles de confidentialité que toute
  autre consigne envoyée.
- **Les agents** : chaque agent avec son état (actif, occupé, inactif, terminé, en erreur), sa tâche, son ancienneté et sa
  machine ; **Discuter** ouvre un fil à deux sens avec cet agent. Ce que répondent l'orchestrateur et les agents est affiché,
  jamais exécuté.
- **Quand un agent est bloqué**, le roster le met en tête avec la question elle-même : une **décision** (répondez avec un de ses
  boutons d'option, ou ouvrez *Discuter*), une **validation** (un clic que vous seul pouvez donner, dans cette session : ouvrez-la ;
  rien ne peut se faire d'ici) ou une **instruction**. Pour cela, le pilote recopie, à chaque point, la question que chaque session
  bloquée écrit dans `./.claude/talixio-code/inbox/QUESTION-<session>.md` (type, question, options, coût d'un mauvais choix). Une
  nouvelle question compte comme une nouveauté : elle apparaît dans les rapports et la pastille, même lors d'un point silencieux. Un
  pilote jumelé avant cette version a besoin de la convention une fois : reconnectez-le, ou envoyez-lui les consignes du message de jumelage.
- **Rapports de la flotte** : comment les rapports du pilote vous parviennent (voir *Canaux et notifications*) : une pastille dans
  l'app par défaut, la carte **Rapports récents**, et en option push + e-mail.

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
