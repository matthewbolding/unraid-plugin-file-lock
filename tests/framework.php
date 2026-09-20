<?php
/**
 * framework.php — a deliberately tiny, dependency-free test harness.
 *
 * The plugin itself has zero third-party dependencies (see build.py's
 * docstring), so pulling in PHPUnit/Composer for a handful of test files
 * would be a heavier footprint than the thing it's testing. A test file
 * calls test($name, $fn); run.php collects and executes them.
 */
declare(strict_types=1);

final class TestFailure extends \Exception {}

$GLOBALS['__filelock_tests'] = [];

function test(string $name, callable $fn): void {
    $GLOBALS['__filelock_tests'][] = [$name, $fn];
}

function filelock_tests_registered(): array {
    return $GLOBALS['__filelock_tests'];
}

function assert_same($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new TestFailure(sprintf(
            "%sexpected %s, got %s",
            $msg === '' ? '' : "$msg: ",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assert_true($actual, string $msg = ''): void {
    assert_same(true, $actual, $msg);
}

function assert_false($actual, string $msg = ''): void {
    assert_same(false, $actual, $msg);
}

function assert_null($actual, string $msg = ''): void {
    assert_same(null, $actual, $msg);
}

/** Recursively create $path/a/b/c, writing $contents into each leaf file listed in $files. */
function filelock_test_make_tree(string $root, array $files): void {
    foreach ($files as $rel => $contents) {
        $full = $root . '/' . ltrim($rel, '/');
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, $contents);
    }
}

/** Recursively delete a directory tree — used to clean up fixture roots after each test. */
function filelock_test_rrmdir(string $dir): void {
    if (!is_dir($dir) || is_link($dir)) { @unlink($dir); return; }
    foreach (scandir($dir) as $name) {
        if ($name === '.' || $name === '..') continue;
        $full = $dir . '/' . $name;
        is_dir($full) && !is_link($full) ? filelock_test_rrmdir($full) : unlink($full);
    }
    rmdir($dir);
}
