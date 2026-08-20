# File Lock — Unraid plugin

A webGUI page to apply or remove the immutable flag (`chattr +i` / `chattr -i`)
on files, from a browser, without dropping to a terminal.

- **Green / "locked"** = immutable (`+i`)
- **Red / "unlocked"** = mutable (`-i`)
- Click a folder to navigate into it; click the checkbox to select it. Selecting
  a **folder** and hitting Lock/Unlock applies the flag to **every file inside
  it, recursively** (directories themselves can't be made immutable).
- The browser is sandboxed to a configurable **base directory** and below.

The webGUI shows **fused** paths (`/mnt/user/...`); the backend resolves each
file to its **physical** disk path (`/mnt/disk2/...`, `/mnt/cache/...`) before
running `chattr`, because the shfs FUSE layer that presents `/mnt/user` doesn't
implement the ioctls `chattr`/`lsattr` need.

## Layout

```
file.lock/
├── file.lock.page              webGUI page (Tools → File Lock)
├── include/
│   ├── PathResolver.php         fused <-> disk path mapping + immutable check
│   ├── common.php               config loader
│   ├── Browse.php               directory listing endpoint
│   ├── Toggle.php               lock/unlock endpoint
│   └── Settings.php             save base directory
├── js/file.lock.js             front end
└── styles/file.lock.css        styling
```

`build.py` inlines all of the above into a single self-contained `file.lock.plg`
installer — no GitHub releases or hosted `.txz` package required.

## Requirements

`chattr +i` works on XFS, BTRFS, and ZFS (the typical Unraid array/pool
filesystems). It does **not** work on FAT/exFAT — files there show status
**n/a**.

## Install

1. Build the installer from source:
   ```bash
   python3 build.py file.lock file.lock.plg
   ```
2. Copy `file.lock.plg` to `/boot/config/plugins/` on the flash drive.
3. Install it — either in the webGUI (**Plugins → Install Plugin**, paste
   `/boot/config/plugins/file.lock.plg`) or from a terminal:
   ```bash
   plugin install /boot/config/plugins/file.lock.plg
   ```

Unraid re-runs every `.plg` on `/boot/config/plugins/` on each boot, which
rewrites the plugin's files from the `.plg`'s inline copies — that's what makes
the install persist across reboots. Your saved base directory
(`/boot/config/plugins/file.lock/file.lock.cfg`) is written once and never
overwritten by a later install, so it survives too.

## Updating

Rebuild and reinstall over the top — no need to remove first:

```bash
python3 build.py file.lock file.lock.plg
cp file.lock.plg /boot/config/plugins/file.lock.plg
plugin install /boot/config/plugins/file.lock.plg
```

`build.py` stamps the plugin version from today's date, so every rebuild is
automatically newer than what's installed. There's no hosted release feed, so
the Plugins page won't show an automatic "update available" prompt — updating
is always this manual rebuild-and-reinstall.

## Uninstall

**Plugins → File Lock → Remove**. This deletes the plugin's code but leaves
`/boot/config/plugins/file.lock/file.lock.cfg` (your saved base directory) in
place; delete that by hand if you want it gone too.

## Developing

The live plugins directory (`/usr/local/emhttp/plugins`) lives in RAM, so
edits there show up on a browser refresh but are lost on reboot — good for
fast iteration, then bake the result into the `.plg` for persistence:

```bash
tar -xzf file.lock-source.tar.gz -C /usr/local/emhttp/plugins/
chmod -R 755 /usr/local/emhttp/plugins/file.lock
```

Then open **Tools → File Lock** in the webGUI and hard-refresh after each edit.
If you're editing over a code-server / VS Code container, map
`/usr/local/emhttp/plugins` into it, or edit on a share and re-run the extract
command above.

## Caveats

- **Mover interaction:** an immutable file on cache/pool storage can't be moved
  or deleted until `+i` is removed. Mover will silently skip locked files —
  lock files that already live on the array if you want mover to behave
  normally.
- **Immutable is absolute:** while `+i` is set, nothing — not root, not
  Docker, not SMB clients — can modify, rename, or delete the file. That's the
  point, but any app trying to write to a locked file will get a permission
  error.
- **Recursive folder operations** do one disk-resolution + `chattr` per file
  and can be slow on large trees; a very large job can hit PHP's execution
  time limit. See "Known issues" below.
- **Access control** is not per-user: anyone who can reach the webGUI can
  lock/unlock anything within the configured base directory.
- **Hardlinks:** `+i` is an inode attribute, so every hardlink to a file
  reflects the same locked state.

## Known issues / roadmap

- **Performance:** disk-path resolution (`PathResolver::toDisk()`) re-globs
  `/mnt` and probes every disk with `file_exists()` for *each file
  individually*. On Unraid this can wake spun-down array disks repeatedly.
  Should resolve once per directory (scan each candidate disk's copy of the
  directory, build a name→disk map) instead of once per file, and batch
  `lsattr`/`chattr` calls instead of one subprocess per file.
- **Symlinks aren't excluded** from directory listings or the recursive
  walk in `Toggle.php`, so a symlink under the base directory pointing outside
  it could be browsed into or recursively locked. Should skip `is_link()`
  entries (or resolve and re-validate `realpath()`) in `Browse.php` and
  `Toggle.php`.
- **`Settings.php` doesn't restrict the base directory to `/mnt`** — any
  existing path is accepted, which weakens the sandbox model. Should require
  the new base to start with `/mnt/`.
- **Search:** no way to find a file across the whole base directory today;
  only per-folder browsing. A `Search.php` endpoint (recursive substring match,
  capped result count, same base/symlink guards) is a natural addition.
- A confirm step on bulk **unlock** (the protection-removing direction).
- Batch `lsattr` per directory instead of per file, to speed up listings of
  folders with thousands of items (folded into the performance item above).
