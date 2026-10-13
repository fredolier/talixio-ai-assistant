#!/bin/bash
# Mounts Talixio's network drive (WebDAV) on Debian or Ubuntu with davfs2.
# Usage: sudo bash mount-webdav-linux.sh   (run it again to change the address or the password)
set -euo pipefail

if [ "$EUID" -ne 0 ]; then
    echo "Run it again with sudo: sudo $0"
    exit 1
fi

# The files on the drive belong to the user who ran sudo
U=${SUDO_USER:-root}
G=$(id -gn "$U")

# Encodes spaces and accents in the address (%20, %C3%A9…), keeps what is already encoded
encode_url() (
    LC_ALL=C
    local s=$1 out='' c h i
    for ((i = 0; i < ${#s}; i++)); do
        c=${s:i:1}
        case "$c" in
            [a-zA-Z0-9.~_/:%-]) out+=$c ;;
            *) printf -v h '%%%02X' "'$c"; out+=$h ;;
        esac
    done
    printf '%s' "$out"
)

echo "=== Talixio network drive (WebDAV) with davfs2 ==="

# Lets a normal user mount the drive (the question davfs2 asks when it is installed)
echo "davfs2 davfs2/suid_file boolean true" | debconf-set-selections
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y davfs2
dpkg-reconfigure -f noninteractive davfs2

echo
echo "The address is in Settings › Network drive, for example https://server/dav/"
echo "A subfolder works too: https://server/dav/Clients/"
read -rp "Drive address: " URL
URL=$(encode_url "$URL")
[[ "$URL" == */ ]] || URL="$URL/"
echo "Address used: $URL"

read -rp "Username [talixio]: " DAVUSER
DAVUSER=${DAVUSER:-talixio}
DAVUSER=${DAVUSER// /_}
read -rsp "Drive password (not the app password): " PASS
echo
PASS=$(printf '%s' "$PASS" | tr -d '[:space:]')

read -rp "Mount point [/mnt/talixio]: " MOUNT
MOUNT=${MOUNT:-/mnt/talixio}
MOUNT=${MOUNT%/}
if [[ "$MOUNT" =~ [[:space:]] ]]; then
    echo "The mount point cannot contain spaces."
    exit 1
fi

# davfs2 needs FUSE in the kernel
if [ ! -e /dev/fuse ]; then
    modprobe fuse 2>/dev/null || true
    if [ ! -e /dev/fuse ] && grep -qw fuse /proc/filesystems; then
        mknod -m 666 /dev/fuse c 10 229
    fi
fi
if [ ! -e /dev/fuse ]; then
    echo "This Linux has no FUSE, which davfs2 needs to mount a drive."
    if grep -qi microsoft /proc/version; then
        echo "On WSL: map the drive in Windows (File Explorer › This PC › Map network drive, letter Z:),"
        echo "then in WSL: sudo mkdir -p /mnt/z && sudo mount -t drvfs Z: /mnt/z"
        echo "Or update WSL from Windows (wsl --update) and run this script again."
    else
        echo "Install a kernel with FUSE (or the fuse3 package) and run this script again."
    fi
    exit 1
fi

# Unmounts a previous setup
if mountpoint -q "$MOUNT"; then umount "$MOUNT"; fi
mkdir -p "$MOUNT"
chown "$U:$G" "$MOUNT"

# Password: replaces any line for this address or this mount point
mkdir -p /etc/davfs2
touch /etc/davfs2/secrets
chmod 600 /etc/davfs2/secrets
awk -v u="$URL" -v m="$MOUNT" '$1 != u && $1 != m' /etc/davfs2/secrets > /etc/davfs2/secrets.new
printf '%s %s %s\n' "$URL" "$DAVUSER" "$PASS" >> /etc/davfs2/secrets.new
cat /etc/davfs2/secrets.new > /etc/davfs2/secrets
rm -f /etc/davfs2/secrets.new
unset PASS

# fstab: replaces any davfs line for this mount point
awk -v m="$MOUNT" '!($2 == m && $3 == "davfs")' /etc/fstab > /etc/fstab.new
echo "$URL $MOUNT davfs rw,_netdev,noauto,user,uid=$U,gid=$G,file_mode=0644,dir_mode=0755 0 0" >> /etc/fstab.new
cp /etc/fstab /etc/fstab.bak
cat /etc/fstab.new > /etc/fstab
rm -f /etc/fstab.new
systemctl daemon-reload 2>/dev/null || true

# Lets the user mount it without sudo
if [ "$U" != root ]; then
    usermod -aG davfs2 "$U"
fi

echo
echo "Done (the previous fstab is in /etc/fstab.bak)."
echo "Mount:   mount $MOUNT"
echo "Unmount: umount $MOUNT"
if [ "$U" != root ]; then
    echo "$U can do it without sudo after signing in again (davfs2 group)."
fi
echo "A file is sent a few seconds after it is closed."
echo "Files added from the web app can take up to a minute to appear."
echo "lost+found exists only on this computer."
echo

read -rp "Mount it now? [Y/n] " ANSWER
if [[ "${ANSWER:-}" =~ ^([yYoO]|)$ ]]; then
    if mount "$MOUNT"; then
        echo "Drive mounted on $MOUNT"
    else
        echo
        echo "Mounting failed (see the message above). For a wrong address or password, run this script again."
        exit 1
    fi
fi
