# Installer Talixio (une personne, un serveur)

Sur une VM Debian 12+ ou Ubuntu 24.04+ :
```bash
git clone https://github.com/fredolier/talixio-ai-assistant.git
cd talixio-ai-assistant
sudo ./install.sh
```
Le script installe PHP, crée l'utilisateur système `talixio`, copie l'app dans `/opt/talixio`, écrit
`/etc/talixio/talixio.env` (avec un mot de passe aléatoire) et démarre deux services :
`talixio-web` (l'app sur 127.0.0.1:8080) et `talixio-worker` (Telegram, routines, tâches de fond).
Il démarre en mode « écho » (sans modèle) pour vérifier l'installation.

## L'adresse en HTTPS
- `--domain assistant.example.com` installe Caddy sur la VM (DNS vers la VM, ports 80 et 443 ouverts).
- `--bind 10.0.0.5:8080` quand votre proxy est sur une autre machine ; dans son Caddyfile :
  `assistant.example.com { reverse_proxy 10.0.0.5:8080 }`. N'ouvrez le port 8080 qu'au proxy.

## Ensuite
- Ouvrez l'app, connectez-vous avec le mot de passe de `talixio.env`, puis réglez tout dans **Réglages**.
- *Lancer les vérifications* teste les connexions et chaque niveau de modèle (comme `sudo talixio doctor`).
- Activez la **connexion en deux étapes** (Réglages).
- Mot de passe oublié : `sudo talixio password`. Téléphone perdu : `sudo talixio totp off`.

Les données sont dans `/var/lib/talixio`. Voir aussi *Modules optionnels* et *Choisir le modèle*.
