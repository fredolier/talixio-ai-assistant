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
  l'espace. Le rapport d'un job suit la même règle.
- **Apprentissage** : ce que l'assistant apprend sur vous dans un espace Confidentiel ou Secret
  reste dans cet espace.
- **Autres espaces** : les routines et jobs d'un espace que le modèle n'a pas le droit de lire ne
  sont ni listés ni modifiables depuis ailleurs ; le dossier d'un espace Secret n'est pas montré
  en dehors de lui.
- **Recherche web** : la recherche web propre au fournisseur est coupée dès que le travail dépasse
  Interne ; celle de Talixio demande votre validation (Confidentiel) ou est refusée (Secret).
- **Sites web de confiance** : les sites listés dans Réglages → Navigateur et validations → Sites web de
  confiance (sous-domaines inclus) s'ouvrent sans demander, y compris depuis un espace Confidentiel, une
  routine ou après la lecture d'un e-mail. Le bouton « Toujours autoriser » d'une validation ajoute le site
  pour vous ; pour une recherche web, il active « Recherche web depuis les espaces Confidentiels sans
  demander ». Les espaces Secrets n'accèdent jamais au web. Tant que des validations attendent, vous recevez
  une seule notification, pas un e-mail par demande.

## Sous chaque réponse
Des pastilles disent où la réponse est allée (« Anthropic · 3 documents », « 3 en local », « 1 bloqué ») ;
touchez-les pour le détail. Elles s'affichent aussi en direct pendant que l'assistant écrit.
