# Notes vocales, réunions et transcription

- **Note vocale** : le micro dans l'app, ou un message vocal sur Telegram/Signal. Elle est transcrite puis traitée.
- **Réunion** : micro → *Résumer la réunion* (gardez la page ouverte et le téléphone allumé), ou
  téléversez un enregistrement (tout audio de plus de 3 minutes compte comme une réunion).
  Vous recevez un résumé, les décisions, les actions (qui/quoi/quand) et les questions ouvertes,
  avec la proposition de créer les rappels et les tâches.
- Les transcriptions et résumés sont cherchables ensuite.
- Telegram limite les fichiers à 20 Mo : téléversez les longs enregistrements dans l'app web.

## Pourquoi une transcription séparée ?
Claude ne prend pas l'audio en entrée : la transcription est une étape à part.

## Les choix de transcription (Réglages → Audio → Transcription)
- **Gemini** : environ 0,15 $ par heure d'enregistrement, avec les noms des intervenants. Avec une
  clé gratuite, Google peut utiliser les données pour entraîner ses modèles ; avec la facturation
  activée, non. Indiquez-le dans Réglages (« Ma clé Gemini est sur une offre payante »).
- **OpenAI** : gpt-4o-transcribe.
- **Local (whisper.cpp)** : l'audio ne quitte pas le serveur, c'est gratuit, mais plus lent (environ la
  moitié de la durée de l'enregistrement sur 4 cœurs) et sans noms d'intervenants.
- **auto** : Gemini si une clé est renseignée, sinon local si whisper.cpp est installé.
- Dans un espace **Confidentiel**, la transcription se fait toujours en local.
