# Sauvegardes et restauration

- **Chaque nuit**, une sauvegarde de la base, de la mémoire, des compétences, des fichiers et des clés
  (les 7 dernières sont gardées), dans `/var/lib/talixio/backups` (ou `TALIXIO_BACKUP_DIR`, par exemple un disque monté).
- Une sauvegarde tout de suite : `sudo talixio backup` (sous Docker : page d'administration → la personne → *Sauvegarde*).
- Si la sauvegarde de la nuit échoue (disque plein, dossier impossible à écrire), vous recevez **une** notification
  qui dit pourquoi ; elle est retentée toutes les 6 heures, et la page **Confidentialité** affiche *Dernière sauvegarde
  en échec* avec la raison, ou *Aucune sauvegarde depuis plus de deux jours*, jusqu'à ce qu'une réussisse.
- Les secrets d'une sauvegarde sont **chiffrés** avec `/etc/talixio/secret.key`, qui n'est pas dans la
  sauvegarde : copiez ce fichier une fois en lieu sûr (et, avec un agent UrBackup ou un autre, ajoutez-le à ce qui
  est sauvegardé, à part). Sur un serveur à plusieurs, sauvegardez le dossier d'état de l'agent (`fleet.json`) :
  la clé de chaque assistant en vient.

## Tout l'hôte, vers un autre disque ou serveur
`sudo /opt/talixio/deploy/talixio-hostbackup.sh backup backup@nas:/srv/talixio` copie tout (données, configuration,
la clé ; sous Docker, chaque volume Talixio) avec rsync, les services arrêtés le temps de la copie.
`… restore backup@nas:/srv/talixio` remet tout en place (sur un nouvel hôte, installez d'abord Talixio, à la même
version) ; ce qui est remplacé est mis de côté dans `<dossier>.before-restore-<date>`.
`… size` liste ce qui serait copié, avec la taille de chaque dossier et le total. Chaque copie est un miroir :
gardez des versions avec UrBackup ou des instantanés.

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
