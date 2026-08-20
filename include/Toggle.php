<?php
/**
 * Toggle.php — apply or remove the immutable flag on the selected items.
 *
 * POST: action ('lock'|'unlock'), paths (JSON array of fused paths), csrf_token
 * Returns JSON: { action, results:[{path,ok,msg}] }
 *
 * Directories cannot themselves be immutable, so a selected directory is
 * treated as "apply to every regular file beneath it" (recursive).
 */

require_once __DIR__ . '/common.php';

$conf = filelock_config();
$base = $conf['base'];

$action = $_POST['action'] ?? '';
$paths  = json_decode($_POST['paths'] ?? '[]', true);

if (!in_array($action, ['lock', 'unlock'], true) || !is_array($paths)) {
    filelock_json(['error' => 'Bad request']);
}
$flag = $action === 'lock' ? '+i' : '-i';

$results = [];

/** Apply the flag to a single fused file path. */
$applyFile = function (string $fused) use (&$results, $flag, $base) {
    $safe = PathResolver::within($fused, $base);
    if ($safe === false) {
        $results[] = ['path' => $fused, 'ok' => false, 'msg' => 'outside base directory'];
        return;
    }
    $disk = PathResolver::toDisk($safe);
    if ($disk === false || !is_file($disk)) {
        $results[] = ['path' => $fused, 'ok' => false, 'msg' => 'file not found on any disk'];
        return;
    }
    $out = []; $rc = 0;
    exec('chattr ' . $flag . ' -- ' . escapeshellarg($disk) . ' 2>&1', $out, $rc);
    $results[] = ['path' => $fused, 'ok' => ($rc === 0), 'msg' => $rc === 0 ? '' : implode(' ', $out)];
};

foreach ($paths as $p) {
    $safe = PathResolver::within($p, $base);
    if ($safe === false) {
        $results[] = ['path' => $p, 'ok' => false, 'msg' => 'outside base directory'];
        continue;
    }
    // chattr opens its target through a symlink, so locking a symlink would
    // actually flag whatever it points at — possibly outside the sandbox.
    if (is_link($safe)) {
        $results[] = ['path' => $p, 'ok' => false, 'msg' => 'symlinks are not followed'];
        continue;
    }
    if (is_dir($safe)) {
        // Recurse into the directory and flag every regular file. The filter
        // excludes symlinks from both the walk and recursion, so a symlinked
        // subdirectory can't be used to walk outside the sandbox and a
        // symlinked file can't be used to chattr its target.
        $dirIter = new RecursiveDirectoryIterator($safe, FilesystemIterator::SKIP_DOTS);
        $filter  = new RecursiveCallbackFilterIterator($dirIter, fn($cur) => !$cur->isLink());
        $it = new RecursiveIteratorIterator($filter);
        foreach ($it as $f) {
            if ($f->isFile()) $applyFile($f->getPathname());
        }
    } else {
        $applyFile($safe);
    }
}

filelock_json(['action' => $action, 'results' => $results]);
