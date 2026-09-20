#!/usr/bin/env python3
"""Build a self-contained Unraid .plg from the file.lock source tree.

There is nothing to "compile" — this just inlines every source file into one
.plg installer that Unraid rewrites to disk on each boot.

Usage:
    python3 build.py [SRC_DIR] [OUT_FILE]

Defaults:
    SRC_DIR  = ./file.lock      (folder holding include/, js/, styles/, file.lock.page)
    OUT_FILE = ./file.lock.plg

Example — build straight from your live plugin folder onto the flash:
    python3 build.py /usr/local/emhttp/plugins/file.lock /boot/config/plugins/file.lock.plg
"""
import os, sys, datetime

SRC = sys.argv[1] if len(sys.argv) > 1 else "file.lock"
OUT = sys.argv[2] if len(sys.argv) > 2 else "file.lock.plg"

PLUGIN     = "file.lock"
DEST       = f"/usr/local/emhttp/plugins/{PLUGIN}"
VERSION    = datetime.date.today().strftime("%Y.%m.%d")
AUTHOR     = "Matthew Bolding"
PLUGIN_URL = f"https://raw.githubusercontent.com/matthewbolding/unraid-plugin-file-lock/master/{PLUGIN}.plg"

# (path relative to SRC, absolute destination on the server)
FILES = [
    ("include/PathResolver.php", f"{DEST}/include/PathResolver.php"),
    ("include/common.php",       f"{DEST}/include/common.php"),
    ("include/Browse.php",       f"{DEST}/include/Browse.php"),
    ("include/Toggle.php",       f"{DEST}/include/Toggle.php"),
    ("include/Delete.php",       f"{DEST}/include/Delete.php"),
    ("include/Search.php",       f"{DEST}/include/Search.php"),
    ("include/Settings.php",     f"{DEST}/include/Settings.php"),
    ("file.lock.page",           f"{DEST}/file.lock.page"),
    ("js/file.lock.js",          f"{DEST}/js/file.lock.js"),
    ("styles/file.lock.css",     f"{DEST}/styles/file.lock.css"),
]

def cdata(text):
    if "]]>" in text:
        sys.exit("ERROR: a source file contains ']]>' which would break CDATA")
    # No leading newline: the .page header block must start at column 0.
    return "<![CDATA[" + text.rstrip("\n") + "\n]]>"

blocks = []
for rel, dest in FILES:
    with open(os.path.join(SRC, rel), encoding="utf-8") as fh:
        content = fh.read()
    blocks.append(f'<FILE Name="{dest}">\n<INLINE>\n{cdata(content)}\n</INLINE>\n</FILE>\n')

# First-run setup: create default config (only if absent) and fix permissions.
blocks.append(f'''<FILE Run="/bin/bash">
<INLINE>
mkdir -p /boot/config/plugins/{PLUGIN}
[ -f /boot/config/plugins/{PLUGIN}/{PLUGIN}.cfg ] || echo 'BASEDIR="/mnt/user/media/video"' > /boot/config/plugins/{PLUGIN}/{PLUGIN}.cfg
chmod -R 755 {DEST}
echo ""
echo "File Lock {VERSION} installed. Open it under Tools in the webGUI."
echo ""
</INLINE>
</FILE>
''')

# Removal: drop the plugin code; keep the saved base-dir config.
# Run="/bin/bash" is required for the INLINE block to actually execute --
# without it the plugin manager just writes the script text to Name and
# never runs it, so "plugin remove" reports success but removes nothing.
blocks.append(f'''<FILE Name="/tmp/{PLUGIN}-remove" Run="/bin/bash" Method="remove">
<INLINE>
rm -rf {DEST}
rm -f /boot/config/plugins/{PLUGIN}.plg
</INLINE>
</FILE>
''')

plg = f'''<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name      "{PLUGIN}">
<!ENTITY author    "{AUTHOR}">
<!ENTITY version   "{VERSION}">
<!ENTITY pluginURL "{PLUGIN_URL}">
<!ENTITY launch    "Tools/FileLock">
]>

<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="&pluginURL;" launch="&launch;">

<CHANGES>
### {VERSION}
- Built from source.
</CHANGES>

{''.join(blocks)}
</PLUGIN>
'''

with open(OUT, "w", encoding="utf-8") as fh:
    fh.write(plg)
print(f"Wrote {OUT} ({os.path.getsize(OUT)} bytes) from {SRC}/")
