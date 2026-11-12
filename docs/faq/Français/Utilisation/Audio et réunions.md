# Notes vocales, réunions et transcription

- **Note vocale** : le micro dans l'app, ou un message vocal sur Telegram/Signal. Elle est transcrite puis traitée.
- **Réunion** : micro → *Résumer la réunion* (gardez la page ouverte et le téléphone allumé), ou
  téléversez un enregistrement (tout audio de plus de 3 minutes compte comme une réunion).
  Vous recevez un résumé, les décisions, les actions (qui/quoi/quand) et les questions ouvertes,
  avec la proposition de créer les rappels et les tâches.
- Les transcriptions et résumés sont cherchables ensuite. Le compte rendu est aussi enregistré comme note, à côté de l'enregistrement.
- Telegram limite les fichiers à 20 Mo : téléversez les longs enregistrements dans l'app web.

## Pourquoi une transcription séparée ?
Claude ne prend pas l'audio en entrée : la transcription est une étape à part.

## Les choix de transcription (Réglages → Modèle et budget → Modèles → carte *Voix*)
Choisissez le modèle avec le bouton **V** d'une ligne du tableau : Whisper ou Voxtral chez un fournisseur européen, OpenAI, Gemini, ou
**Whisper (local)** sur ce serveur. La carte *Voix* contient aussi les réglages des réunions : le niveau des résumés et la durée
à partir de laquelle un enregistrement est une réunion. Les possibilités :
- **Gemini** : environ 0,15 $ par heure d'enregistrement, avec les noms des intervenants. Avec une
  clé gratuite, Google peut utiliser les données pour entraîner ses modèles ; avec la facturation
  activée, non. Indiquez-le dans Réglages (« Ma clé Gemini est sur une offre payante »).
- **OpenAI** : gpt-4o-transcribe.
- **Local (whisper.cpp)** : l'audio ne quitte pas le serveur, c'est gratuit, mais plus lent (environ la
  moitié de la durée de l'enregistrement sur 4 cœurs) et sans noms d'intervenants.
- **Fournisseurs européens** (OVHcloud, Scaleway, Infomaniak : Whisper ; Mistral : Voxtral) : l'audio reste en Europe.
- **Aucun modèle choisi** : automatique. Gemini si une clé est renseignée, sinon local si whisper.cpp est installé.
- Dans un espace **Confidentiel**, la transcription se fait toujours en local.

## Quel modèle Gemini ? (Réglages → Modèle et budget → Modèles → carte *Voix*)
Laissé vide, le choix est automatique, et c'est le bon réglage dans la plupart des cas.

**Gemini** (le nom commence par `gemini`) :

| Modèle | Pour quoi |
|---|---|
| `gemini-2.5-flash` | Le choix par défaut. Bonne qualité, noms des intervenants, peu cher. |
| `gemini-2.5-flash-lite` | Le moins cher. Suffit pour les notes vocales ; un peu moins fiable sur les longues réunions à plusieurs voix. |
| `gemini-2.5-pro` | Plus cher et plus lent, rarement meilleur pour une transcription. |
| `gemini-3-flash-preview`, … | Les modèles plus récents de votre clé marchent aussi (la liste est dans Réglages → Modèle et budget). |

Vide : le modèle « standard » de Gemini si Gemini est votre fournisseur, sinon `gemini-2.5-flash`.
Un nom de modèle whisper laissé dans ce champ est ignoré par Gemini.

**Local (whisper.cpp)** : le nom d'un fichier `ggml-<nom>.bin` dans `/opt/whisper.cpp/models`, ou son chemin.

| Modèle | Taille | Qualité en français | Vitesse |
|---|---|---|---|
| `large-v3-turbo-q5_0` | 0,55 Go | Très bonne | Le choix par défaut, le meilleur compromis |
| `large-v3-turbo` | 1,6 Go | Très bonne | Comme ci-dessus, plus de mémoire |
| `large-v3` | 3,1 Go | La meilleure | Environ 4 fois plus lent que turbo |
| `medium` (`medium-q5_0` : 0,5 Go) | 1,5 Go | Bonne | Plus lent que turbo |
| `small` (`small-q5_1` : 0,2 Go) | 0,5 Go | Correcte (noms propres et chiffres moins sûrs) | Rapide |
| `base`, `tiny` | 0,15 / 0,08 Go | Faible en français | Très rapide |

Le modèle doit être téléchargé sur le serveur : `sudo ./install.sh --with-whisper` (variable
`WHISPER_MODEL=small` pour en choisir un autre), ou sous Docker
`install-stack.sh --whisper-model small`. Les fichiers viennent de
huggingface.co/ggerganov/whisper.cpp. Prévoyez environ deux fois la taille du modèle en mémoire.
