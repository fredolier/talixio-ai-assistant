# Le catalogue (plusieurs personnes sur un serveur)

Quand plusieurs personnes partagent un serveur, la page d'administration a un onglet **Catalogue** : les apps et les
connecteurs que tout le monde sur le serveur peut installer. Chaque assistant voit le catalogue dans **Apps**
(*Depuis le catalogue*) et dans **Réglages → Connecteurs**.

## Envoyer quelque chose au catalogue

- **Une app** : ouvrez-la et choisissez **Proposer au catalogue** (aussi sous **Partager**), avec un mot pour l'administrateur
  si vous voulez. Ce qui part est le même paquet qu'une app partagée : son code, ce qu'elle lit et comment installer
  ses connecteurs, jamais ses données, son état enregistré ni de clé.
- **Un connecteur** : dans Réglages → Connecteurs, choisissez-le sous *Depuis le catalogue* et appuyez sur **Envoyer
  pour validation**. Seule la façon de l'installer part (son dépôt GitHub, ou son adresse https et les noms
  d'en-têtes), jamais vos clés ni les valeurs des en-têtes. Un connecteur installé depuis un `.zip` ou une commande ne
  peut pas aller au catalogue.

Vous voyez où il en est : en attente de validation, dans le catalogue, ou refusé (avec le mot de l'administrateur).
Renvoyer une app modifiée envoie une nouvelle version ; une fois validée, elle remplace celle du catalogue.

## Valider (administrateur)

Dans **Catalogue**, chaque élément en attente montre qui l'a envoyé, son mot et tout ce qu'il contient (code, sources,
connecteurs) avant que vous le **validiez** ou le **refusiez**, avec un mot facultatif renvoyé. Rien n'arrive dans le
catalogue avant votre accord. Vous pouvez aussi publier vous-même une app depuis un fichier `.talixio-app.json` ou un
connecteur depuis son dépôt GitHub ou son adresse, et retirer n'importe quel élément : ceux qui l'ont installé gardent
leur copie.

## Installer depuis le catalogue

- **Apps → Depuis le catalogue** : **Installer** ajoute l'app à l'espace courant. Elle attend encore votre accord avant
  de lire des données, comme toute app. Quand une nouvelle version est publiée, **Mettre à jour** l'apporte (elle
  attend de nouveau votre accord) ; **Désinstaller** la retire avec son état enregistré.
- **Réglages → Connecteurs → Depuis le catalogue** : **Installer** ajoute le connecteur ; vous vous connectez ensuite
  ou remplissez ses réglages avec votre propre compte. **Désinstaller** le retire.

Les apps d'un espace Secret n'en sortent jamais : elles ne peuvent pas être envoyées au catalogue.

## Je ne vois pas le catalogue

Le catalogue a besoin d'un serveur géré depuis la page d'administration. Si la section du catalogue indique qu'il n'est pas en place, l'assistant a été créé par une version antérieure au catalogue : mettez à jour le serveur à nouveau, ou choisissez **Reconstruire le conteneur** pour cet assistant dans la page d'administration. Depuis la 0.28.73, l'agent corrige aussi cela tout seul à son démarrage.
