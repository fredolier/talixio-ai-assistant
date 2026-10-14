# Ouvrir la bibliothèque comme un lecteur réseau (WebDAV)

- Réglages → *Lecteur réseau (WebDAV)* → *Créer un nouveau mot de passe du lecteur* (il faut le mot de passe de l'app).
  Le mot de passe du lecteur n'est affiché qu'une fois ; en créer un nouveau désactive l'ancien.
- *Accès* : désactivé, lecture seule ou lecture et écriture.
- Adresse : celle affichée dans la carte (`https://…/dav/`), utilisateur `talixio`.
  - **Windows** : Explorateur → Ce PC → Connecter un lecteur réseau, cocher *Se connecter à l'aide d'informations d'identification différentes*.
    Le service Windows **WebClient** doit être démarré (services.msc → WebClient → Démarrer, type Automatique).
    Si Windows dit qu'il ne peut pas accéder au dossier : vérifier l'adresse (avec `/dav/` à la fin), le mot de passe du lecteur (pas celui de l'app), puis le service WebClient.
  - **macOS** : Finder → Aller → Se connecter au serveur (⌘K).
  - **iPhone, iPad** : Fichiers → ⋯ → Se connecter au serveur.
  - **Android** : une app WebDAV (Solid Explorer, Material Files, CX File Explorer…).
  - **Linux** (Debian, Ubuntu) : le script `deploy/mount-webdav-linux.sh` installe davfs2 et prépare le montage :
    ```
    curl -fsSLO https://raw.githubusercontent.com/fredolier/talixio-ai-assistant/HEAD/deploy/mount-webdav-linux.sh
    sudo bash mount-webdav-linux.sh
    ```
    Il demande l'adresse, le mot de passe du lecteur et le point de montage (`/mnt/talixio` par défaut) ; ensuite
    `mount /mnt/talixio` marche sans sudo après une nouvelle connexion. Le relancer pour changer d'adresse ou de mot de passe.
    davfs2 envoie un fichier quelques secondes après sa fermeture, et les fichiers ajoutés depuis l'app web peuvent
    mettre jusqu'à une minute à apparaître. Son dossier `lost+found` n'existe que sur l'ordinateur.
    **WSL** (Linux sous Windows) n'a souvent pas FUSE, dont davfs2 a besoin (« can't open fuse device »). Le script
    fait alors connecter le lecteur par Windows (une lettre libre, reconnectée à l'ouverture de session) et monte
    cette lettre dans WSL avec drvfs : pas besoin de FUSE. Si Windows dit que le nom réseau est introuvable, démarrer
    une fois son client WebDAV, dans PowerShell en administrateur :
    `Start-Service WebClient; Set-Service WebClient -StartupType Automatic`. Le client de Windows refuse par défaut
    les fichiers de plus de 50 Mo. Après un redémarrage de WSL : `sudo mount /mnt/talixio`.
    **Dans un conteneur Docker**, le démarrer avec `--device /dev/fuse --cap-add SYS_ADMIN --security-opt apparmor:unconfined`,
    ou monter le lecteur sur l'hôte et le partager avec `-v /mnt/talixio:/mnt/talixio`.
- Un sous-dossier peut s'ouvrir directement : ajouter son chemin à l'adresse, par exemple `https://…/dav/Clients/`
  (espaces et accents encodés sur macOS et Linux : `Mon%20dossier`). L'orthographe doit être exacte, majuscules
  comprises. Le mot de passe du lecteur ouvre toujours tout le lecteur.
- Ce sont les mêmes dossiers et fichiers que dans la bibliothèque :
  - un fichier ajouté ou modifié est relu et réindexé (recherche par mots et par le sens) comme un envoi depuis l'app ;
  - un fichier renommé ou déplacé garde son index, sous son nouveau nom ;
  - un fichier ou dossier supprimé va dans la corbeille de la bibliothèque (30 jours).
- Les dossiers des espaces **Secret** n'apparaissent pas. La FAQ est en lecture seule.
- Les fichiers techniques des systèmes (`.DS_Store`, `._…`, `Thumbs.db`, `desktop.ini`…) sont gardés à part, hors de la bibliothèque.
- Enregistrer un fichier sous un nom déjà présent le remplace ; l'ancien contenu reste dans ses *Versions* (voir Bibliothèque).
  Deux fichiers du même nom d'avant la 0.28.29, tant qu'ils ne sont pas fusionnés : le plus récent garde son nom, les autres s'affichent « nom (#id).ext ».
- Dix mauvais mots de passe différents depuis une même adresse la bloquent 15 minutes ; le bon mot de passe passe
  toujours. Un appareil qui réessaie un ancien mot de passe enregistré ne compte qu'une fois. Quand une adresse est
  bloquée, Réglages › Lecteur réseau l'indique, avec **Débloquer maintenant** ; créer un nouveau mot de passe du
  lecteur débloque aussi toutes les adresses.
- « Mot de passe actuel incorrect » en créant un mot de passe du lecteur : c'est le mot de passe de l'app saisi qui est refusé ; rien n'a changé, l'ancien mot de passe du lecteur reste valable.
