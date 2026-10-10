# Passer d'une installation simple à Docker

Votre installation faite avec `install.sh` devient l'assistant d'une personne de la pile Docker :
```bash
cd /opt/talixio-ai-assistant && git pull     # le dossier de votre clone
sudo deploy/docker/install-stack.sh --domain assistant.example.com --migrate fred --name "Frédéric"
```
- Les données de `/var/lib/talixio` sont **copiées** dans l'assistant `fred` (l'original reste en place),
  les réglages de `/etc/talixio/talixio.env` sont importés, le mot de passe reste le même.
- Les anciens services (talixio-web, talixio-worker, Caddy, Ollama) sont arrêtés et désactivés, pas supprimés.
- Le modèle whisper déjà installé est repris ; la personne reçoit 2 Go et 2 processeurs.
- La nouvelle adresse est `https://assistant.example.com/fred/` : ouvrez-la et réinstallez l'app sur le
  téléphone ; mettez-la dans Réglages → *Adresse publique*. Les liens de partage déjà envoyés
  utilisent l'ancienne adresse.

**Ne relancez pas** `--migrate` une fois la pile en service : cela recopierait les anciennes données
par-dessus. Pour reconstruire ou mettre à jour, `sudo deploy/docker/install-stack.sh` sans option.

## Revenir en arrière
```bash
docker compose -f deploy/docker/compose.yml down
sudo systemctl enable --now talixio-web talixio-worker   # (et caddy, ollama s'ils étaient installés)
```
Ce qui a été fait entre-temps dans Docker reste dans le volume `talixio-fred`.
