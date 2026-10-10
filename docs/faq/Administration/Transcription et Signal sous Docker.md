# Transcription locale et Signal sous Docker

## Transcription (whisper.cpp)
- L'image contient whisper.cpp. Le modèle est dans le volume `talixio-whisper`, partagé en lecture
  seule par tous les assistants ; `install-stack.sh` le remplit (`--whisper-model large-v3-turbo-q5_0`
  par défaut, `small`, `medium`, ou `none`). Un modèle déjà installé sur le serveur est copié.
- Le modèle large a besoin d'environ 1 Go pendant la transcription : donnez 2 Go à la personne
  (page d'administration → limites), ou choisissez `small`.
- Une réunion d'une heure prend 30 à 60 minutes sur 2 processeurs.

## Signal
signal-cli est dans l'image (serveurs x86_64). Réglages → Signal : le numéro de l'assistant et le vôtre, puis :
```bash
docker exec -it talixio-fred talixio signal register
docker exec -it talixio-fred talixio signal verify 123456
docker exec -it talixio-fred talixio signal updateProfile --given-name Talixio
docker restart talixio-fred
```

## Si l'image a été construite sans eux
Si un téléchargement a échoué pendant la construction, l'image est faite sans cette partie et le journal
de construction le dit : relancez `sudo deploy/docker/install-stack.sh --rebuild-base`.
