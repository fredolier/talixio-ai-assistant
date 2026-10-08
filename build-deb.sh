#!/bin/sh
# Build talixio_<version>_all.deb  (usage: ./build-deb.sh [version])
set -eu
VERSION="${1:-0.1.0}"
ROOT="$(cd "$(dirname "$0")" && pwd)"
STAGE="$(mktemp -d)"; trap 'rm -rf "$STAGE"' EXIT
install -d "$STAGE/DEBIAN" "$STAGE/usr/share/talixio/bin" "$STAGE/usr/bin" \
           "$STAGE/etc/talixio" "$STAGE/lib/systemd/system" "$STAGE/usr/share/doc/talixio"
cp -r "$ROOT/src" "$STAGE/usr/share/talixio/src"
install -m 755 "$ROOT/bin/talixio" "$STAGE/usr/share/talixio/bin/talixio"
install -m 755 "$ROOT/packaging/talixio-wrapper" "$STAGE/usr/bin/talixio"
install -m 640 "$ROOT/packaging/talixio.env" "$STAGE/etc/talixio/talixio.env"
install -m 644 "$ROOT/packaging/talixio.service" "$STAGE/lib/systemd/system/talixio.service"
install -m 644 "$ROOT/README.md" "$STAGE/usr/share/doc/talixio/README.md"
install -m 755 "$ROOT/packaging/postinst" "$ROOT/packaging/prerm" "$STAGE/DEBIAN/"
echo /etc/talixio/talixio.env > "$STAGE/DEBIAN/conffiles"
cat > "$STAGE/DEBIAN/control" <<CTL
Package: talixio
Version: $VERSION
Section: net
Priority: optional
Architecture: all
Depends: php-cli (>= 8.2), php-curl, php-sqlite3, php-mbstring, adduser
Maintainer: Talixio <frederic@talixio.com>
Description: Self-hosted always-on AI assistant (Claude / OpenAI)
 PHP daemon with persistent memory, tool use, Telegram chat and scheduled
 proactive messages. Runs as a systemd service.
CTL
find "$STAGE" -type d -exec chmod 755 {} +
dpkg-deb --root-owner-group --build "$STAGE" "$ROOT/talixio_${VERSION}_all.deb"
