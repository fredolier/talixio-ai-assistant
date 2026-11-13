# Les niveaux de confidentialité

Chaque espace a un niveau, réglé avec le bouclier à côté de son nom. Les règles sont appliquées au
moment où une donnée va sortir du serveur : l'assistant ne peut pas les contourner, même si un
e-mail ou un document le lui demande.

## Interne
Comportement normal : les services configurés dans les Réglages sont utilisés.

## Confidentiel
- Seul un modèle sous contrat professionnel lit le contenu : API Claude, API OpenAI, ou Gemini si
  votre clé est sur une offre payante.
- Web, navigateur et connecteurs demandent votre validation à chaque fois.
- Transcription et indexation (recherche par le sens) uniquement en local.
- Notifications sans contenu.
- Liens de partage : mot de passe obligatoire, 30 jours maximum.
- L'espace n'est pas accessible depuis Telegram.

## Secret
Rien ne quitte le serveur : pas de modèle extérieur, pas de web, pas de partage. Son contenu n'est
jamais cherché depuis un autre espace. La bibliothèque et les tâches fonctionnent ; pour discuter,
il faut un modèle installé sur le serveur.

## La « contamination »
Si une question posée dans Général lit un document confidentiel, la suite de la réponse suit les règles Confidentiel.

## Ce qui reste à son niveau
- **Lecture** : un modèle qui n'a pas droit à un niveau ne lit pas les fichiers, notes ou mémoire
  de ce niveau, même depuis Général (vous les retrouvez vous-même avec la recherche).
- **Écriture** : ce qui vient d'un espace Confidentiel ou Secret n'est enregistré que dans un
  dossier au moins aussi protégé (le dossier de son espace). Il n'est pas copié dans la mémoire
  commune, les contacts ou les compétences, que tous les espaces lisent ; il va dans les notes de
  l'espace. Le rapport d'une mission suit la même règle.
- **Apprentissage** : ce que l'assistant apprend sur vous dans un espace Confidentiel ou Secret
  reste dans cet espace.
- **Autres espaces** : les routines, missions et tâches du tableau d'un espace que le modèle n'a pas le droit de lire ne
  sont ni listés ni modifiables depuis ailleurs ; le dossier d'un espace Secret n'est pas montré
  en dehors de lui.
- **Recherche web** : la recherche web propre au fournisseur est coupée dès que le travail dépasse
  Interne ; celle de Talixio demande votre validation (Confidentiel) ou est refusée (Secret).
- **Contenu écrit par d'autres** : dès qu'une réponse a lu un e-mail, une page web (y compris la recherche web
  propre au modèle), un document que vous avez importé ou joint, votre agenda, le résultat d'un connecteur ou un
  message d'un autre assistant ou d'un agent, un texte glissé dedans ne peut pas agir seul : dès lors, seule la lecture
  se fait librement (recherche, lecture de fichiers, du mail, de l'agenda, du tableau ; préparation d'un brouillon de
  mail), et tout ce qui modifie ou envoie quelque chose (mémoire, compétences, routines, tâches, contacts, notes, missions,
  flotte de code, messages à d'autres assistants, commandes shell, ouverture d'une adresse web absente de ce contenu)
  attend votre validation. Dans le navigateur, saisir, cliquer et choisir se font sans demander sur un site que vous
  avez nommé dans votre demande ou mis en confiance, sauf si la réponse a lu vos propres données (elles pourraient être
  saisies dans la page) ; sinon vous validez cette action sur le moment. Vos propres notes, écrites et modifiées en dernier par vous dans l'app, ne comptent pas.
  Une routine que vous avez autorisée à faire l'une de ces choses (*Toujours autoriser pour la routine*, voir *Tâches et
  routines*) la fait sans demander.
  Une demande tient en une ligne (l'outil, les valeurs courtes, et la longueur d'un long texte comme le contenu
  d'une note) ; *Voir le détail* dessous montre tout ce qu'elle écrirait, et un avis par e-mail ou messagerie
  l'inclut. Les résultats validés à la suite vont dans un seul message du chat (« Fait : 9 actions validées »,
  chaque résultat replié dessous), un par routine ; un message échangé entre-temps en commence un nouveau.
  Un résultat validé qui venait d'un tel contenu garde la règle tant qu'il est dans la conversation récente, et une
  compétence apprise d'un tel travail attend elle aussi votre validation.
  Une mission, qu'elle soit lancée, menée par le directeur ou reprise, transmet ce qu'elle a lu à son rapport : le tour qui le
  relaie (et les suivants, tant que le rapport est dans la conversation récente) demande de la même façon, et une mission
  qui a lu un fichier Confidentiel rapporte au niveau Confidentiel (pas de recherche web, rien copié dans la mémoire
  commune).
- **Sites web de confiance** : les sites listés dans Réglages → Navigateur et validations → Sites web de
  confiance (sous-domaines inclus) s'ouvrent sans demander, y compris depuis un espace Confidentiel, une
  routine ou après la lecture d'un e-mail. Le bouton « Toujours autoriser » d'une validation ajoute le site
  pour vous ; pour une recherche web, il active « Recherche web depuis les espaces Confidentiels sans
  demander ». Les espaces Secrets n'accèdent jamais au web. La liste se gère comme un tableau de pastilles : chaque
  site a sa croix ✕, on en ajoute un par son nom ou son adresse complète (`https://www.lemonde.fr/page` devient
  `lemonde.fr`), on cherche dans la liste, on l'**importe / exporte** en texte (un site par ligne) ; ce qui n'est
  pas un site web est refusé.
- **Sites web bloqués** (même page) : sites qui ne sont jamais ouverts, quel que soit l'espace et même si vous
  validiez — l'assistant est prévenu que le site est bloqué. Sous-domaines inclus ; un site bloqué l'emporte sur
  un site de confiance.
  Tant que des validations attendent, vous recevez
  une seule notification, pas un e-mail par demande.

## Sous chaque réponse
Des pastilles disent où la réponse est allée (« Anthropic · 3 documents », « 3 en local », « 1 bloqué ») ;
touchez-les pour le détail. Elles s'affichent aussi en direct pendant que l'assistant écrit.
