#!/bin/sh
# Checks a server is not exposed to PHP2Shell (CVE-2026-109618, CVE-2026-109619).
# The flaw is in the PHP engine, so the patch level is the answer. Run on the server
# (or: docker exec talixio-<name> sh /opt/talixio/deploy/check-php2shell.sh).
set -eu
ver=$(php -r 'echo PHP_VERSION;')
pkg=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
echo "PHP $ver"
# Debian/Ubuntu ship the fix as a package revision, so compare the distro package, not PHP_VERSION.
dver=$(dpkg-query -W -f '${Version}' "php${pkg}-cli" 2>/dev/null || true)
fixed=""
case "$pkg" in
  8.4) fixed="8.4.27-1~deb13u1" ;;
  8.3) fixed="8.3.28" ;;        # upstream fixed line; distro revision may differ
  8.2) fixed="8.2.35-1~deb12u1" ;;
esac
bad=0
if [ -n "$dver" ] && [ -n "$fixed" ]; then
  if dpkg --compare-versions "$dver" ge "$fixed"; then echo "OK  package $dver >= $fixed (patched)"; else echo "VULNERABLE  package $dver < $fixed"; bad=1; fi
else
  echo "?   cannot read the distro package version; confirm $ver includes the 2026-10-21 fix with your vendor"
fi
up=$(php -r 'echo ini_get("session.upload_progress.enabled") ? "on":"off";')
echo "session.upload_progress.enabled = $up  (mitigation: should be off)"
[ "$up" = off ] || echo "WARN  turn session.upload_progress off until PHP is patched"
[ "$bad" = 0 ] && echo "=> not vulnerable to PHP2Shell" || { echo "=> VULNERABLE: update PHP now"; exit 1; }
