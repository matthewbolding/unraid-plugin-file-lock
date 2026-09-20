#!/usr/bin/env php
<?php
/**
 * run.php — discover tests/*Test.php, run every registered test(), and print
 * a pass/fail summary. Exit code is nonzero if anything failed.
 *
 * Usage: php tests/run.php
 */
declare(strict_types=1);

require __DIR__ . '/framework.php';
require dirname(__DIR__) . '/include/PathResolver.php';

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$failures = 0;
$total = 0;
foreach (filelock_tests_registered() as [$name, $fn]) {
    $total++;
    try {
        $fn();
        echo "  ok  $name\n";
    } catch (\Throwable $e) {
        $failures++;
        echo "FAIL  $name\n";
        echo "      " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

printf("\n%d tests, %d passed, %d failed.\n", $total, $total - $failures, $failures);
exit($failures === 0 ? 0 : 1);
