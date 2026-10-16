# Les espaces

Un espace est une conversation séparée pour un sujet : un client, la maison, la santé…

- On change d'espace en haut de la conversation.
- Chaque espace a **son dossier** dans la bibliothèque (cherché en premier ; les pièces jointes y sont
  rangées), **ses consignes**, ses notes, un modèle par défaut, une couleur et un **tableau de tâches**.
- Un espace ne voit pas les conversations des autres, sauf si vous le demandez.
- Telegram, Signal et l'e-mail écrivent dans *Général* ; `/space Maison` les fait passer dans un autre espace.
- Chaque espace a un **niveau de confidentialité** (Interne, Confidentiel, Secret) : le bouclier à côté
  de son nom. Voir *Confidentialité / Niveaux de confidentialité*.
- Avec un compte IA par espace, un espace Confidentiel ou Secret est cloisonné : les modèles des
  autres espaces ne le lisent pas, et rien n'en sort vers un endroit moins protégé.
- Supprimer un espace garde ses documents dans la bibliothèque (voir plus bas).

## Archiver, restaurer, historique

- **Archiver** un espace (fiche de l'espace → *Archiver*, à confirmer d'un second clic) le retire de la liste
  sans rien effacer : conversation, notes, tâches et dossier restent. Les canaux qui écrivaient dedans
  reviennent dans *Général*.
- Les espaces archivés sont en bas du sélecteur d'espaces, sous **Archivés (n)**. En ouvrir un affiche un
  bandeau avec **Restaurer** ; la fiche de l'espace a aussi le bouton *Restaurer*.
- La fiche de l'espace montre son **historique** : création, renommage, archivage, restauration,
  changement de confidentialité ou de dossier, avec la date et l'origine (dans l'appli ou par l'assistant).
- On peut aussi demander à l'assistant : « liste mes espaces », « que s'est-il passé sur l'espace Maison ? »,
  « restaure l'espace Maison ». Il ne peut pas archiver un espace lui-même.
- L'historique existe depuis la version 0.16.1 : un archivage plus ancien n'y figure pas.
- **Supprimer** un espace efface sa conversation ; ses documents restent dans la bibliothèque et ses
  tâches passent dans *Général*.

## Déplacer un espace vers un autre assistant

Quand vous avez plusieurs assistants (voir *Administration / Plusieurs personnes sur un serveur*), un
espace peut passer de l'un à l'autre avec tout ce qui lui appartient.

- Fiche de l'espace → **Déplacer…**, choisissez l'assistant, **Déplacer**. Votre navigateur porte
  l'espace directement à l'autre assistant, puis il est retiré d'ici.
- Ce qui part avec lui : la conversation et ses résumés, les consignes et les notes, le modèle, la
  couleur et le niveau de confidentialité, les tâches (avec leurs dépendances, leur historique et le
  temps passé), les routines et les travaux, ce que l'assistant a appris à son sujet, et son
  **dossier de la bibliothèque**. Les documents d'ailleurs dans la bibliothèque vers lesquels pointent la
  conversation ou les tâches sont copiés aussi.
- Ce qui reste : le compte IA de l'espace (les clés ne voyagent jamais ; l'autre assistant utilise ses
  propres réglages, à changer là-bas si besoin), les liens de partage, les notifications.
- Si l'autre assistant a déjà un espace du même nom, du même code de tâches ou du même dossier,
  l'espace déplacé en reçoit un libre (« Acme 2 », un nouveau code, « /Clients/Acme (2) »). Ses tâches
  sont renumérotées ; leurs anciens codes les retrouvent toujours.
- Les tâches affichées dans votre agenda en sortent ici, et apparaissent dans l'agenda de l'autre
  assistant s'il en a un.
- Ici, le dossier part à la corbeille (restaurable pendant 30 jours), et les applis de discussion qui
  écrivaient dans l'espace reviennent dans *Général*.
- Pas pendant qu'un travail de l'espace tourne : attendez qu'il se termine.
- Vers un autre serveur, ou si le transfert direct échoue : **Télécharger le paquet** (un .zip), puis dans
  l'autre assistant ouvrez le menu des espaces → **Importer un espace…**, et revenez cliquer sur
  **Le retirer d'ici**. Le paquet contient tout l'espace : rangez-le en lieu sûr ou supprimez-le.
- L'historique de l'espace indique « déplacé depuis … ».
