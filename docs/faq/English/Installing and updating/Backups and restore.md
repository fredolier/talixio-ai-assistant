# Backups and restore

- **Every night**, a backup of the database, memory, skills, files and keys
  (the last 7 are kept), in `/var/lib/talixio/backups` (or `TALIXIO_BACKUP_DIR`, for example a mounted disk).
- A backup right now: `sudo talixio backup` (under Docker: admin page → the person → *Backup*).
- If the nightly backup fails (a full disk, a folder that cannot be written), you get **one** notice saying why;
  it is tried again every 6 hours, and the **Privacy** page shows *Last backup failed* with the reason, or
  *No backup for more than two days*, until one works again.
- The secrets in a backup are **encrypted** with `/etc/talixio/secret.key`, which is not in the backup: copy
  that file somewhere safe once (and, with an UrBackup agent or any other, add it to what is backed up, kept apart).
  On a shared server, back up the agent's state folder (`fleet.json`): each assistant's key comes from it.

## The whole host, to another disk or server
`sudo /opt/talixio/deploy/talixio-hostbackup.sh backup backup@nas:/srv/talixio` copies everything (data, config,
the key; under Docker every Talixio volume) with rsync, the services stopped for the time of the copy.
`… restore backup@nas:/srv/talixio` puts it back (on a new host, install Talixio first, at the same version);
what it replaces is kept aside in `<folder>.before-restore-<date>`. Each copy is a mirror: keep versions with
UrBackup or snapshots.

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
