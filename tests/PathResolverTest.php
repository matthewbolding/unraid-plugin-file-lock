<?php
/**
 * PathResolverTest.php — covers the pure sandboxing/path logic in
 * PathResolver.php. diskRoots()/resolveDir()/toDisk() are pointed at a
 * throwaway fixture tree via their $mnt parameter rather than the real
 * /mnt, so these tests never touch the live array disks.
 */
declare(strict_types=1);

// ---- clean() ---------------------------------------------------------

test('clean() collapses duplicate slashes', function () {
    assert_same('/mnt/user/share', PathResolver::clean('/mnt//user///share'));
});

test('clean() strips . segments', function () {
    assert_same('/mnt/user/share', PathResolver::clean('/mnt/./user/./share'));
});

test('clean() resolves .. segments without touching the filesystem', function () {
    assert_same('/mnt/user', PathResolver::clean('/mnt/user/share/..'));
});

test('clean() cannot climb above root via leading ..', function () {
    assert_same('/etc', PathResolver::clean('/../../etc'));
});

test('clean() of the root path is a single slash', function () {
    assert_same('/', PathResolver::clean('/'));
});

test('clean() adds a leading slash to a relative path', function () {
    assert_same('/mnt/user', PathResolver::clean('mnt/user'));
});

// ---- within() ----------------------------------------------------------

test('within() accepts a path equal to the base', function () {
    assert_same('/mnt/user/media', PathResolver::within('/mnt/user/media', '/mnt/user/media'));
});

test('within() accepts a path below the base', function () {
    assert_same('/mnt/user/media/movies', PathResolver::within('/mnt/user/media/movies', '/mnt/user/media'));
});

test('within() rejects a path outside the base', function () {
    assert_false(PathResolver::within('/mnt/user/appdata', '/mnt/user/media'));
});

test('within() rejects a sibling directory with a matching name prefix', function () {
    // /mnt/user/media-private must NOT be considered "within" /mnt/user/media.
    assert_false(PathResolver::within('/mnt/user/media-private/secret', '/mnt/user/media'));
});

test('within() rejects a .. escape attempt', function () {
    assert_false(PathResolver::within('/mnt/user/media/../../appdata', '/mnt/user/media'));
});

test('within() normalises both sides before comparing', function () {
    assert_same('/mnt/user/media/movies', PathResolver::within('/mnt//user/media//movies', '/mnt/user/media/'));
});

// ---- parseLsattrLine() --------------------------------------------------

test('parseLsattrLine() reports immutable when the i flag is set', function () {
    [$path, $immutable] = PathResolver::parseLsattrLine('----i---------e----- /mnt/disk1/share/file.jpg');
    assert_same('/mnt/disk1/share/file.jpg', $path);
    assert_true($immutable);
});

test('parseLsattrLine() reports mutable when the i flag is absent', function () {
    [$path, $immutable] = PathResolver::parseLsattrLine('-------------e----- /mnt/disk1/share/file.jpg');
    assert_same('/mnt/disk1/share/file.jpg', $path);
    assert_false($immutable);
});

test('parseLsattrLine() handles a path containing spaces', function () {
    [$path, $immutable] = PathResolver::parseLsattrLine('----i---------e----- /mnt/disk1/share/my file.jpg');
    assert_same('/mnt/disk1/share/my file.jpg', $path);
    assert_true($immutable);
});

test('parseLsattrLine() returns null for an unparseable line', function () {
    assert_null(PathResolver::parseLsattrLine(''));
});

// ---- diskRoots() / resolveDir() / toDisk() (fixture-backed) ------------

function filelock_with_fixture(callable $fn): void {
    $root = sys_get_temp_dir() . '/filelock-test-' . bin2hex(random_bytes(6));
    filelock_test_make_tree($root, [
        'disk1/share/only-on-disk1.txt' => 'a',
        'disk1/share/on-both.txt'       => 'from disk1',
        'disk2/share/only-on-disk2.txt' => 'b',
        'cache/share/on-both.txt'       => 'from cache',
        // Reserved/non-disk entries that diskRoots() must ignore.
        'user/placeholder.txt'  => 'x',
        'disks/placeholder.txt' => 'x',
    ]);
    try {
        $fn($root);
    } finally {
        filelock_test_rrmdir($root);
    }
}

test('diskRoots() finds numbered array disks and pools, and skips reserved names', function () {
    filelock_with_fixture(function (string $root) {
        $roots = PathResolver::diskRoots($root);
        sort($roots);
        assert_same([
            "$root/cache",
            "$root/disk1",
            "$root/disk2",
        ], $roots);
    });
});

test('resolveDir() maps filenames from every disk under a fused directory', function () {
    filelock_with_fixture(function (string $root) {
        $map = PathResolver::resolveDir('/mnt/user/share', $root);
        assert_same("$root/disk1/share/only-on-disk1.txt", $map['only-on-disk1.txt']);
        assert_same("$root/disk2/share/only-on-disk2.txt", $map['only-on-disk2.txt']);
    });
});

test('resolveDir() applies first-disk-wins when a filename exists on more than one disk', function () {
    filelock_with_fixture(function (string $root) {
        // diskRoots() lists numbered disks before pools, so disk1 must win over cache.
        $map = PathResolver::resolveDir('/mnt/user/share', $root);
        assert_same("$root/disk1/share/on-both.txt", $map['on-both.txt']);
    });
});

test('toDisk() resolves a fused file path to its physical disk path', function () {
    filelock_with_fixture(function (string $root) {
        $disk = PathResolver::toDisk('/mnt/user/share/only-on-disk2.txt', $root);
        assert_same("$root/disk2/share/only-on-disk2.txt", $disk);
    });
});

test('toDisk() returns false for a file that exists on no disk', function () {
    filelock_with_fixture(function (string $root) {
        assert_false(PathResolver::toDisk('/mnt/user/share/does-not-exist.txt', $root));
    });
});

test('toDisk() passes through a path that is already physical, unresolved', function () {
    filelock_with_fixture(function (string $root) {
        assert_same('/mnt/disk1/share/file.txt', PathResolver::toDisk('/mnt/disk1/share/file.txt', $root));
    });
});
