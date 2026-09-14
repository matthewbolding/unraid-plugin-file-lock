# File Lock — Unraid plugin

A webGUI page to apply or remove the immutable flag (`chattr +i` / `chattr -i`) on files, from a browser, without dropping to a terminal.

- **Green / "locked"** = immutable (`+i`)
- **Red / "unlocked"** = mutable (`-i`)
- Click a folder to navigate into it; click the checkbox to select it. Selecting a **folder** and hitting Lock/Unlock applies the flag to **every file inside it, recursively** (directories themselves can't be made immutable).
- The search box finds files/folders by name anywhere under the current folder, not just the current listing.
- The browser is sandboxed to a configurable **base directory** and below.

The webGUI shows **fused** paths (`/mnt/user/...`); the backend resolves each file to its **physical** disk path (`/mnt/disk2/...`, `/mnt/cache/...`) before running `chattr`, because the shfs FUSE layer that presents `/mnt/user` doesn't implement the ioctls `chattr`/`lsattr` need.

## Layout

```
file.lock/
├── file.lock.page              webGUI page (Tools → File Lock)
├── include/
│   ├── PathResolver.php         fused <-> disk path mapping + immutable check
│   ├── common.php               config loader
│   ├── Browse.php               directory listing endpoint
│   ├── Toggle.php               lock/unlock endpoint
│   ├── Search.php               recursive name search endpoint
│   └── Settings.php             save base directory
├── js/file.lock.js             front end
└── styles/file.lock.css        styling
```

`build.py` inlines all of the above into a single self-contained `file.lock.plg` installer — no GitHub releases or hosted `.txz` package required.

## Requirements

`chattr +i` works on XFS, BTRFS, and ZFS (the typical Unraid array/pool filesystems). It does **not** work on FAT/exFAT — files there show status **n/a**.

## Install

1. Build the installer from source:
   ```bash
   python3 build.py file.lock file.lock.plg
   ```
2. Copy `file.lock.plg` to `/boot/config/plugins/` on the flash drive.
3. Install it — either in the webGUI (**Plugins → Install Plugin**, paste `/boot/config/plugins/file.lock.plg`) or from a terminal:
   ```bash
   plugin install /boot/config/plugins/file.lock.plg
   ```

Unraid re-runs every `.plg` on `/boot/config/plugins/` on each boot, which rewrites the plugin's files from the `.plg`'s inline copies — that's what makes the install persist across reboots. Your saved base directory (`/boot/config/plugins/file.lock/file.lock.cfg`) is written once and never overwritten by a later install, so it survives too.

## Updating

Rebuild, then **remove before reinstalling** — Unraid's `plugin install` compares the install source *path*, not file content, and always installs from the same fixed path (`/boot/config/plugins/file.lock.plg`). So a plain overwrite-and-reinstall is silently ignored ("not re-installing same plugin"), `forced` included — that flag only affects a version-downgrade check that never gets reached here:

```bash
python3 build.py file.lock file.lock.plg
plugin remove file.lock.plg          # note: the registered name includes .plg
cp file.lock.plg /boot/config/plugins/file.lock.plg
plugin install /boot/config/plugins/file.lock.plg
```

`build.py` stamps the plugin version from today's date, so every rebuild is automatically newer than what's installed — that just doesn't matter for the same-path check above. There's no hosted release feed, so the Plugins page won't show an automatic "update available" prompt — updating is always this manual rebuild → remove → reinstall.

## Uninstall

**Plugins → File Lock → Remove**, or from a terminal `plugin remove file.lock.plg` (the registered name includes the `.plg` extension — `plugin remove file.lock` silently no-ops, reporting success without removing anything). Either way this deletes the plugin's code but leaves `/boot/config/plugins/file.lock/file.lock.cfg` (your saved base directory) in place; delete that by hand if you want it gone too.

## Developing

The live plugins directory (`/usr/local/emhttp/plugins`) lives in RAM, so edits there show up on a browser refresh but are lost on reboot — good for fast iteration, then bake the result into the `.plg` for persistence:

```bash
tar -xzf file.lock-source.tar.gz -C /usr/local/emhttp/plugins/
chmod -R 755 /usr/local/emhttp/plugins/file.lock
```

Then open **Tools → File Lock** in the webGUI and hard-refresh after each edit. If you're editing over a code-server / VS Code container, map `/usr/local/emhttp/plugins` into it, or edit on a share and re-run the extract command above.

## Testing

`tests/` covers the sandboxing and path-resolution logic in `include/PathResolver.php` (fused-path cleaning, the `within()` traversal guard, fused-to-disk resolution, and `lsattr` output parsing) with a small dependency-free harness — no PHPUnit/Composer, matching the zero-dependency approach `build.py` already takes. The disk-resolution tests point at a throwaway temp directory rather than the real `/mnt`, so they never touch actual array data. Run them with:

```bash
php tests/run.php
```

`Browse.php`/`Toggle.php`/`Search.php`/`Settings.php` aren't covered here — they're thin AJAX endpoints over `$_POST`, real disk I/O, and the live config file, so they're better exercised by hand through the webGUI than mocked in a unit test.

## Caveats

- **Mover interaction:** an immutable file on cache or a pool storage can't be moved or deleted until `+i` is removed. The mover will therefore skip locked files.
- **Immutable is absolute:** while `+i` is set no entity or user, including the root user, Docker, SMB clients, etc., can modify, rename, or delete the file. Any app trying to write to a locked file will likewise get a permission error.
- **Recursive folder operations** batch `chattr` in chunks rather than one subprocess per file, but a large enough tree can still hit PHP's execution time limit. See "Known issues" below.
- **Access control** is not per-user: anyone who can reach the webGUI can lock/unlock anything within the configured base directory.
- **Hardlinks:** `+i` is an inode attribute, so every hardlink to a file reflects the same locked state.

## Known issues / roadmap

- A very large recursive lock/unlock or search can still hit PHP's execution time limit, since it's all done within one request. Chunking the work across multiple requests from the JS side (with a progress indicator) would remove that ceiling.
