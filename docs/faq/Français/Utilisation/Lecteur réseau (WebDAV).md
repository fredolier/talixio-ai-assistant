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
- Ce sont les mêmes dossiers et fichiers que dans la bibliothèque :
  - un fichier ajouté ou modifié est relu et réindexé (recherche par mots et par le sens) comme un envoi depuis l'app ;
  - un fichier renommé ou déplacé garde son index, sous son nouveau nom ;
  - un fichier ou dossier supprimé va dans la corbeille de la bibliothèque (30 jours).
- Les dossiers des espaces **Secret** n'apparaissent pas. La FAQ est en lecture seule.
- Les fichiers techniques des systèmes (`.DS_Store`, `._…`, `Thumbs.db`, `desktop.ini`…) sont gardés à part, hors de la bibliothèque.
- Deux fichiers du même nom dans un dossier : le plus récent garde son nom, les autres s'affichent « nom (#id).ext ».
- Trop de mauvais mots de passe depuis une même adresse la bloquent 15 minutes.
- « Mot de passe actuel incorrect » en créant un mot de passe du lecteur : c'est le mot de passe de l'app saisi qui est refusé ; rien n'a changé, l'ancien mot de passe du lecteur reste valable.
