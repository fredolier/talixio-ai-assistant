# Sauvegardes et restauration

- **Chaque nuit**, une sauvegarde de la base, de la mémoire, des compétences, des fichiers et des clés
  (les 7 dernières sont gardées), dans `/var/lib/talixio/backups` (ou `TALIXIO_BACKUP_DIR`, par exemple un disque monté).
- Une sauvegarde tout de suite : `sudo talixio backup` (sous Docker : page d'administration → la personne → *Sauvegarde*).

## Restaurer (installation simple)
```bash
sudo systemctl stop talixio-web talixio-worker
cd /var/lib/talixio && sudo tar -xzf backups/talixio-AAAAMMJJ-HHMMSS.tar.gz
sudo mv backup-snapshot.db talixio.db
sudo chown -R talixio:talixio /var/lib/talixio
sudo systemctl start talixio-web talixio-worker
```

## Changer de serveur
Plutôt qu'une sauvegarde à restaurer à la main, utilisez le **transfert** : voir
*Administration / Transférer un assistant vers un autre serveur*.
