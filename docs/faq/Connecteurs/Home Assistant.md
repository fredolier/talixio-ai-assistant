# Connecter Home Assistant

Avec ce connecteur, l'assistant pilote votre domotique comme le fait l'assistant vocal de Home Assistant
(Assist) : allumer, éteindre, régler un thermostat, lire une température, ajouter à une liste de courses…
Il ne voit que les appareils que vous avez **exposés** à Assist.

Rien à installer côté Talixio : Home Assistant a son propre serveur MCP, auquel Talixio se connecte
directement par son adresse.

## Ce qu'il faut
- Home Assistant **2025.2 ou plus récent**.
- Que le serveur de Talixio puisse joindre Home Assistant : sur le même réseau
  (`http://192.168.1.10:8123`), par un VPN, ou par son adresse publique (Nabu Casa, proxy).
- Un compte Home Assistant pour l'assistant. Le plus prudent : un compte **dédié, non administrateur**
  (voir plus bas).

## Côté Home Assistant
1. **Paramètres → Appareils et services → Ajouter une intégration** → *Model Context Protocol Server*.
2. **Exposer les appareils** : Paramètres → Assistants vocaux → onglet *Exposer*. Cochez ce que
   l'assistant peut voir et commander, et rien d'autre (pas la porte du garage si vous n'y tenez pas).
3. **Un jeton** : connecté avec le compte de l'assistant, *Profil → Sécurité → Jetons d'accès longue
   durée → Créer un jeton*. Copiez-le tout de suite : il ne sera plus affiché.
4. Avec un compte **non administrateur** : intégration *Model Context Protocol Server* → *Configurer*,
   décochez l'option qui exige un compte administrateur (*Require an administrator account*). (Ou gardez l'option et utilisez l'adresse
   `/api/mcp/assist`, ouverte à tous les comptes.)

## Côté Talixio
Réglages → **Connecteurs (MCP)** → champ *Configuration*. Ajoutez une entrée `homeassistant` à côté de
celles qui existent déjà :

```json
{
  "mcpServers": {
    "homeassistant": {
      "url": "http://192.168.1.10:8123/api/mcp",
      "headers": { "Authorization": "Bearer COLLEZ_LE_JETON_ICI" }
    }
  }
}
```

| Champ | À mettre |
|---|---|
| `url` | l'adresse de Home Assistant **suivie de `/api/mcp`** (ou `/api/mcp/assist`) |
| `Authorization` | `Bearer ` (avec l'espace) puis le jeton longue durée |

Enregistrez : Talixio liste les outils de Home Assistant (`mcp_homeassistant_…`, par exemple
`HassTurnOn`, `HassTurnOff`, `GetLiveContext`). L'assistant peut s'en servir dès le message suivant.

## Utiliser
- « Allume la lumière du salon », « Quelle température fait-il dans la chambre ? »,
  « Ajoute du lait à la liste de courses ».
- Les commandes qui agissent **demandent votre validation** dans l'app. Une fois en confiance, ajoutez
  `"trust": "all"` à l'entrée `homeassistant` : l'assistant agit alors sans demander.
- Ça marche aussi depuis Telegram ou Signal. Les routines et tâches de fond, elles, n'utilisent pas les
  connecteurs : la domotique se commande depuis une conversation.

## Si ça ne marche pas
| Ce qu'on voit | Cause probable |
|---|---|
| `cannot reach …` | Home Assistant n'est pas joignable depuis le serveur : adresse, port 8123, pare-feu, VPN |
| HTTP 401 | jeton mal copié, ou `Bearer ` oublié devant |
| HTTP 403 | compte non administrateur alors que l'option « compte administrateur exigé » est cochée |
| HTTP 400 `Bad Request`, sans autre texte | Home Assistant refuse la requête venue par un proxy (Caddy, Nginx…) qu'il ne connaît pas ; son journal dit *« A request from a reverse proxy was received from … »*. Utilisez l'adresse locale (`http://IP:8123/api/mcp`), ou déclarez ce proxy dans Home Assistant (voir plus bas) |
| HTTP 404 | il manque `/api/mcp` à la fin de l'adresse, ou Home Assistant est trop ancien |
| Aucun appareil, « je ne trouve pas la lumière » | l'appareil n'est pas exposé à Assist |

Pour tester depuis le serveur (sous Docker) :
`docker exec talixio-fred curl -s -o /dev/null -w '%{http_code}\n' http://192.168.1.10:8123/api/` :
`401` veut dire « joignable » (il manque juste le jeton), une erreur ou `000` veut dire « pas joignable ».

Pour garder une adresse publique (`https://ha.example.com`) servie par un proxy, Home Assistant doit
connaître ce proxy. Dans son `configuration.yaml`, puis redémarrez Home Assistant :
```yaml
http:
  use_x_forwarded_for: true
  trusted_proxies:
    - 192.168.0.31   # l'adresse du proxy, telle que l'indique le journal de Home Assistant
```

## Notes
- Le jeton est gardé dans `data/mcp.json`, dans votre assistant. Pour couper l'accès, supprimez le jeton
  dans Home Assistant (Profil → Sécurité) ou l'entrée dans Talixio.
- Une adresse du réseau local (`192.168.…`, `10.…`, `….local`) compte comme un service **local** pour la
  confidentialité : les espaces confidentiels peuvent l'utiliser. Une adresse publique (Nabu Casa…) non.
- Le bouton *Se connecter* (OAuth) n'est pas la bonne voie ici : Home Assistant n'enregistre pas de
  client automatiquement. Le jeton longue durée est le plus simple.
