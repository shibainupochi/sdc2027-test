<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/contact/lib.php';
try {
    $cfg = entry_config(dirname(__DIR__));
    $db = contact_db($cfg['storage_dir']);
    $send = in_array('--send',$argv,true);
    foreach ($db->query('SELECT * FROM contacts WHERE notified=0 ORDER BY created') as $row) {
        echo $row['reference'].($send ? (contact_notify($db,$row,$cfg) ? " accepted\n" : " failed\n") : " pending (use --send to retry)\n");
    }
} catch (Throwable $e) { fwrite(STDERR,"Check private configuration and storage.\n"); exit(1); }
