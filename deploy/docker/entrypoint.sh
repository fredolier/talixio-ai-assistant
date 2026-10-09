#!/bin/bash
# Runs the web app and the worker side by side; if one stops it is started again, and a stop
# signal (docker stop) stops both. Logs go to the container's output.
set -u
mkdir -p "$HOME" "$TALIXIO_DATA_DIR"
cd "$TALIXIO_DATA_DIR"

web() {
  exec php -d variables_order=EGPCS -d upload_max_filesize=500M -d post_max_size=510M -d memory_limit=512M -d max_execution_time=0 \
    -S "$TALIXIO_WEB_BIND" -t /opt/talixio/web/public /opt/talixio/web/router.php
}
worker() { exec php /opt/talixio/bin/talixio worker; }

[ $# -gt 0 ] && exec php /opt/talixio/bin/talixio "$@"   # a one-off command, e.g. doctor
php /opt/talixio/bin/talixio boot                         # first password / resets asked from the admin page

# tini -g sends docker's stop signal to every process here, these loops included
( while true; do ( web ); echo "web app stopped, restarting in 3 s" >&2; sleep 3; done ) &
( while true; do ( worker ); echo "worker stopped, restarting in 5 s" >&2; sleep 5; done ) &
wait
