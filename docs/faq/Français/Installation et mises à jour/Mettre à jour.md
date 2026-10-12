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
- L'onglet indique s'il existe une version plus récente sur la branche, et ce qui change. En fin de mise
  à jour, la page d'administration et son agent passent aussi sur la nouvelle version (depuis 0.19.6) :
  rechargez la page quelques secondes après.
- L'image est faite en deux étages. Les **dépendances** (paquets système, Chromium, LibreOffice,
  whisper.cpp, signal-cli…) sont une image à part, `talixio-base`, reconstruite seulement quand
  `deploy/docker/base.Dockerfile` change (plusieurs minutes). L'**application** se pose dessus : une mise
  à jour ordinaire ne recopie que le code et prend quelques secondes, plus le redémarrage des assistants.
- Pour reconstruire aussi les dépendances (dernières mises à jour de sécurité des paquets, ou un
  téléchargement raté) : `sudo deploy/docker/install-stack.sh --rebuild-base`.
- En ligne de commande : `git pull`, puis `sudo deploy/docker/install-stack.sh` (sans autre option :
  les réglages de la pile sont gardés dans `deploy/docker/.env`).
- Dépôt GitHub privé : créez sur GitHub un jeton à lecture seule limité au dépôt (*fine-grained token*,
  droit *Contents : Read*), ajoutez la ligne `GIT_TOKEN=le_jeton` dans `deploy/docker/.env`, puis relancez
  `sudo deploy/docker/install-stack.sh`. Pour couper l'accès, révoquez le jeton sur GitHub.

## Ce qui a changé
Le dossier **/FAQ/English/What's new** contient une page par version (en anglais, tirée du journal des
modifications). La version installée est en bas du menu
et dans Réglages → *Mises à jour*.

## L'app reste figée après une mise à jour ?
Rechargez la page (ou fermez et rouvrez l'app). Depuis la 0.12.1, les scripts sont servis à une adresse
qui porte la version : une page ne peut plus tourner avec des scripts d'une autre version.

## Que deviennent les jobs en cours ?
La mise à jour attend d'abord jusqu'à 10 minutes que les jobs en arrière-plan se terminent, sans en
lancer de nouveaux. Un job encore en cours au redémarrage de l'app (ou après un redémarrage du
serveur) est relancé une fois : un job de recherche reprend à partir des étapes déjà faites. Vous
recevez un message listant les jobs interrompus. Les jobs en attente et les routines s'exécutent
dès que l'app est revenue ; une réponse en cours d'écriture dans le chat est coupée : reposez la question.
