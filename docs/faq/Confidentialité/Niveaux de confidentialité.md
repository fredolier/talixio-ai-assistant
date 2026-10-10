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

## Sous chaque réponse
Des pastilles disent où la réponse est allée (« Anthropic · 3 documents », « 3 en local », « 1 bloqué ») ;
touchez-les pour le détail. Elles s'affichent aussi en direct pendant que l'assistant écrit.
