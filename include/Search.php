<?php
/**
 * Search.php — recursively find files/folders under a fused directory whose
 * name contains a query string, reporting each match's lock state.
 *
 * POST: q (query string), path (fused dir to search from, defaults to base),
 *       csrf_token
 * Returns JSON: { base, path, query, results:[{name,path,dir,locked}], truncated }
 *   truncated: true if the scan or result count hit its cap before finishing
 */

require_once __DIR__ . '/common.php';

const FILELOCK_SEARCH_MAX_RESULTS = 500;
const FILELOCK_SEARCH_MAX_SCAN    = 20000;

$conf = filelock_config();
$base = $conf['base'];

$q = trim($_POST['q'] ?? '');
if ($q === '') filelock_json(['error' => 'Empty search query']);

$path = $_POST['path'] ?? $base;
$path = PathResolver::within($path, $base);
if ($path === false) filelock_json(['error' => 'Path is outside the base directory']);
if (is_link($path))  filelock_json(['error' => 'Symlinks are not followed']);
if (!is_dir($path))  filelock_json(['error' => 'Not a directory: ' . $path]);

$results   = [];
$scanned   = 0;
$truncated = false;

// Same symlink guard as Browse/Toggle: a symlinked subdirectory could walk
// outside the sandbox, so it's excluded from both the walk and recursion.
$dirIter = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
$filter  = new RecursiveCallbackFilterIterator($dirIter, fn($cur) => !$cur->isLink());
$it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

foreach ($it as $f) {
    if (++$scanned > FILELOCK_SEARCH_MAX_SCAN) { $truncated = true; break; }
    if (stripos($f->getFilename(), $q) === false) continue;

    $results[] = ['name' => $f->getFilename(), 'path' => $f->getPathname(), 'dir' => $f->isDir(), 'locked' => null];
    if (count($results) >= FILELOCK_SEARCH_MAX_RESULTS) { $truncated = true; break; }
}

// Batch-resolve lock status for every matched file in one/few lsattr calls,
// same as Browse.php, rather than one subprocess per match.
$disks = [];
foreach ($results as $r) {
    if ($r['dir']) continue;
    $disk = PathResolver::toDisk($r['path']);
    if ($disk !== false) $disks[$r['path']] = $disk;
}
$status = PathResolver::isImmutableBatch(array_values($disks));
foreach ($results as &$r) {
    if (isset($disks[$r['path']])) $r['locked'] = $status[$disks[$r['path']]] ?? null;
}
unset($r);

usort($results, function ($a, $b) {
    if ($a['dir'] !== $b['dir']) return $a['dir'] ? -1 : 1;
    return strcasecmp($a['name'], $b['name']);
});

filelock_json([
    'base'      => $base,
    'path'      => $path,
    'query'     => $q,
    'results'   => $results,
    'truncated' => $truncated,
]);
