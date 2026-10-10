# Les connecteurs (MCP)

Un connecteur donne à l'assistant les outils d'un autre service : GitHub, Notion, votre domotique, une
autre application (par exemple un gestionnaire de tâches)… Réglages → **Connecteurs (MCP)**.

## Un service en ligne
Collez sa configuration dans le champ *Configuration* (format `mcpServers` habituel) :
une `url`, avec un jeton dans `headers`, ou le bouton **Se connecter** pour les services qui utilisent OAuth
(Notion, Linear, Atlassian…).

## Votre propre connecteur, sans administrateur
Un serveur MCP « local » est un petit programme (Node.js ou Python). Pour l'installer vous-même :

1. Compressez **son dossier** en `.zip` (celui qui contient `package.json`, ou `src/server.js`, `server.js`,
   `index.js`, `server.py`…).
2. Importez le `.zip` dans la bibliothèque, par exemple dans **/Connecteurs**.
3. Ouvrez le fichier → **Installer comme connecteur** (ou Réglages → Connecteurs → *Installer depuis la
   bibliothèque…*).
4. Donnez-lui un nom court (`anki`) et remplissez les réglages qu'il lit (adresse, compte, mot de passe…) :
   Talixio les trouve dans son code et son README.
5. *Installer* : il est décompressé dans votre assistant, ses dépendances Node installées (`npm install`),
   puis démarré. Ses outils apparaissent (`mcp_anki_…`) et l'assistant peut s'en servir au message suivant.

- **Mettre à jour** : importez le nouveau `.zip` et réinstallez sous le même nom. Un mot de passe laissé
  vide garde l'ancien.
- **Supprimer** : retirez son entrée dans *Configuration*, puis *Enregistrer et connecter*.
- Les outils qui modifient quelque chose **demandent votre validation**, sauf si vous ajoutez
  `"trust": "all"` à son entrée.
- Les réglages (mot de passe compris) sont gardés dans `data/mcp.json`, dans votre assistant : ils font
  partie des sauvegardes et des transferts. Un compte dédié est préférable.
- Le connecteur tourne dans votre assistant (sous Docker, dans votre conteneur) avec les mêmes droits que
  lui : n'installez que du code dont vous connaissez l'origine.
- Un projet TypeScript qui doit être compilé l'est (`npm run build`) si le paquet n'a pas de fichier prêt.
- Python : seuls les serveurs sans dépendances à installer sont pris en charge pour l'instant.
