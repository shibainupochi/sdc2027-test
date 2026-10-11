<?php
declare(strict_types=1);
require dirname(__DIR__).'/contact/lib.php';
function check(bool $v,string $label): void { if (!$v) throw new RuntimeException($label); }
function fails(callable $fn): void { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException('Expected rejection.'); }
$dir = sys_get_temp_dir().'/sdc-contact-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
try {
    $data = ['subject'=>'entry-status','name'=>'テスト担当','method'=>'email','contact'=>'test@example.com','backup_method'=>'none','receipt'=>'SDC27-1234567890ABCDEF','message'=>"日本語の問い合わせ\nEnglish message",'website'=>''];
    $clean = contact_validate($data);
    foreach (['email'=>'test@example.com','whatsapp'=>'+65 8123 4567','messenger'=>'https://www.facebook.com/test'] as $method=>$value) check(contact_validate(array_replace($data,['method'=>$method,'contact'=>$value]))['contact']===$value,'reply method');
    foreach ([['contact'=>"test@example.com\r\nBcc:bad@example.com"],['method'=>'whatsapp','contact'=>'81234567'],['method'=>'messenger','contact'=>'javascript:alert(1)'],['subject'=>'unknown'],['message'=>' '],['backup_method'=>'email','backup_contact'=>''],['receipt'=>'invalid'],['website'=>'bot'],['name'=>[]]] as $v) fails(fn()=>contact_validate(array_replace($data,$v)));
    $db = contact_db($dir);$owner=hash('sha256','owner');$key=str_repeat('a',48);$ip=hash('sha256','ip');
    $first=contact_save($db,$key,$owner,$ip,$clean,1000);
    $retry=contact_save($db,$key,$owner,$ip,$clean,1001);
    check($first['created'] && !$retry['created'] && $first['row']['reference']===$retry['row']['reference'],'duplicate retry');
    check(contact_find($db,$key,'other')===null,'owner isolation');
    fails(fn()=>contact_save($db,$key,$owner,$ip,array_replace($clean,['message'=>'changed']),1002));
    fails(fn()=>contact_save($db,str_repeat('b',48),$owner,$ip,$clean,1002));
    check(!contact_notify($db,$first['row'],['notification_sender'=>'internal@example.com'],fn()=>false),'transport failure');
    check((int)$db->query('SELECT COUNT(*) FROM contacts')->fetchColumn()===1,'preserved after failure');
    $captured=null;
    check(contact_notify($db,$first['row'],['notification_sender'=>'internal@example.com'],function($to,$subject,$body,$headers)use(&$captured){$captured=[$to,$headers,$body];return true;}),'transport success');
    check($captured[0]==='osaka@sakuradragoncup.jp' && $captured[1]['From']==='internal@example.com' && $captured[1]['Reply-To']==='test@example.com','fixed routing');
    $r=$db->query('SELECT notified,notification_attempts FROM contacts')->fetch(PDO::FETCH_ASSOC);
    check((int)$r['notified']===1 && (int)$r['notification_attempts']===2,'retry status');
    echo "Contact validation, durable save, idempotency, rate limit and notification tests passed.\n";
} finally { $db=null; foreach(glob($dir.'/*') as $file) unlink($file); rmdir($dir); }
