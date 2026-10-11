<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/en/entry/lib.php';

function contact_validate(array $data): array {
    $out = [];
    foreach (['subject','name','method','contact','backup_method','backup_contact','receipt','message'] as $key) {
        $value = $data[$key] ?? (in_array($key,['backup_contact','receipt'],true) ? '' : null);
        if (!is_string($value) || !preg_match('//u',$value)) throw new InvalidArgumentException('Please complete the required fields. / 必須項目を入力してください。');
        $value = trim(str_replace("\r\n","\n",$value));
        if (strlen($value) > ($key === 'message' ? 20000 : 1024) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value) || ($key !== 'message' && preg_match('/[\r\n]/',$value))) throw new InvalidArgumentException('Invalid or oversized field. / 入力内容が長すぎるか、無効な文字があります。');
        $out[$key] = $value;
    }
    if (!in_array($out['subject'],['entry','entry-status','race','hotel','access','other'],true)) throw new InvalidArgumentException('Select a topic. / 件名を選択してください。');
    if ($out['name'] === '' || $out['message'] === '') throw new InvalidArgumentException('Enter your name and message. / 氏名と内容を入力してください。');
    foreach ([''=>'method','backup_'=>'backup_method'] as $prefix=>$key) {
        $method = $out[$key];
        if ($prefix === 'backup_' && $method === 'none') { $out['backup_contact'] = ''; continue; }
        if (!in_array($method,['email','whatsapp','messenger'],true)) throw new InvalidArgumentException('Select a reply method. / 返信方法を選択してください。');
        $v = $out[$prefix.'contact'];
        $valid = match ($method) {
            'email' => (bool)filter_var($v,FILTER_VALIDATE_EMAIL),
            'whatsapp' => (bool)preg_match('/^\+[1-9][0-9]{6,14}$/',str_replace([' ','(',')','-'],'',$v)),
            'messenger' => (bool)filter_var($v,FILTER_VALIDATE_URL) && (bool)preg_match('~^https?://~i',$v),
        };
        if (!$valid) throw new InvalidArgumentException('Check your contact details; WhatsApp needs a country code. / 連絡先をご確認ください。WhatsAppには国番号が必要です。');
    }
    if ($out['subject'] !== 'entry-status') $out['receipt'] = '';
    if ($out['receipt'] !== '' && !preg_match('/^SDC27-[A-F0-9]{16}$/',$out['receipt'])) throw new InvalidArgumentException('Check the entry receipt number, or leave it blank. / 受付番号をご確認いただくか、空欄にしてください。');
    if (!is_string($data['website'] ?? '') || trim($data['website'] ?? '') !== '') throw new InvalidArgumentException('Unable to accept this message. / 送信できません。');
    return $out;
}
function contact_db(string $dir): PDO {
    umask(0077);
    $file = $dir.'/contacts.sqlite';
    if (is_link($file)) throw new RuntimeException('Database symlink rejected.');
    $db = new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=10000');
    $db->exec('CREATE TABLE IF NOT EXISTS contacts(reference TEXT PRIMARY KEY, request_key TEXT UNIQUE NOT NULL, owner_hash TEXT NOT NULL, ip_hash TEXT NOT NULL, created INTEGER NOT NULL, payload TEXT NOT NULL, notified INTEGER NOT NULL DEFAULT 0, notification_attempts INTEGER NOT NULL DEFAULT 0)');
    $db->exec('CREATE INDEX IF NOT EXISTS contacts_rate ON contacts(ip_hash,created)');
    @chmod($file,0600);
    return $db;
}
function contact_find(PDO $db,string $key,string $owner): ?array {
    $q = $db->prepare('SELECT * FROM contacts WHERE request_key=? AND owner_hash=?');
    $q->execute([$key,$owner]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
function contact_save(PDO $db,string $key,string $owner,string $ip,array $clean,int $now): array {
    $json = json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $old = contact_find($db,$key,$owner);
        if ($old) {
            if ($old['payload'] !== $json) throw new LogicException('This message was already saved. / このお問い合わせは既に保存されています。');
            $db->exec('COMMIT'); return ['created'=>false,'row'=>$old];
        }
        $q = $db->prepare('SELECT COUNT(*) FROM contacts WHERE ip_hash=? AND created>?');
        $q->execute([$ip,$now-60]);
        if ((int)$q->fetchColumn() > 0) throw new OverflowException('Please wait one minute before sending another message. / 次のお問い合わせは1分後に送信してください。');
        $q->execute([$ip,$now-86400]);
        if ((int)$q->fetchColumn() >= 20) throw new OverflowException('Daily submission limit reached. / 本日の送信回数の上限に達しました。');
        $row = ['reference'=>'SDC-C-'.strtoupper(bin2hex(random_bytes(8))),'request_key'=>$key,'owner_hash'=>$owner,'ip_hash'=>$ip,'created'=>$now,'payload'=>$json];
        $q = $db->prepare('INSERT INTO contacts(reference,request_key,owner_hash,ip_hash,created,payload) VALUES(?,?,?,?,?,?)');
        $q->execute(array_values($row));
        $db->exec('COMMIT'); return ['created'=>true,'row'=>$row];
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}
function contact_notify(PDO $db,array $row,array $cfg,?callable $transport=null): bool {
    $ok = false;
    try {
        $sender = $cfg['notification_sender'] ?? '';
        if (is_string($sender) && filter_var($sender,FILTER_VALIDATE_EMAIL) && !preg_match('/[\r\n]/',$sender)) {
            $d = json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
            $body = 'Reference / お問い合わせ番号: '.$row['reference']."\nReceived (JST): ".(new DateTimeImmutable('@'.$row['created']))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i:s')."\n\n";
            $labels = ['subject'=>'Topic / 件名','name'=>'Name / 氏名','method'=>'Reply method / 返信方法','contact'=>'Contact / 連絡先','backup_method'=>'Backup method / 予備返信方法','backup_contact'=>'Backup contact / 予備連絡先','receipt'=>'Entry receipt / エントリー受付番号','message'=>'Message / 内容'];
            foreach ($labels as $key=>$label) $body .= $label.': '.$d[$key]."\n\n";
            $headers = ['From'=>$sender,'MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8'];
            if ($d['method'] === 'email') $headers['Reply-To'] = $d['contact'];
            $transport ??= fn($to,$subject,$body,$headers)=>mail($to,$subject,$body,$headers);
            $ok = (bool)$transport('osaka@sakuradragoncup.jp','SDC2027 contact '.$row['reference'],$body,$headers);
        }
    } catch (Throwable $e) { error_log('SDC contact notification failed: '.$row['reference']); }
    try {
        $q = $db->prepare('UPDATE contacts SET notified=MAX(notified,?),notification_attempts=notification_attempts+1 WHERE reference=?');
        $q->execute([$ok ? 1 : 0,$row['reference']]);
    } catch (Throwable $e) { error_log('SDC contact notification status failed: '.$row['reference']); }
    return $ok;
}
