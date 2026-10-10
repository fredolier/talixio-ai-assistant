# Modules optionnels de l'installation

Chacun s'ajoute en relançant `sudo ./install.sh` avec l'option (rien n'est perdu) :

| Option | Ce que ça apporte |
|---|---|
| `--with-ollama` | Recherche par le sens en local (modèle qwen3-embedding:0.6b ; plus léger : voir *Utilisation / Recherche par le sens*) : gratuit, privé, bon en français |
| `--with-whisper` | Transcription sur le serveur (whisper.cpp, modèle large-v3-turbo ; `WHISPER_MODEL=small` pour plus léger) |
| `--with-office` | LibreOffice : pages fidèles des fichiers Word/Excel/PowerPoint, anciens formats lus |
| `--with-browser` | Navigateur sans écran : pages avec JavaScript, clics, captures |
| `--with-shell` | Bac à sable Linux avec Python pour les calculs, analyses et graphiques |
| `--with-node` | Node.js, pour les connecteurs MCP lancés par `npx` |
| `--with-signal` | signal-cli, pour le canal Signal |
| `--with-claude-code` | Claude Code, pour utiliser votre abonnement Claude Pro/Max |

Sous Docker (plusieurs personnes), l'image contient déjà tout cela ; le modèle whisper est partagé
par tous les assistants.
