# La pile Docker derrière un proxy existant

Quand un autre reverse proxy (par exemple Caddy sur une autre machine) fait déjà le HTTPS :
```bash
sudo deploy/docker/install-stack.sh --domain assistant.example.com --behind-proxy 8080
```
La pile sert alors en HTTP simple sur le port 8080 (pas de certificat sur ce serveur). Sur le proxy :
```
assistant.example.com {
    reverse_proxy <ip de ce serveur>:8080
}
```
- En sous-domaines (`https://<personne>.<domaine>/`), mettez aussi `*.assistant.example.com` sur le proxy.
- N'ouvrez le port 8080 qu'au proxy : `ufw allow from <ip-du-proxy> to any port 8080`.
- `--behind-proxy 10.0.0.5:8080` écoute seulement sur cette adresse.
- Le réglage est gardé dans `deploy/docker/.env` : inutile de le redonner aux relances suivantes.
