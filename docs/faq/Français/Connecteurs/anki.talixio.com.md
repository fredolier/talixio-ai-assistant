# Connecter anki.talixio.com

Pour que l'assistant lise et gère vos tâches d'**anki.talixio.com** (« quelles tâches ai-je ouvertes dans
anki ? », « ajoute dans anki : rappeler le notaire »), on installe le serveur MCP d'anki comme connecteur.
Tout se fait depuis l'app, sans administrateur.

## Ce qu'il faut
- Le dossier **`mcp/`** du serveur MCP d'anki (il contient `package.json` et `src/server.js`).
- Un compte anki. **Un compte dédié à l'assistant est préférable** : son mot de passe est gardé dans
  l'assistant (voir plus bas).

## Installer
1. Sur votre ordinateur, compressez le dossier `mcp/` en `.zip` (clic droit → Compresser / Envoyer vers →
   Dossier compressé). Le `.zip` peut contenir le dossier `mcp/` lui-même ou directement son contenu.
2. Dans l'assistant, **Bibliothèque** → dossier **/Connecteurs** (créez-le au besoin) → *Importer* le `.zip`.
3. Ouvrez le fichier → **Installer comme connecteur**.
4. Remplissez :

   | Champ | Valeur |
   |---|---|
   | Nom | `anki` |
   | `TALIXIO_URL` | `https://anki.talixio.com` (**sans** `/api` à la fin) |
   | `TALIXIO_EMAIL` | l'adresse du compte anki |
   | `TALIXIO_PASSWORD` | son mot de passe |

5. **Installer**. Après une minute au plus (installation des dépendances), la fenêtre affiche
   « Prêt : n outil(s) de « anki » ». Les outils apparaissent dans Réglages → Connecteurs, sous `anki`.

## Utiliser
- Demandez simplement : « Quelles tâches ai-je ouvertes dans anki ? ».
- L'assistant a aussi **son propre tableau de tâches** (page Tâches). Dites « dans anki » quand ce n'est pas
  évident : sinon il peut utiliser son tableau à lui.
- Ses outils **demandent votre validation** dans l'app tant que vous n'avez pas marqué ceux qui lisent
  (lister, chercher) dans Réglages → Connecteurs. Une fois en confiance, ajoutez `"trust": "all"` à l'entrée `anki` dans
  Réglages → Connecteurs → *Configuration*.
- Ça marche aussi depuis le téléphone, Telegram ou Signal : c'est l'assistant qui parle à anki.

## Si ça ne marche pas
Le message d'erreur s'affiche dans la fenêtre d'installation et dans la carte `anki` de Réglages → Connecteurs.

| Message | Que faire |
|---|---|
| refus de connexion, 401, identifiants | vérifiez l'adresse e-mail et le mot de passe ; réinstallez (un mot de passe laissé vide garde l'ancien) |
| 404, page introuvable | `TALIXIO_URL` doit être `https://anki.talixio.com`, sans `/api` ni `/` final |
| `installing dependencies` en erreur | l'assistant n'a pas pu joindre npm : réessayez plus tard, ou prévenez l'administrateur (accès Internet du serveur) |
| « No server found » | le `.zip` ne contient pas le bon dossier : il faut celui où se trouve `package.json` |

*Vérifier à nouveau* (Réglages → Connecteurs) relance le serveur et relit ses outils.

## Mettre à jour, retirer
- **Nouvelle version** du serveur MCP : importez le nouveau `.zip` et réinstallez sous le nom `anki`.
  Laissez le mot de passe vide pour garder l'actuel.
- **Changer de mot de passe** : réinstallez avec le nouveau, ou modifiez `TALIXIO_PASSWORD` dans
  *Configuration*.
- **Retirer** : supprimez l'entrée `anki` dans *Configuration*, puis *Enregistrer et connecter*.

## À savoir
- L'adresse, le compte et le mot de passe sont gardés dans `data/mcp.json`, dans votre assistant : ils font
  partie de ses sauvegardes et d'un éventuel transfert vers un autre serveur.
- Le serveur MCP d'anki tourne dans votre assistant ; il ne parle qu'à anki.talixio.com.

Voir aussi : *Connecteurs / Installer un connecteur*.
