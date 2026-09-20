<?php
/**
 * Delete.php — unlock (remove the immutable flag, if set) and permanently
 * delete the selected files.
 *
 * POST: paths (JSON array of fused paths), csrf_token
 * Returns JSON: { results:[{path,ok,msg}] }
 *
 * This is destructive and irreversible, unlike Toggle.php's lock/unlock.
 * The front end gates the call behind an explicit confirm() prompt; this
 * endpoint does not re-confirm anything itself (there is nothing meaningful
 * to check server-side that isn't already covered by the normal CSRF/base-
 * directory checks below), so it must never be wired up to fire without
 * that prompt having already been shown and accepted.
 *
 * Directories cannot themselves be immutable and are never deleted here —
 * only regular files, matching Toggle.php's recursive-into-a-folder
 * semantics. A selected folder's contents are unlocked and deleted
 * recursively; the (now possibly empty) folder itself is left in place.
 */

require_once __DIR__ . '/common.php';

$conf = filelock_config();
$base = $conf['base'];

$paths = json_decode($_POST['paths'] ?? '[]', true);
if (!is_array($paths)) {
    filelock_json(['error' => 'Bad request']);
}

$results = [];
$jobs    = []; // fused file path => disk path, resolved files waiting for unlock+delete

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
    // Same symlink guard as Toggle.php: chattr/unlink would act on whatever
    // the symlink points at, possibly outside the sandbox.
    if (is_link($safe)) {
        $results[] = ['path' => $p, 'ok' => false, 'msg' => 'symlinks are not followed'];
        continue;
    }
    if (is_dir($safe)) {
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

// Unlock every job first, in batches (same pattern as Toggle.php): an
// immutable file can't be unlinked, and batching keeps this fast when
// deleting many files at once. If a batch as a whole fails (e.g. one bad
// path in it), fall back to unlocking that batch's members individually so
// one bad file doesn't leave the rest immutable and un-deletable.
foreach (array_chunk($jobs, PathResolver::BATCH_SIZE, true) as $chunk) {
    $args = implode(' ', array_map('escapeshellarg', $chunk));
    $out = []; $rc = 0;
    exec("chattr -i -- $args 2>&1", $out, $rc);
    if ($rc !== 0) {
        foreach ($chunk as $disk) {
            $out2 = []; $rc2 = 0;
            exec('chattr -i -- ' . escapeshellarg($disk) . ' 2>&1', $out2, $rc2);
        }
    }
}

// Delete. unlink()'s own success/failure is the source of truth here — no
// need to branch on the chattr result above, since a file that was never
// immutable (or lives on a filesystem chattr doesn't support) still deletes
// normally, and one that's still stuck immutable will simply fail here.
foreach ($jobs as $fused => $disk) {
    if (@unlink($disk)) {
        $results[] = ['path' => $fused, 'ok' => true, 'msg' => ''];
    } else {
        $results[] = ['path' => $fused, 'ok' => false, 'msg' => 'delete failed'];
    }
}

filelock_json(['results' => $results]);
