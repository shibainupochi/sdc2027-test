<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/en/entry/lib.php';
try {
    $cfg = entry_config(dirname(__DIR__));
    $db = entry_db($cfg['storage_dir']);
    $send = in_array('--send', $argv, true);
    foreach ($db->query('SELECT * FROM entries WHERE notified=0 ORDER BY submitted') as $row) {
        echo $row['receipt'].($send ? (entry_notify($db,$row,$cfg) ? " accepted\n" : " failed\n") : " pending (use --send to retry)\n");
    }
} catch (Throwable $e) { fwrite(STDERR, "Cannot process notifications. Check private configuration.\n"); exit(1); }
