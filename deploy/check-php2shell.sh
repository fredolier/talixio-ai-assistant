#!/bin/sh
# Checks a server is not exposed to PHP2Shell (CVE-2026-109618, CVE-2026-109619).
# The flaw is in the PHP engine, so the patch level is the answer. Run on the server
# (or: docker exec talixio-<name> sh /opt/talixio/deploy/check-php2shell.sh).
# Exit 0: patched. Exit 1: vulnerable. Exit 2: cannot tell (a distro that backports under the old version number).
set -eu
ver=$(php -r 'echo PHP_VERSION;')
pkg=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
echo "PHP $ver"
dver=$(dpkg-query -W -f '${Version}' "php${pkg}-cli" 2>/dev/null || true)
osid=$( . /etc/os-release 2>/dev/null; echo "${ID:-unknown}")
# Fixed upstream releases (2026-10-21): 8.5.12, 8.4.27, 8.3.36, 8.2.35. Debian ships them as package revisions.
case "$pkg" in 8.5) up=8.5.12 ;; 8.4) up=8.4.27 ;; 8.3) up=8.3.36 ;; 8.2) up=8.2.35 ;; *) up="" ;; esac
state=unknown
if [ "$osid" = debian ] && [ -n "$dver" ]; then
  case "$pkg" in 8.4) fixed="8.4.27-1~deb13u1" ;; 8.2) fixed="8.2.35-1~deb12u1" ;; *) fixed="" ;; esac
  if [ -n "$fixed" ]; then
    if dpkg --compare-versions "$dver" ge "$fixed"; then state=ok; echo "OK  Debian package $dver >= $fixed (DSA-6574-1 / DLA-4848-1)"; else state=bad; echo "VULNERABLE  Debian package $dver < $fixed"; fi
  fi
fi
if [ "$state" = unknown ] && [ -n "$up" ]; then
  if [ "$(printf '%s\n%s\n' "$up" "$ver" | sort -V | head -1)" = "$up" ]; then state=ok; echo "OK  PHP $ver >= $up"
  elif [ -n "$dver" ] && [ "$osid" != debian ]; then echo "?   $osid backports the fix under the old number ($dver): check its security notice (Ubuntu: USN-8948-1) or run apt update && apt upgrade"
  else state=bad; echo "VULNERABLE  PHP $ver < $up"; fi
elif [ "$state" = unknown ]; then echo "?   PHP $pkg has no upstream fix: use a distribution that backports it, or move to 8.2 or later"; fi
srv=$(php -r 'echo ini_get("session.upload_progress.enabled") ? "on":"off";')
echo "session.upload_progress.enabled = $srv  (should be off; the Talixio containers force it off)"
case "$state" in
  ok)  echo "=> not vulnerable to PHP2Shell"; exit 0 ;;
  bad) echo "=> VULNERABLE: update PHP now (apt update && apt upgrade, then restart talixio-web)"; exit 1 ;;
  *)   echo "=> cannot tell from the version number"; exit 2 ;;
esac
