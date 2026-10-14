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

# davfs2 needs FUSE in the kernel: checked first, before installing or asking anything
# In a container the kernel usually has FUSE but Docker does not hand the device over: say how to start it
if [ -f /.dockerenv ] || [ -f /run/.containerenv ]; then
    if ! { [ -e /dev/fuse ] && { : <>/dev/fuse; } 2>/dev/null; }; then
        echo "This runs in a container, which has no access to /dev/fuse (davfs2 needs it)."
        if grep -qw fuse /proc/filesystems; then
            echo "The kernel has FUSE: start the container with"
            echo "    --device /dev/fuse --cap-add SYS_ADMIN --security-opt apparmor:unconfined"
            echo "(docker compose: devices: [/dev/fuse], cap_add: [SYS_ADMIN], security_opt: [apparmor:unconfined])"
        fi
        echo "Or mount the drive on the host (run this script there) and share it: -v /mnt/talixio:/mnt/talixio"
        exit 1
    fi
fi
if [ ! -e /dev/fuse ]; then
    modprobe fuse 2>/dev/null || true
    if [ ! -e /dev/fuse ] && grep -qw fuse /proc/filesystems; then
        mknod -m 666 /dev/fuse c 10 229
    fi
fi
WINDOWS=0
if [ ! -e /dev/fuse ]; then
    if grep -qi microsoft /proc/version; then
        # WSL without FUSE: Windows maps the drive (its own WebDAV client) and WSL mounts that letter (drvfs, no FUSE)
        NET=$(command -v net.exe || ls /mnt/*/Windows/System32/net.exe 2>/dev/null | head -1 || true)
        if [ -z "$NET" ]; then
            echo "This WSL kernel has no FUSE, and Windows commands cannot be run from here (WSL interop is off)."
            echo "Map the drive in Windows (File Explorer › This PC › Map network drive, letter Z:),"
            echo "then in WSL: sudo mkdir -p /mnt/z && sudo mount -t drvfs Z: /mnt/z"
            exit 1
        fi
        echo "This WSL kernel has no FUSE (which davfs2 needs): Windows will connect the drive instead,"
        echo "and it will be mounted here from its drive letter."
        WINDOWS=1
    else
        echo "This Linux has no FUSE, which davfs2 needs to mount a drive."
        echo "Install a kernel with FUSE (or the fuse3 package) and run this script again."
        exit 1
    fi
fi

if [ "$WINDOWS" = 1 ]; then
    echo
    echo "The address is in Settings › Network drive, for example https://server/dav/"
    echo "A subfolder works too: https://server/dav/Clients/"
    read -rp "Drive address: " URL
    URL=$(encode_url "$URL")
    [[ "$URL" == */ ]] || URL="$URL/"
    [[ "$URL" == https://* ]] || { echo "Windows connects a network drive only over https://"; exit 1; }
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
    if [[ "$MOUNT" =~ [[:space:]] ]]; then echo "The mount point cannot contain spaces."; exit 1; fi

    # Windows' own client: \\server@SSL\path (the form net.exe always accepts); the letter is the next free one
    UNC=${URL#https://}; UNC=${UNC%/}
    HOST=${UNC%%/*}; REST=${UNC#"$HOST"}
    UNC="\\\\${HOST}@SSL$(printf '%s' "$REST" | tr '/' '\\')"
    # A previous connection to the same place goes first (a changed password)
    "$NET" use "$UNC" /delete /y >/dev/null 2>&1 || true
    OUT=$("$NET" use '*' "$UNC" "$PASS" /user:"$DAVUSER" /persistent:yes 2>&1 | tr -d '\r') || true
    unset PASS
    LETTER=$(printf '%s' "$OUT" | grep -oE '\b[D-Z]:' | head -1 || true)
    if [ -z "$LETTER" ]; then
        echo "Windows could not connect the drive:"
        echo "$OUT"
        echo "Check the address and the drive password. If Windows says the network name cannot be found, start its"
        echo "WebDAV client once, in PowerShell as administrator: Start-Service WebClient; Set-Service WebClient -StartupType Automatic"
        exit 1
    fi
    echo "Connected in Windows as $LETTER"

    U_ID=$(id -u "$U"); G_ID=$(id -g "$U")
    if mountpoint -q "$MOUNT"; then umount "$MOUNT"; fi
    mkdir -p "$MOUNT"
    awk -v m="$MOUNT" '$2 != m' /etc/fstab > /etc/fstab.new
    echo "$LETTER $MOUNT drvfs rw,noauto,uid=$U_ID,gid=$G_ID 0 0" >> /etc/fstab.new
    cp /etc/fstab /etc/fstab.bak
    cat /etc/fstab.new > /etc/fstab
    rm -f /etc/fstab.new
    if mount "$MOUNT"; then
        echo
        echo "Drive mounted on $MOUNT (Windows drive $LETTER; the previous fstab is in /etc/fstab.bak)."
        echo "After a restart of WSL: sudo mount $MOUNT   (Windows reconnects $LETTER when you sign in)."
        echo "Windows' client refuses files over 50 MB by default and shows changes made elsewhere after a short delay."
    else
        echo "Windows connected $LETTER but WSL could not mount it. Try: sudo mount -t drvfs $LETTER $MOUNT"
        exit 1
    fi
    exit 0
fi

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
