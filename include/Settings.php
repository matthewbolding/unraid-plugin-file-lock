<?php
/**
 * Settings.php — persist the configurable base directory.
 *
 * POST: base (fused path), csrf_token
 * Returns JSON: { ok, base } or { ok:false, msg }
 */

require_once __DIR__ . '/common.php';

$base = PathResolver::clean($_POST['base'] ?? '/mnt/user');

// The whole app's safety model is "sandboxed to base and below", so the base
// itself must live under /mnt (array disks, pools, user shares) -- anything
// else would defeat the sandbox rather than define it.
if (strpos($base, '/mnt/') !== 0) {
    filelock_json(['ok' => false, 'msg' => 'Base directory must be under /mnt']);
}

if (!is_dir($base)) {
    filelock_json(['ok' => false, 'msg' => 'Directory does not exist: ' . $base]);
}

$dir = dirname(FILELOCK_CFG);
if (!is_dir($dir)) @mkdir($dir, 0755, true);

$ok = @file_put_contents(FILELOCK_CFG, "BASEDIR=\"$base\"\n");
filelock_json($ok !== false
    ? ['ok' => true, 'base' => $base]
    : ['ok' => false, 'msg' => 'Could not write config to flash']);
