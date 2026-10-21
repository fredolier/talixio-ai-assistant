# Les connecteurs (MCP)

Un connecteur donne à l'assistant les outils d'un autre service : GitHub, Notion, votre domotique, une
autre application (par exemple un gestionnaire de tâches)… Réglages → **Connecteurs (MCP)**.

Des connecteurs prêts à installer en un clic : *Connecteurs / Liste des connecteurs*.

## Un service en ligne
Collez sa configuration dans le champ *Configuration* (format `mcpServers` habituel) :
une `url`, avec un jeton dans `headers`, ou le bouton **Se connecter** pour les services qui utilisent OAuth
(Notion, Linear, Atlassian…).

## Un connecteur publié sur GitHub
Un serveur MCP « local » est un petit programme (Node.js ou Python). S'il est sur GitHub, son adresse suffit :

1. Réglages → Connecteurs → **Installer un connecteur…**
2. Collez l'adresse du dépôt (`https://github.com/propriétaire/depot`, ou
   `https://github.com/propriétaire/depot/tree/main/dossier` si le serveur est dans un sous-dossier) →
   **Récupérer**. Talixio télécharge le dépôt et le range en `.zip` dans **/Connecteurs**.
3. Vérifiez ce qu'il a trouvé (fichier de démarrage, réglages lus), remplissez les réglages, puis *Installer*.

Vous pouvez aussi le demander à l'assistant (« installe le connecteur https://github.com/… ») : il vous
demande votre validation, puis l'installe ; s'il lit des réglages (adresse, compte, clé…), il vous donne le
lien du fichier pour les remplir vous-même (un mot de passe ne s'écrit pas dans la conversation).

Seuls les dépôts **publics** peuvent être récupérés ainsi ; pour un dépôt privé, téléchargez-le en `.zip`
et suivez la méthode ci-dessous.

## Votre propre connecteur, depuis un .zip
Pour un serveur qui n'est pas sur GitHub (ou dans un dépôt privé) :

1. Compressez **son dossier** en `.zip` (celui qui contient `package.json`, ou `src/server.js`, `server.js`,
   `index.js`, `server.py`…).
2. Importez le `.zip` dans la bibliothèque, par exemple dans **/Connecteurs**.
3. Ouvrez le fichier → **Installer comme connecteur** (ou Réglages → Connecteurs → *Installer un
   connecteur…*).
4. Donnez-lui un nom court (`anki`) et remplissez les réglages qu'il lit (adresse, compte, mot de passe…) :
   Talixio les trouve dans son code et son README.
5. *Installer* : il est décompressé dans votre assistant, ses dépendances Node installées (`npm install`),
   puis démarré. Ses outils apparaissent (`mcp_anki_…`) et l'assistant peut s'en servir au message suivant.

- **Mettre à jour** : importez le nouveau `.zip` et réinstallez sous le même nom. Un mot de passe laissé
  vide garde l'ancien.
- **Supprimer** : retirez son entrée dans *Configuration*, puis *Enregistrer et connecter*.
- Chaque outil **demande votre validation** tant que vous n'en décidez pas autrement. Dans Réglages →
  Connecteurs, chaque connecteur est une carte à déplier : elle dit ce que fait le connecteur, puis donne
  un tableau de ses outils avec ce que fait chacun. Les outils sont groupés selon ce qu'ils semblent
  faire (*semblent seulement lire*, *incertain*, *peuvent modifier ou envoyer*) ; ce classement est
  deviné d'après les indications du connecteur et le nom de l'outil, vérifiez la description. Cochez
  un outil pour qu'il tourne sans demander, aussi dans les tâches de fond ; la case d'un groupe coche
  tout le groupe (seulement les lignes que le filtre affiche). Chaque case est enregistrée aussitôt. Ou
  ajoutez `"trust": "all"` à son entrée pour faire confiance à tous ses outils.
- Les réglages (mot de passe compris) sont gardés dans `data/mcp.json`, dans votre assistant : ils font
  partie des sauvegardes et des transferts. Un compte dédié est préférable.
- Le connecteur tourne dans un bac à sable quand bubblewrap est installé (toujours sous Docker) : il voit
  son propre dossier, les programmes du système et le réseau, pas vos documents, vos e-mails ni vos clés.
  Il atteint toutefois ce que ses réglages lui donnent : n'installez que du code dont vous connaissez l'origine.
- Un projet TypeScript qui doit être compilé l'est (`npm run build`) si le paquet n'a pas de fichier prêt.
- Python : seuls les serveurs sans dépendances à installer sont pris en charge pour l'instant.

## Ce qu'il ne faut pas faire
- Pas de `git clone`, `npm install` ou `npm start` à lancer vous-même, et pas d'adresse `localhost` à
  déclarer : Talixio installe et démarre le connecteur lui-même, à chaque utilisation.
- Pas besoin de redémarrer l'assistant : les nouveaux outils sont là dès le message suivant.
- La *Configuration* est du JSON strict : pas de commentaires (`//`).
- Une API web classique (REST) n'est pas un serveur MCP : il faut un serveur MCP qui l'utilise.
- Un résultat long (une page convertie, un gros export) est enregistré en entier comme fichier dans votre
  bibliothèque, dans les *Notes* de l'espace ; demandez à l'assistant de le déplacer ou de le renommer où vous
  voulez. Le fil n'en montre que le début, replié.

Exemples pas à pas : *Connecteurs / anki.talixio.com* (un connecteur à installer) et *Connecteurs / Home Assistant* (un service distant, par son adresse).

Sur un serveur partagé par plusieurs personnes, les connecteurs validés par l'administrateur sont dans Réglages →
Connecteurs → *Depuis le catalogue*, à installer d'un bouton (voir *Administration / Le catalogue*).
