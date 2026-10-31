<?php
declare(strict_types=1);
// Run over the existing deployment SSH connection; never publish this file.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? '';
require $root . '/en/entry/lib.php';
$cfg = entry_config($root);
$path = entry_private_path(getenv('SDC_ENTRY_CONFIG') ?: dirname(entry_public_base($root)) . '/private-sdc-entry/config.php', $root);
$backup = $path . '.before-20261101-opening';
// A private backup doubles as a one-time migration marker. Later deployments
// must not override an administrator's subsequent decision to close reception.
if (!is_file($backup)) {
    if (!entry_terms(entry_clock())['in_period']) throw new RuntimeException('Outside the opening period.');
    if (!filter_var($cfg['notification_sender'] ?? '', FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Notification sender is not configured.');
    entry_db($cfg['storage_dir']);
    $source = file_get_contents($path);
    $updated = preg_replace("/(['\"])enabled\\1\\s*=>\\s*(?:false|true)\\b/", "'enabled' => true", $source, -1, $count);
    if ($count !== 1) throw new RuntimeException('Expected one literal enabled setting.');
    umask(0077);
    $temp = tempnam(dirname($path), '.entry-opening-');
    if (file_put_contents($temp, $updated) !== strlen($updated)) throw new RuntimeException('Cannot prepare configuration.');
    $check = require $temp;
    $original = require $path;
    if ($check !== array_replace($original, ['enabled'=>true])) throw new RuntimeException('Unexpected configuration change.');
    if (!copy($path, $backup) || !chmod($backup, 0600)) throw new RuntimeException('Cannot back up configuration.');
    if (!rename($temp, $path)) throw new RuntimeException('Cannot activate configuration; inspect backup before retry.');
    chmod($path, 0600);
    echo "Opening migration applied; private backup saved.\n";
}
$cfg = entry_config($root);
echo 'enabled=' . ($cfg['enabled'] ? 'true' : 'false') . '; in_period=' . (entry_terms(entry_clock())['in_period'] ? 'true' : 'false') . "\n";
$receipt = $argv[2] ?? '';
if ($receipt !== '') {
    if (!preg_match('/^SDC27-[A-F0-9]{16}$/', $receipt)) throw new RuntimeException('Invalid receipt.');
    $db = entry_db($cfg['storage_dir']);
    $q = $db->prepare('SELECT receipt, submitted, fee, notified, notification_attempts, payload FROM entries WHERE receipt=?');
    $q->execute([$receipt]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Receipt not saved.');
    $data = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
    if (!str_contains($data['team'], 'TEST')) throw new RuntimeException('Only TEST applications can be verified in deployment logs.');
    unset($row['payload']);
    echo json_encode($row, JSON_THROW_ON_ERROR) . "\n";
}
