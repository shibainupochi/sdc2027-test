<?php
declare(strict_types=1);

const ENTRY_START = '2026-11-01 00:00:00';
const ENTRY_EARLY_END = '2027-02-01 00:00:00';
const ENTRY_END = '2027-03-01 00:00:00';

function entry_clock(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
}

function entry_terms(DateTimeImmutable $time): array {
    $jst = $time->setTimezone(new DateTimeZone('Asia/Tokyo'));
    $stamp = $jst->format('Y-m-d H:i:s');
    return ['in_period' => $stamp >= ENTRY_START && $stamp < ENTRY_END,
        'fee' => $stamp < ENTRY_EARLY_END ? 80000 : 100000,
        'submitted' => $jst->format('Y-m-d\TH:i:sP')];
}

function entry_public_base(string $publicRoot): string {
    $current = realpath($publicRoot);
    if ($current === false) throw new RuntimeException('Cannot verify public root.');
    while (true) {
        if (in_array(strtolower(basename($current)), ['www','public_html','htdocs'], true)) return $current;
        $parent = dirname($current);
        if ($parent === $current) return realpath($publicRoot);
        $current = $parent;
    }
}

function entry_private_path(string $path, string $publicRoot): string {
    $real = realpath($path);
    $roots = [realpath($publicRoot), entry_public_base($publicRoot), realpath(dirname(__DIR__, 2))];
    if ($real === false) throw new RuntimeException('Private path does not exist.');
    foreach ($roots as $root) {
        if ($root === false) throw new RuntimeException('Cannot verify public root.');
        $a = str_replace('\\', '/', $real);
        $b = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') { $a = strtolower($a); $b = strtolower($b); }
        if ($a === $b || str_starts_with($a, $b . '/')) throw new RuntimeException('Storage/config must be outside the website and repository.');
    }
    return $real;
}

function entry_config(string $publicRoot): array {
    $path = getenv('SDC_ENTRY_CONFIG') ?: dirname(entry_public_base($publicRoot)) . '/private-sdc-entry/config.php';
    $path = entry_private_path($path, $publicRoot);
    if (!is_file($path)) throw new RuntimeException('Private configuration is missing.');
    $cfg = require $path;
    if (!is_array($cfg) || !is_bool($cfg['enabled'] ?? null)) throw new RuntimeException('Invalid configuration.');
    $cfg['storage_dir'] = entry_private_path((string)($cfg['storage_dir'] ?? ''), $publicRoot);
    if (!is_dir($cfg['storage_dir']) || !is_writable($cfg['storage_dir'])) throw new RuntimeException('Private storage is not writable.');
    return $cfg;
}

function entry_db(string $dir): PDO {
    umask(0077);
    $file = $dir . '/entries.sqlite';
    if (is_link($file)) throw new RuntimeException('Database symlinks are not allowed.');
    $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=10000');
    $db->exec('CREATE TABLE IF NOT EXISTS entries (
        receipt TEXT PRIMARY KEY, request_key TEXT UNIQUE NOT NULL, owner_hash TEXT NOT NULL,
        submitted TEXT NOT NULL, fee INTEGER NOT NULL, payload TEXT NOT NULL,
        notified INTEGER NOT NULL DEFAULT 0, notification_attempts INTEGER NOT NULL DEFAULT 0
    )');
    @chmod($file, 0600);
    return $db;
}

