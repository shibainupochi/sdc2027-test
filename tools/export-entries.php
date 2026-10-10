<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/en/entry/lib.php';
try {
    $cfg = entry_config(dirname(__DIR__));
    $db = entry_db($cfg['storage_dir']);
    $list = in_array('--list', $argv, true);
    $out = fopen('php://stdout', 'w');
    $keys = ['category','team','country','representative','method','contact','backup_method','backup_contact','hotel','steerer','notes'];
    $columns = ['receipt','submitted_JST','fee_JPY','notification_accepted','notification_attempts'];
    if (!$list) fputcsv($out, array_merge($columns, $keys), ',', '"', '');
    foreach ($db->query('SELECT * FROM entries ORDER BY submitted,receipt') as $row) {
        $values = [$row['receipt'],$row['submitted'],$row['fee'],$row['notified'],$row['notification_attempts']];
        $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($keys as $key) {
            $v = (string)($payload[$key] ?? '');
            // Also prevent formulas hidden behind whitespace/BOM.
            $values[] = preg_match('/^(?:[\s\x{FEFF}]*[=+@\-]|[\t\r\n])/u', $v) ? "'".$v : $v;
        }
        if ($list) echo $row['receipt']."\t".$row['submitted']."\tJPY ".$row['fee']."\t".$payload['category']."\tnotified=".$row['notified']."\n";
        else fputcsv($out, $values, ',', '"', '');
    }
} catch (Throwable $e) { fwrite(STDERR, "Cannot export entries. Check the private configuration and storage.\n"); exit(1); }
