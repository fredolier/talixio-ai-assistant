# Apps

**Apps** (dans le menu) regroupe de petites applications que l'assistant construit pour vous : un graphique du cours
d'une action, un tableau de bord de vos tâches, un suivi de dépenses ou d'habitudes, un tableau d'un fichier CSV de
la bibliothèque…

## Comment en obtenir une

- Demandez dans la discussion : *« Fais-moi une app qui affiche le cours de l'action Apple sur le dernier mois. »*
- Pas encore d'idée ? Demandez : **« Avec ce que tu sais de moi, peux-tu me proposer plusieurs apps que tu pourrais
  développer et ajouter au menu Apps ? »** L'assistant s'appuie sur votre mémoire, votre bibliothèque, vos tâches et
  vos routines, et en propose quelques-unes, chacune avec ce qu'elle afficherait et d'où viennent ses données.
  Choisissez-en une, il la construit.
- Ou partez d'un exemple sur la page Apps (*Cours de bourse*, *Les tâches en un coup d'œil*).
- Pour modifier une app : ouvrez-la → *Modifier dans la discussion*, et dites ce que vous voulez changer.
  Si elle plante, l'app affiche l'erreur en haut : copiez-la dans la discussion.
- Créer ou modifier une app, c'est du code : l'assistant utilise pour cela votre modèle **deep** (si vous en avez
  réglé un), car les petits modèles s'y trompent souvent.

## Ce qu'une app peut afficher

Chiffres clés (avec une flèche d'évolution et une petite courbe), courbes et barres (empilées aussi), camemberts et
anneaux, jauges, barres classées, progression vers un objectif, calendrier de chaleur, frise chronologique, pastilles,
onglets et tableaux. Les tableaux se téléchargent en CSV et les graphiques en PNG. Les liens d'une app s'ouvrent dans Talixio (un fichier de la bibliothèque, les
tâches…) ; une adresse web vous est demandée d'abord.

Sources qu'une app peut déclarer :

| Source | Ce qu'elle lit |
|---|---|
| Web | une adresse fixe (seuls quelques mots peuvent changer, comme un symbole boursier) |
| Fichier / Dossier | un fichier de la bibliothèque, ou les fichiers d'un dossier (pratique avec une routine qui y range ses résultats) |
| Recherche | une recherche dans la bibliothèque |
| Tâches, Agenda, Contacts | ceux de Talixio |
| Script | un de vos scripts enregistrés |
| Connecteur | un outil d'un connecteur, **seulement si vous l'avez marqué *lecture seule*** dans Réglages → Connecteurs |

## Pourquoi c'est sûr

Une app n'est pas du code libre. L'assistant l'écrit avec un petit kit (graphiques, tableaux, chiffres, un petit
stockage à elle), et :

- elle tourne dans un cadre isolé : pas de réseau, pas de cookies, aucun accès au reste de l'app ni à votre session ;
- elle ne reçoit des données que de **ses sources déclarées** : une adresse web fixe, un fichier ou un dossier de la
  bibliothèque, une recherche dans la bibliothèque, les tâches de son espace, votre agenda, vos contacts, un de vos
  scripts enregistrés, ou un outil de connecteur que vous avez marqué *lecture seule*. Rien d'autre ;
- **toute app créée ou modifiée par l'assistant attend votre validation.** Ouvrez-la : vous voyez les sources qu'elle
  lit (et le code si vous voulez), puis *Approuver*. D'ici là, elle ne reçoit aucune donnée ;
- une app appartient à un espace et suit son niveau de confidentialité : les apps d'un espace Secret ne sont pas
  montrées ailleurs et ne lisent jamais le web. Une app d'un espace Confidentiel, ou qui lit un fichier ou un
  dossier Confidentiel, n'atteint pas non plus le web ni un connecteur : ce qu'elle lit reste dans l'app. Les
  arguments d'un script ne peuvent pas commencer par `-`.

Un outil de connecteur peut agir (envoyer, supprimer, payer), et ce que le connecteur dit de lui-même n'est pas pris
pour argent comptant : une app ne peut appeler qu'un outil que *vous* avez marqué lecture seule, avec les arguments
fixés au moment de votre validation.

## Partager une app

Ouvrez l'app et choisissez **Partager** :

- **Copier vers cet assistant** envoie une copie à un autre de vos assistants (ceux que vous rejoignez depuis le
  menu). Votre page l'envoie directement, comme un espace déplacé.
- **La télécharger en fichier** enregistre un fichier `.talixio-app.json` que vous pouvez donner à n'importe qui. Il
  l'ajoute avec **Ajouter une app depuis un fichier** dans ses Apps.

La copie contient le code de l'app et ce qu'elle lit, jamais ses données, son état enregistré, une clé ou la valeur
d'un en-tête. Un fichier de la bibliothèque qu'elle lit est cherché au même endroit dans l'autre bibliothèque ;
s'il n'y est pas, cette source est laissée de côté et l'app l'indique. Une app d'un espace Secret ne peut pas être
partagée.

De l'autre côté, l'app attend d'être approuvée, comme une app écrite par l'assistant. Sa page liste ce qu'il lui
faut pour fonctionner : un connecteur à installer (**L'installer** utilise le dépôt GitHub ou l'adresse en ligne
d'origine ; vous vous connectez ensuite ou remplissez ses réglages avec votre propre compte) et les outils du
connecteur à laisser s'exécuter sans demander. Un connecteur ajouté depuis un `.zip` ou une commande s'installe à
la main, sous le même nom.

## Ce qu'une app ne peut pas faire

Écrire quoi que ce soit (tâches, fichiers, mails, connecteurs), utiliser le réseau librement, exécuter du code sur le
serveur, voir vos clés ou réglages, lire un autre espace, ou être partagée en direct : une app partagée est une copie qui tourne avec les données de l'autre côté. C'est voulu. Les évolutions
prévues sont dans la feuille de route du projet.

Les données web et des connecteurs sont mises en cache (30 minutes par défaut) et limitées à 60 lectures par heure et par app.
Supprimez une app depuis sa page.