function entry_find(PDO $db, string $key, string $owner): ?array {
    $q = $db->prepare('SELECT * FROM entries WHERE request_key=? AND owner_hash=?');
    $q->execute([$key, $owner]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function entry_receipt(array $row): array {
    return ['receipt' => $row['receipt'], 'submitted' => $row['submitted'], 'fee' => (int)$row['fee'],
        'application' => json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR)];
}

function entry_validate(array $data): array {
    $out = [];
    foreach (['category','team','country','representative','method','contact','backup_method','backup_contact','hotel','steerer','notes'] as $key) {
        $value = $data[$key] ?? (in_array($key, ['notes','backup_contact'], true) ? '' : null);
        if (!is_string($value) || !preg_match('//u', $value)) throw new InvalidArgumentException('Please complete all required fields.');
        $out[$key] = trim(str_replace("\r\n", "\n", $value));
        $max = $key === 'notes' ? 20000 : 1024;
        if (strlen($out[$key]) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $out[$key])) throw new InvalidArgumentException('A field is too long or contains invalid characters.');
        if ($key !== 'notes' && preg_match('/[\r\n]/', $out[$key])) throw new InvalidArgumentException('Please use a single line for contact and team details.');
    }
    foreach (['team','country','representative','contact'] as $key) if ($out[$key] === '') throw new InvalidArgumentException('Please complete all required fields.');
    $options = ['category'=>['open','women','senior'],'method'=>['email','whatsapp','messenger','line'],
        'backup_method'=>['none','email','whatsapp','messenger','line'],'hotel'=>['yes','no','undecided'],'steerer'=>['yes','no','undecided']];
    foreach ($options as $key=>$allowed) if (!in_array($out[$key], $allowed, true)) throw new InvalidArgumentException('Please select a valid option.');
    foreach ([''=>'method','backup_'=>'backup_method'] as $prefix=>$key) {
        $method = $out[$key];
        if ($method === 'none') { $out['backup_contact'] = ''; continue; }
        $v = $out[$prefix . 'contact'];
        $valid = $v !== '';
        if ($method === 'email') $valid = (bool)filter_var($v, FILTER_VALIDATE_EMAIL);
        if ($method === 'messenger') $valid = (bool)filter_var($v, FILTER_VALIDATE_URL) && (bool)preg_match('~^https?://~i', $v);
        if ($method === 'whatsapp') $valid = (bool)preg_match('/^\+[1-9][0-9]{6,14}$/', str_replace([' ', '(', ')', '-'], '', $v));
        if (!$valid) throw new InvalidArgumentException('Please check your contact details. WhatsApp needs + and the country code.');
    }
    if (($data['ack'] ?? null) !== 'on') throw new InvalidArgumentException('Please acknowledge the entry and payment conditions.');
    if (!is_string($data['website'] ?? '') || trim($data['website'] ?? '') !== '') throw new InvalidArgumentException('Unable to accept this submission.');
    return $out;
}

function entry_save(PDO $db, string $key, string $owner, array $clean, DateTimeImmutable $now): array {
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $old = entry_find($db, $key, $owner);
    if ($old) {
        if ($old['payload'] !== $json) throw new LogicException('This submission was already saved with different details. Reload to view your receipt.');
        return ['row'=>$old,'created'=>false];
    }
    $terms = entry_terms($now);
    if (!$terms['in_period']) throw new DomainException('Applications are not currently open.');
    $row = ['receipt'=>'SDC27-'.strtoupper(bin2hex(random_bytes(8))), 'request_key'=>$key,
        'owner_hash'=>$owner,'submitted'=>$terms['submitted'],'fee'=>$terms['fee'],'payload'=>$json];
    try {
        $q = $db->prepare('INSERT INTO entries(receipt,request_key,owner_hash,submitted,fee,payload) VALUES(?,?,?,?,?,?)');
        $q->execute(array_values($row));
    } catch (PDOException $e) {
        $old = entry_find($db, $key, $owner);
        if (!$old) throw $e;
        if ($old['payload'] !== $json) throw new LogicException('This submission was already saved with different details.');
        return ['row'=>$old,'created'=>false];
    }
    return ['row'=>$row,'created'=>true];
}

function entry_notify(PDO $db, array $row, array $cfg, ?callable $transport = null): bool {
    $accepted = false;
    try {
        $sender = $cfg['notification_sender'] ?? '';
        if (is_string($sender) && filter_var($sender, FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n]/', $sender)) {
            $body = 'Receipt: '.$row['receipt']."\nSubmitted (JST): ".$row['submitted']."\nFee: JPY ".$row['fee']."\n\n".$row['payload'];
            $transport ??= fn($to,$subject,$body,$headers) => mail($to,$subject,$body,$headers);
            $accepted = (bool)$transport('osaka@sakuradragoncup.jp','SDC2027 entry '.$row['receipt'],$body,
                ['From'=>$sender,'MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8']);
        }
    } catch (Throwable $e) { error_log('SDC notification failed for '.$row['receipt']); }
    try {
        $q = $db->prepare('UPDATE entries SET notified=MAX(notified,?),notification_attempts=notification_attempts+1 WHERE receipt=?');
        $q->execute([$accepted ? 1 : 0, $row['receipt']]);
    } catch (Throwable $e) { error_log('SDC notification status could not be recorded for '.$row['receipt']); }
    return $accepted;
}
