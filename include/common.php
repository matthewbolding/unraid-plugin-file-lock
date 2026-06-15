<?php
/**
 * common.php — shared bootstrap for the AJAX endpoints.
 * Loads the resolver and the plugin config. CSRF is handled by Unraid globally.
 */

require_once __DIR__ . '/PathResolver.php';

const FILELOCK_CFG = '/boot/config/plugins/file.lock/file.lock.cfg';

function filelock_config(): array {
    $cfg = is_file(FILELOCK_CFG) ? @parse_ini_file(FILELOCK_CFG) : [];
    return [
        'base' => $cfg['BASEDIR'] ?? '/mnt/user/media/video',
    ];
}

// CSRF is validated globally by Unraid's webGUI before any plugin endpoint
// runs, and the validated token is then stripped from $_POST. Endpoints
// therefore do NOT re-check it (doing so fails, since the token is already
// gone). The browser must still SEND csrf_token so the request passes
// Unraid's gate in the first place.


function filelock_json($data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
