# Backups and restore

- **Every night**, a backup of the database, memory, skills, files and keys
  (the last 7 are kept), in `/var/lib/talixio/backups` (or `TALIXIO_BACKUP_DIR`, for example a mounted disk).
- A backup right now: `sudo talixio backup` (under Docker: admin page → the person → *Backup*).

## Restoring (simple install)
```bash
sudo systemctl stop talixio-web talixio-worker
cd /var/lib/talixio && sudo tar -xzf backups/talixio-YYYYMMDD-HHMMSS.tar.gz
sudo mv backup-snapshot.db talixio.db
sudo chown -R talixio:talixio /var/lib/talixio
sudo systemctl start talixio-web talixio-worker
```

## Moving to another server
Rather than restoring a backup by hand, use the **transfer**: see
*Administration / Moving an assistant to another server*.
