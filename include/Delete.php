<?php
/**
 * Delete.php — unlock (remove the immutable flag, if set) and permanently
 * delete the selected files.
 *
 * POST: paths (JSON array of fused file paths), csrf_token
 * Returns JSON: { results:[{path,ok,msg}] }
 *
 * This is destructive and irreversible, unlike Toggle.php's lock/unlock.
 * The front end gates the call behind an explicit confirm() prompt; this
 * endpoint does not re-confirm anything itself (there is nothing meaningful
 * to check server-side that isn't already covered by the normal CSRF/base-
 * directory checks below), so it must never be wired up to fire without
 * that prompt having already been shown and accepted.
 *
 * Files only: a selected directory is rejected rather than recursed into.
 * (An earlier version did recurse, deleting every file inside a folder but
 * never the folder itself, which looked like "nothing happened" once the
 * now-empty/partly-emptied folder was still sitting right there. Since
 * directories can't be made immutable in the first place, there's no
 * "unlock" half of "unlock & delete" for one anyway -- restricting this to
 * files keeps the button's behavior unambiguous.) The front end disables
 * the button whenever a folder is selected; this check is the real
 * enforcement, in case this endpoint is ever reached some other way.
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

foreach ($paths as $p) {
    $safe = PathResolver::within($p, $base);
    if ($safe === false) {
        $results[] = ['path' => $p, 'ok' => false, 'msg' => 'outside base directory'];
        continue;
    }
    // chattr/unlink would act on whatever a symlink points at, possibly
    // outside the sandbox -- same guard as Toggle.php.
    if (is_link($safe)) {
        $results[] = ['path' => $p, 'ok' => false, 'msg' => 'symlinks are not followed'];
        continue;
    }
    if (is_dir($safe)) {
        $results[] = ['path' => $p, 'ok' => false, 'msg' => 'folders are not supported -- select individual files'];
        continue;
    }
    $disk = PathResolver::toDisk($safe);
    if ($disk === false || !is_file($disk)) {
        $results[] = ['path' => $p, 'ok' => false, 'msg' => 'file not found on any disk'];
        continue;
    }
    $jobs[$p] = $disk;
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
