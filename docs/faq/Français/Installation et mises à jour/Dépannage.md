# Dépannage

## Premier réflexe
Réglages → *Lancer les vérifications* (ou `sudo talixio doctor`, sous Docker
`docker exec talixio-<vous> talixio doctor`) : chaque ligne en rouge dit ce qui ne va pas.

## « Aucun modèle d'IA n'est encore configuré »
L'assistant n'a ni clé d'API ni connexion Claude. Un encart dans la conversation propose
*Se connecter avec Claude* ou *Utiliser une clé d'API*. Après un passage à Docker, ce peut être l'import
des réglages qui a échoué : voir *Administration / Passer en multi-utilisateur*.

## Mes documents n'apparaissent plus ou ne s'ouvrent plus après un déménagement
Depuis la 0.16, les chemins des fichiers sont remis à jour automatiquement au démarrage quand le dossier
de données a changé (installation simple → Docker, transfert). Si un document reste introuvable, vérifiez
qu'il est bien dans le volume (`docker exec talixio-<vous> ls /data/files`).

## « Worker hors ligne »
Les routines, Telegram et les tâches de fond sont arrêtés. Installation simple :
`systemctl status talixio-worker` et `journalctl -u talixio-worker -n 50`. Docker : `docker logs talixio-<vous>`.

## L'app ne répond plus ou reste sur une ancienne version
Rechargez, ou fermez et rouvrez l'app. Si rien n'y fait : dans le navigateur, paramètres du site → effacer les données.

## Je ne peux plus me connecter
- Mot de passe oublié : `sudo talixio password` (Docker : page d'administration → *Nouveau mot de passe*).
- Téléphone perdu (deux étapes) : `sudo talixio totp off` (Docker : *Réinitialiser la connexion en deux étapes*).
- Après 5 erreurs en 15 minutes, l'adresse est bloquée un moment.

## Les documents restent « en lecture… »
La lecture se fait en arrière-plan par le worker : vérifiez qu'il tourne. Un fichier « illisible » :
ouvrez-le et choisissez *Relire*.

## La transcription locale échoue
- « not enough memory » : sous Docker, donnez 2 Go à la personne (page d'administration → limites),
  ou choisissez le modèle `small`.
- « model file not found » : relancez `sudo ./install.sh --with-whisper` (ou `install-stack.sh` sous Docker).

## Un e-mail ou Signal ne répond pas
Page **Canaux** : la dernière erreur de chaque canal y est affichée. Vérifiez que l'expéditeur est dans la liste des autorisés.
