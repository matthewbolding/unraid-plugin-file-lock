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

    /**
     * Physical mount roots that can back a user share: array disks + pools.
     * e.g. ['/mnt/disk1', '/mnt/disk2', '/mnt/cache'].
     */
    public static function diskRoots(): array {
        $roots = [];

        // Array data disks: /mnt/disk1, /mnt/disk2, ...
        foreach (glob('/mnt/disk[0-9]*', GLOB_ONLYDIR) as $d) {
            $roots[] = $d;
        }

        // Pools (cache and any named pool): everything else under /mnt that
        // isn't reserved and isn't an array disk we already added.
        foreach (glob('/mnt/*', GLOB_ONLYDIR) as $m) {
            $name = basename($m);
            if (in_array($name, self::RESERVED, true)) continue;
            if (preg_match('/^disk[0-9]+$/', $name)) continue;
            $roots[] = $m;
        }

        return $roots;
    }

    /**
     * Convert a fused path to the physical disk path that actually holds it.
     * A regular file lives on exactly one disk, so we test each root for the
     * exact path and return the first hit. Returns false if not found.
     *
     * If the path isn't a /mnt/user(0) path it's assumed to already be physical
     * and is returned unchanged.
     */
    public static function toDisk(string $fused) {
        $fused = self::clean($fused);

        if (!preg_match('#^/mnt/user0?/(.+)$#', $fused, $m)) {
            return $fused; // not a user-share path; treat as already physical
        }
        $rel = $m[1];

        foreach (self::diskRoots() as $root) {
            $cand = $root . '/' . $rel;
            if (file_exists($cand)) return $cand;
        }
        return false;
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

    /**
     * Read the immutable flag of a physical path via lsattr.
     * Returns true (locked), false (unlocked), or null (couldn't determine,
     * e.g. filesystem doesn't support attributes).
     */
    public static function isImmutable(string $diskPath) {
        $out = []; $rc = 0;
        exec('lsattr -d -- ' . escapeshellarg($diskPath) . ' 2>/dev/null', $out, $rc);
        if ($rc !== 0 || !isset($out[0])) return null;
        // Output looks like: "----i---------e----- /mnt/disk1/share/file.jpg"
        $flags = preg_split('/\s+/', trim($out[0]))[0];
        return strpos($flags, 'i') !== false;
    }
}
