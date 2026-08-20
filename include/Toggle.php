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
$jobs    = []; // fused file path => disk path, resolved files waiting for chattr

/** Resolve a single fused file path to its disk path, or record why it can't be done. */
$prepareFile = function (string $fused) use (&$results, &$jobs, $base) {
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
    $jobs[$fused] = $disk;
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
            if ($f->isFile()) $prepareFile($f->getPathname());
        }
    } else {
        $prepareFile($safe);
    }
}

// Apply the flag in batches -- a handful of chattr subprocesses instead of
// one per file. If a batch as a whole fails (e.g. one bad path in it), fall
// back to running that batch's members individually so one bad file doesn't
// mark the rest as failed too.
foreach (array_chunk($jobs, PathResolver::BATCH_SIZE, true) as $chunk) {
    $args = implode(' ', array_map('escapeshellarg', $chunk));
    $out = []; $rc = 0;
    exec("chattr $flag -- $args 2>&1", $out, $rc);
    if ($rc === 0) {
        foreach ($chunk as $fused => $disk) {
            $results[] = ['path' => $fused, 'ok' => true, 'msg' => ''];
        }
        continue;
    }
    foreach ($chunk as $fused => $disk) {
        $out2 = []; $rc2 = 0;
        exec('chattr ' . $flag . ' -- ' . escapeshellarg($disk) . ' 2>&1', $out2, $rc2);
        $results[] = ['path' => $fused, 'ok' => ($rc2 === 0), 'msg' => $rc2 === 0 ? '' : implode(' ', $out2)];
    }
}

filelock_json(['action' => $action, 'results' => $results]);
