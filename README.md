# File Lock — Unraid plugin

A GUI to apply/remove the immutable flag (`chattr +i` / `chattr -i`) on files.
The browser shows **fused** paths (`/mnt/user/...`); the backend resolves each
file to its **physical** disk path (`/mnt/disk2/...`) before running `chattr`,
because the shfs FUSE layer doesn't support the attribute ioctls.

- **Green / "locked"** = immutable (`+i`)
- **Red / "unlocked"** = mutable (`-i`)
- Folders navigate on click; the checkbox selects. Selecting a **folder** and
  hitting Lock/Unlock applies the flag to **every file inside it, recursively**
  (directories themselves can't be made immutable).
- The browser is sandboxed to a configurable **base directory** and below.

## Files

```
file.lock/
├── file.lock.page              webGUI page (appears under Tools → File Lock)
├── include/
│   ├── PathResolver.php         fused<->disk mapping + immutable check
│   ├── common.php               config loader + CSRF guard
│   ├── Browse.php               directory listing endpoint
│   ├── Toggle.php               lock/unlock endpoint
│   └── Settings.php             save base directory
├── js/file.lock.js             front-end
└── styles/file.lock.css        styling
```

`file.lock.plg` is a self-contained installer that writes all of the above
inline — no GitHub or `.txz` hosting required.

---

## Deploy — Option A: live development (fast iteration)

The plugins directory lives in RAM (`/usr/local/emhttp/plugins`), so edits show
up on a browser refresh but are **lost on reboot**. Great for iterating, then
bake the result into the `.plg` (Option B) for persistence.

1. Put `file.lock-source.tar.gz` somewhere on the server (e.g. a share).
2. Extract it into the plugins dir:
   ```bash
   tar -xzf file.lock-source.tar.gz -C /usr/local/emhttp/plugins/
   chmod -R 755 /usr/local/emhttp/plugins/file.lock
   mkdir -p /boot/config/plugins/file.lock
   [ -f /boot/config/plugins/file.lock/file.lock.cfg ] || \
     echo 'BASEDIR="/mnt/user"' > /boot/config/plugins/file.lock/file.lock.cfg
   ```
3. Open the webGUI -> **Tools -> File Lock**. Edit files in place and refresh.

> If it doesn't appear under Tools, hard-refresh the webGUI. The location is set
> by `Menu="Utilities"` at the top of `file.lock.page` — change it if you'd
> rather it live elsewhere.

## Deploy — Option B: persistent install (survives reboot)

1. Copy `file.lock.plg` to `/boot/config/plugins/` on the server (the flash drive).
2. In the webGUI: **Plugins -> Install Plugin**, paste the path
   `/boot/config/plugins/file.lock.plg`, and Install. (Or from a terminal:
   `plugin install /boot/config/plugins/file.lock.plg`.)
3. On every boot Unraid re-runs the `.plg`, which rewrites the files from the
   inline blocks. Your saved base directory in
   `/boot/config/plugins/file.lock/file.lock.cfg` is preserved.

To **uninstall**: Plugins -> File Lock -> Remove (your saved base-dir config is
kept; delete `/boot/config/plugins/file.lock/` by hand if you want it gone too).

### Rebuilding the .plg after edits

The `.plg` is generated from the source tree by `build_plg.py`. After editing
source files, re-run it to regenerate a fresh inline installer.

---

## Should I use the VS Code community app?

Yes — it's the fastest way to iterate. The LinuxServer **code-server**
container in Community Applications is actively maintained and gives you a full
VS Code in the browser.

One catch: by default the container only sees the paths you map into it. To
edit the live plugin, add a volume mapping for `/usr/local/emhttp/plugins`
(host) -> some path in the container, then edit there and refresh the webGUI.
Editing the source on a share + running the Option-A extract command is often
cleaner than mapping the RAM plugins dir directly.

---

## Caveats worth knowing

- **Filesystem support:** `chattr +i` works on XFS, BTRFS, and ZFS (typical
  Unraid array/pool filesystems). It does **not** work on FAT/exFAT. Files whose
  filesystem can't report attributes show status **n/a**.
- **Mover interaction:** an immutable file sitting on a cache/pool can't be moved
  or deleted until you remove `+i`. If you lock files on cache, mover will skip
  them. Lock files that already live on the array if you want mover to behave.
- **Immutable is absolute:** while `+i` is set, *nothing* — not root, not Docker,
  not SMB clients — can modify, rename, or delete the file. That's the point, but
  it also means an app trying to write a locked file will get permission errors.
- **Recursive folder ops** can be slow on very large photo trees (one
  resolve + `chattr` per file). A huge recursive job could hit PHP's execution
  time limit; if your libraries are massive, consider locking per-subfolder, or
  ask me to add chunked/background processing.
- **Access control:** anyone who can reach the Unraid webGUI can lock/unlock
  within the base directory. The base-dir sandbox + path-traversal guard keep it
  from wandering outside that root, but it's not per-user.
- **Hardlinks:** `+i` is an inode attribute, so every hardlink to a file
  reflects the same locked state.

## Ideas you might want next

- A one-click **"lock entire current folder"** button.
- A confirm step on bulk **unlock** (the protection-removing direction).
- Show the resolved physical disk path as a row tooltip for debugging.
- Batch `lsattr` per directory instead of per file, to speed up listings of
  folders with thousands of photos.
# unraid-plugin-file-lock
