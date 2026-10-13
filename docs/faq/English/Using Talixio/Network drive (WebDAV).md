# Opening the library as a network drive (WebDAV)

- Settings → *Network drive (WebDAV)* → *Create a new drive password* (you need the app password).
  The drive password is shown only once; creating a new one turns off the old one.
- *Access*: off, read-only, or read and write.
- Address: the one shown in the card (`https://…/dav/`), username `talixio`.
  - **Windows**: File Explorer → This PC → Map network drive, tick *Connect using different credentials*.
    The Windows **WebClient** service must be running (services.msc → WebClient → Start, startup type Automatic).
    If Windows says it cannot access the folder: check the address (with `/dav/` at the end), the drive password (not the app password), then the WebClient service.
  - **macOS**: Finder → Go → Connect to Server (⌘K).
  - **iPhone, iPad**: Files → ⋯ → Connect to Server.
  - **Android**: a WebDAV app (Solid Explorer, Material Files, CX File Explorer…).
  - **Linux** (Debian, Ubuntu): the script `deploy/mount-webdav-linux.sh` installs davfs2 and sets up the mount:
    ```
    curl -fsSLO https://raw.githubusercontent.com/fredolier/talixio-ai-assistant/HEAD/deploy/mount-webdav-linux.sh
    sudo bash mount-webdav-linux.sh
    ```
    It asks for the address, the drive password and the mount point (`/mnt/talixio` by default); then `mount /mnt/talixio`
    works without sudo once you have signed in again. Run it again to change the address or the password.
    davfs2 sends a file a few seconds after it is closed, and files added from the web app can take up to a minute to
    appear. Its `lost+found` folder exists only on the computer.
- A subfolder can be opened directly: add its path to the address, for example `https://…/dav/Clients/`
  (spaces and accents encoded on macOS and Linux: `My%20Folder`). The spelling must be exact, capital letters
  included. The drive password still opens the whole drive.
- These are the same folders and files as in the library:
  - a file added or changed is read and indexed again (search by words and by meaning), just like an upload from the app;
  - a file renamed or moved keeps its index, under its new name;
  - a deleted file or folder goes to the library's trash (30 days).
- Folders of **Secret** spaces do not appear. The FAQ is read-only.
- System files (`.DS_Store`, `._…`, `Thumbs.db`, `desktop.ini`…) are kept aside, outside the library.
- Two files with the same name in a folder: the most recent keeps its name, the others show as "name (#id).ext".
- Ten different wrong passwords from the same address block it for 15 minutes; the right password still gets in.
  A device retrying an old saved password counts once. When an address is blocked, Settings › Network drive says
  so, with **Unblock now**; creating a new drive password also unblocks every address.
- "The current password is wrong." when creating a drive password: it is the app password you typed that was refused; nothing has changed, and the old drive password still works.
