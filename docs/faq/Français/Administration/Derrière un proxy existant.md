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

## Quelles adresses sont de confiance
L'assistant lit l'adresse du visiteur et le https dans les en-têtes `X-Forwarded-For` et
`X-Forwarded-Proto` du proxy seulement quand la requête vient d'un proxy de confiance ; sinon n'importe qui
pourrait les forger pour échapper au blocage après des mots de passe faux. `TALIXIO_TRUSTED_PROXIES` les liste :
`loopback` (par défaut, un proxy sur la même machine), `private` (toute adresse privée : la pile Docker le
règle, car seul son Caddy atteint les assistants), ou des adresses et plages comme `10.0.0.5, 192.168.1.0/24`.
Pour une installation simple derrière un proxy sur une autre machine, ajoutez l'adresse du proxy dans
`/etc/talixio/talixio.env`, par ex. `TALIXIO_TRUSTED_PROXIES=10.0.0.5`, puis `sudo systemctl restart talixio-web`.

## Discussion par e-mail
Seuls les e-mails vérifiés par votre fournisseur sont crus (l'en-tête `Authentication-Results` le plus haut).
Réglez `TALIXIO_EMAIL_AUTHSERV_ID` sur le nom du serveur de votre fournisseur, le premier mot de cet en-tête
(par ex. `mx.example.com`), pour qu'aucun autre serveur ne soit cru.
