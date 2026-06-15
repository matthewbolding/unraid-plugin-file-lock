<?php
/**
 * Browse.php — list a directory (fused path) and report each file's lock state.
 *
 * POST: path (fused dir, defaults to base), csrf_token
 * Returns JSON: { base, path, parent, entries:[{name,path,dir,locked}] }
 *   locked: true = immutable, false = mutable, null = dir / unknown
 */

require_once __DIR__ . '/common.php';

$conf = filelock_config();
$base = $conf['base'];

$path = $_POST['path'] ?? $base;
$path = PathResolver::within($path, $base);
if ($path === false)  filelock_json(['error' => 'Path is outside the base directory']);
if (!is_dir($path))   filelock_json(['error' => 'Not a directory: ' . $path]);

$dh = @opendir($path);
if ($dh === false)    filelock_json(['error' => 'Cannot open directory']);

$entries = [];
while (($name = readdir($dh)) !== false) {
    if ($name === '.' || $name === '..') continue;
    $full  = rtrim($path, '/') . '/' . $name;
    $isDir = is_dir($full);
    $entry = ['name' => $name, 'path' => $full, 'dir' => $isDir, 'locked' => null];

    if (!$isDir) {
        $disk = PathResolver::toDisk($full);
        if ($disk !== false) {
            $entry['locked'] = PathResolver::isImmutable($disk);
        }
    }
    $entries[] = $entry;
}
closedir($dh);

// Folders first, then files, each alphabetical (case-insensitive).
usort($entries, function ($a, $b) {
    if ($a['dir'] !== $b['dir']) return $a['dir'] ? -1 : 1;
    return strcasecmp($a['name'], $b['name']);
});

filelock_json([
    'base'    => $base,
    'path'    => $path,
    'parent'  => ($path === PathResolver::clean($base)) ? null : dirname($path),
    'entries' => $entries,
]);
