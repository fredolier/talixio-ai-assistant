# Mettre à jour Talixio

## Installation simple
- **Depuis l'app** : Réglages → *Mises à jour* montre les nouvelles versions (vérifiées toutes les 6 h),
  avec *Mettre à jour maintenant* (sauvegarde, `git pull`, réinstallation, redémarrage) et
  *Revenir à la version précédente*. La mise à jour automatique s'active dans les Réglages.
- **À la main** : dans le dossier du dépôt, `git pull && sudo ./install.sh` (réglages et données conservés ;
  ajoutez les mêmes options `--with-…` que la première fois si vous voulez les garder à jour).
- Les apps ouvertes affichent « Une nouvelle version est prête – Recharger ».

## Serveur à plusieurs (Docker)
- Page d'administration → **Mises à jour** : l'image est reconstruite depuis le dépôt, puis chaque
  assistant passe dessus l'un après l'autre. Si l'un ne démarre pas, il revient à la version précédente
  et la mise à jour s'arrête. *Revenir à la version précédente* annule la dernière.
- L'image est faite en deux étages. Les **dépendances** (paquets système, Chromium, LibreOffice,
  whisper.cpp, signal-cli…) sont une image à part, `talixio-base`, reconstruite seulement quand
  `deploy/docker/base.Dockerfile` change (plusieurs minutes). L'**application** se pose dessus : une mise
  à jour ordinaire ne recopie que le code et prend quelques secondes, plus le redémarrage des assistants.
- Pour reconstruire aussi les dépendances (dernières mises à jour de sécurité des paquets, ou un
  téléchargement raté) : `sudo deploy/docker/install-stack.sh --rebuild-base`.
- En ligne de commande : `git pull`, puis `sudo deploy/docker/install-stack.sh` (sans autre option :
  les réglages de la pile sont gardés dans `deploy/docker/.env`).
- Dépôt GitHub privé : `GIT_URL` dans `.env` doit contenir un jeton en lecture seule.

## Ce qui a changé
Le dossier **/FAQ/Nouveautés** contient une page par version. La version installée est en bas du menu
et dans Réglages → *Mises à jour*.

## L'app reste figée après une mise à jour ?
Rechargez la page (ou fermez et rouvrez l'app). Depuis la 0.12.1, les scripts sont servis à une adresse
qui porte la version : une page ne peut plus tourner avec des scripts d'une autre version.
