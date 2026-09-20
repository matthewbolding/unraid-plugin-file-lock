<?php
/**
 * PathResolver.php
 *
 * Maps between Unraid's fused user-share paths (/mnt/user/...) and the
 * physical disk paths that actually back each file (/mnt/disk1/..., /mnt/cache/...).
 *
 * chattr / lsattr only work on the real on-disk path, because the shfs FUSE
 * layer that presents /mnt/user does not implement the ioctls they use.
 * The UI only ever shows the fused path; everything below resolves it.
 */

class PathResolver {

    /** Directory names directly under /mnt that are NOT array disks or pools. */
    const RESERVED = ['user', 'user0', 'disks', 'remotes', 'addons', 'rootshare'];

    /** Per-request cache for diskRoots(), which is otherwise re-globbed constantly. Keyed by $mnt. */
    private static array $diskRootsCache = [];

    /**
     * Physical mount roots that can back a user share: array disks + pools.
     * e.g. ['/mnt/disk1', '/mnt/disk2', '/mnt/cache'].
     *
     * $mnt is injectable (defaults to the real '/mnt') purely so tests can
     * point this at a throwaway fixture tree instead of the live array.
     */
    public static function diskRoots(string $mnt = '/mnt'): array {
        if (isset(self::$diskRootsCache[$mnt])) return self::$diskRootsCache[$mnt];

        $roots = [];

        // Array data disks: $mnt/disk1, $mnt/disk2, ...
        foreach (glob("$mnt/disk[0-9]*", GLOB_ONLYDIR) as $d) {
            $roots[] = $d;
        }

        // Pools (cache and any named pool): everything else under $mnt that
        // isn't reserved and isn't an array disk we already added.
        foreach (glob("$mnt/*", GLOB_ONLYDIR) as $m) {
            $name = basename($m);
            if (in_array($name, self::RESERVED, true)) continue;
            if (preg_match('/^disk[0-9]+$/', $name)) continue;
            $roots[] = $m;
        }

        return self::$diskRootsCache[$mnt] = $roots;
    }

    /** Per-request cache for resolveDir(): [$mnt][fused directory] -> [filename => diskPath]. */
    private static array $dirCache = [];

    /**
     * Resolve every entry directly inside a fused directory to its physical
     * disk path in one pass: scan each disk's copy of the directory once
     * (readdir), instead of probing every disk with file_exists() for each
     * file individually. On Unraid this also avoids waking a spun-down disk
     * once per file that isn't even on it.
     *
     * First disk found wins, matching the previous first-hit semantics of
     * toDisk(). Result is cached per directory for the life of the request,
     * since callers (e.g. Browse.php's per-entry lookup, or a recursive
     * Toggle.php walk) commonly resolve many files from the same directory.
     */
    public static function resolveDir(string $fusedDir, string $mnt = '/mnt'): array {
        $fusedDir = self::clean($fusedDir);
        if (isset(self::$dirCache[$mnt][$fusedDir])) return self::$dirCache[$mnt][$fusedDir];

        $map = [];
        if (preg_match('#^/mnt/user0?/(.*)$#', $fusedDir, $m)) {
            $rel = $m[1];
            foreach (self::diskRoots($mnt) as $root) {
                $cand = $rel === '' ? $root : $root . '/' . $rel;
                if (!is_dir($cand)) continue;
                $dh = @opendir($cand);
                if ($dh === false) continue;
                while (($name = readdir($dh)) !== false) {
                    if ($name === '.' || $name === '..') continue;
                    if (!isset($map[$name])) $map[$name] = $cand . '/' . $name;
                }
                closedir($dh);
            }
        }
        return self::$dirCache[$mnt][$fusedDir] = $map;
    }

    /**
     * Convert a fused path to the physical disk path that actually holds it,
     * via the directory-level cache above. Returns false if not found.
     *
     * If the path isn't a /mnt/user(0) path it's assumed to already be physical
     * and is returned unchanged.
     */
    public static function toDisk(string $fused, string $mnt = '/mnt') {
        $fused = self::clean($fused);

        if (!preg_match('#^/mnt/user0?/(.+)$#', $fused)) {
            return $fused; // not a user-share path; treat as already physical
        }

        $map = self::resolveDir(dirname($fused), $mnt);
        return $map[basename($fused)] ?? false;
    }

    /**
     * Normalise a path: force leading slash, collapse '.' and '..', strip
     * duplicate slashes. Does NOT require the path to exist (unlike realpath),
     * so it is safe to run on user input before touching the filesystem.
     */
    public static function clean(string $path): string {
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') { array_pop($parts); continue; }
            $parts[] = $seg;
        }
        return '/' . implode('/', $parts);
    }

    /**
     * Guard against directory traversal: confirm $path resolves to somewhere
     * at or below $base (both fused paths). Returns the cleaned path, or false
     * if it escapes the sandbox.
     */
    public static function within(string $path, string $base) {
        $path = self::clean($path);
        $base = self::clean($base);
        if ($path === $base) return $path;
        if (strpos($path, rtrim($base, '/') . '/') === 0) return $path;
        return false;
    }

    /** Max paths per lsattr/chattr subprocess call, well under a typical ARG_MAX. */
    const BATCH_SIZE = 200;

    /**
     * Read the immutable flag of a physical path via lsattr.
     * Returns true (locked), false (unlocked), or null (couldn't determine,
     * e.g. filesystem doesn't support attributes).
     */
    public static function isImmutable(string $diskPath) {
        return self::isImmutableBatch([$diskPath])[$diskPath] ?? null;
    }

    /**
     * Read the immutable flag of many physical paths at once: a handful of
     * `lsattr` subprocesses (chunked to stay under ARG_MAX) instead of one
     * per path, which matters once a directory has hundreds of files.
     *
     * Returns [diskPath => true|false|null], null meaning the filesystem
     * couldn't report attributes for that path (lsattr omits it from output
     * rather than failing the whole batch).
     */
    public static function isImmutableBatch(array $diskPaths): array {
        $status = [];
        foreach (array_unique($diskPaths) as $p) $status[$p] = null;
        if (!$status) return $status;

        foreach (array_chunk(array_keys($status), self::BATCH_SIZE) as $chunk) {
            $args = implode(' ', array_map('escapeshellarg', $chunk));
            $out = []; $rc = 0;
            exec("lsattr -d -- $args 2>/dev/null", $out, $rc);
            foreach ($out as $line) {
                $parsed = self::parseLsattrLine($line);
                if ($parsed === null) continue;
                [$path, $immutable] = $parsed;
                $status[$path] = $immutable;
            }
        }
        return $status;
    }

    /**
     * Parse one line of `lsattr -d` output, e.g.
     * "----i---------e----- /mnt/disk1/share/file.jpg".
     * Returns [diskPath, immutable] or null if the line doesn't match the
     * expected shape (kept separate from isImmutableBatch so it's testable
     * without actually shelling out to lsattr).
     */
    public static function parseLsattrLine(string $line): ?array {
        if (!preg_match('/^(\S+)\s+(.*)$/', $line, $m)) return null;
        return [$m[2], strpos($m[1], 'i') !== false];
    }
}
