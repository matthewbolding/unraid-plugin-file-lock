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

Rebuild, then **remove before reinstalling** — Unraid's `plugin install`
compares the install source *path*, not file content, and always installs
from the same fixed path (`/boot/config/plugins/file.lock.plg`). So a plain
overwrite-and-reinstall is silently ignored ("not re-installing same
plugin"), `forced` included — that flag only affects a version-downgrade
check that never gets reached here:

```bash
python3 build.py file.lock file.lock.plg
plugin remove file.lock.plg          # note: the registered name includes .plg
cp file.lock.plg /boot/config/plugins/file.lock.plg
plugin install /boot/config/plugins/file.lock.plg
```

`build.py` stamps the plugin version from today's date, so every rebuild is
automatically newer than what's installed — that just doesn't matter for the
same-path check above. There's no hosted release feed, so the Plugins page
won't show an automatic "update available" prompt — updating is always this
manual rebuild → remove → reinstall.

## Uninstall

**Plugins → File Lock → Remove**, or from a terminal `plugin remove
file.lock.plg` (the registered name includes the `.plg` extension — `plugin
remove file.lock` silently no-ops, reporting success without removing
anything). Either way this deletes the plugin's code but leaves
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
- **Recursive folder operations** do one `chattr` subprocess per file and can
  be slow on very large trees; a large enough job can hit PHP's execution
  time limit. See "Known issues" below.
- **Access control** is not per-user: anyone who can reach the webGUI can
  lock/unlock anything within the configured base directory.
- **Hardlinks:** `+i` is an inode attribute, so every hardlink to a file
  reflects the same locked state.

## Known issues / roadmap

- **`Settings.php` doesn't restrict the base directory to `/mnt`** — any
  existing path is accepted, which weakens the sandbox model. Should require
  the new base to start with `/mnt/`.
- **Search:** no way to find a file across the whole base directory today;
  only per-folder browsing. A `Search.php` endpoint (recursive substring match,
  capped result count, same base/symlink guards as `Browse.php`/`Toggle.php`)
  is a natural addition.
- A confirm step on bulk **unlock** (the protection-removing direction).
- **`lsattr`/`chattr` are still one subprocess per file** in `Browse.php` and
  `Toggle.php`. Disk-path resolution is now batched per directory (see below),
  but the attribute read/write itself isn't — batching `lsattr -d -- f1 f2 ...`
  / `chattr +i -- f1 f2 ...` in chunks would cut fork/exec overhead further on
  large trees.

### Fixed

- ~~Disk-path resolution (`PathResolver::toDisk()`) re-globbed `/mnt` and
  probed every disk with `file_exists()` for each file individually, which on
  Unraid could wake spun-down array disks repeatedly.~~ Now resolves once per
  directory (`PathResolver::resolveDir()` scans each candidate disk's copy of
  the directory and builds a name→disk map), with `diskRoots()` itself cached
  per request.
- ~~Symlinks weren't excluded from directory listings or the recursive walk in
  `Toggle.php`~~, so a symlink under the base directory pointing outside it
  could be browsed into, or — since `chattr` opens its target through a
  symlink — recursively locked outside the sandbox. `Browse.php` now skips
  symlinks entirely, and `Toggle.php` rejects a directly-selected symlink and
  filters symlinks out of the recursive walk (`RecursiveCallbackFilterIterator`)
  so a symlinked subdirectory can't be traversed either.
- `build.py`'s generated removal `<FILE>` block was missing `Run="/bin/bash"`,
  so `plugin remove` reported success without actually deleting the deployed
  plugin directory — Unraid only executes a block's `INLINE` content when
  `Run=` is present; otherwise it just writes the script text to `Name` and
  stops.
