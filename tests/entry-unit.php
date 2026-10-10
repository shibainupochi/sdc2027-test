<?php
declare(strict_types=1);
require dirname(__DIR__) . '/en/entry/lib.php';
function check(bool $condition, string $label): void { if (!$condition) throw new RuntimeException($label); }
function fails(callable $fn, string $label): void {
    try { $fn(); } catch (Throwable $e) { return; }
    throw new RuntimeException('Expected rejection: '.$label);
}
$private = sys_get_temp_dir().'/sdc-entry-unit-'.bin2hex(random_bytes(6));
mkdir($private, 0700);
$now = fn(string $value) => new DateTimeImmutable($value, new DateTimeZone('Asia/Tokyo'));
$cases = [
    ['2026-10-31 23:59:59',false,80000],['2026-11-01 00:00:00',true,80000],
    ['2027-01-31 23:59:59',true,80000],['2027-02-01 00:00:00',true,100000],
    ['2027-02-28 23:59:59',true,100000],['2027-03-01 00:00:00',false,100000],
];
foreach ($cases as [$time,$open,$fee]) {
    $t=entry_terms($now($time));check($t['in_period']===$open && $t['fee']===$fee,'JST boundary '.$time);
}
check(entry_terms(new DateTimeImmutable('2027-01-31T15:00:00Z'))['fee']===100000,'UTC to JST fee');
$data = ['category'=>'open','team'=>'Synthetic test team','country'=>'Japan','representative'=>'Test person',
    'method'=>'line','contact'=>'test-line','backup_method'=>'none','hotel'=>'undecided','steerer'=>'yes','notes'=>'','ack'=>'on'];
$clean=entry_validate($data);
check($clean['backup_contact']==='' && !isset($clean['ack']), 'optional fields/whitelist');
foreach (['line'=>'team-line','email'=>'test@example.invalid','whatsapp'=>'+65 8123 4567','messenger'=>'https://www.facebook.com/test'] as $method=>$value) {
    check(entry_validate(array_replace($data,['method'=>$method,'contact'=>$value]))['contact']===$value,'contact '.$method);
    check(entry_validate(array_replace($data,['backup_method'=>$method,'backup_contact'=>$value]))['backup_contact']===$value,'backup '.$method);
}
foreach ([['method'=>'email','contact'=>'bad'],['method'=>'whatsapp','contact'=>'81234567'],['method'=>'messenger','contact'=>'javascript:alert(1)'],
    ['team'=>'   '],['hotel'=>'invalid'],['category'=>'invalid'],['ack'=>''],['website'=>'bot'],['contact'=>[]],['backup_method'=>'email','backup_contact'=>''],['notes'=>str_repeat('x',20001)]] as $change) {
    fails(fn()=>entry_validate(array_replace($data,$change)), 'invalid payload');
}
fails(fn()=>entry_private_path(dirname(__DIR__),dirname(__DIR__)),'public storage');
fails(fn()=>entry_private_path(__DIR__,dirname(__DIR__)),'repository storage');
check(entry_private_path($private,dirname(__DIR__))===realpath($private),'external private storage');
mkdir($private.'/www/site',0700,true);mkdir($private.'/www/leak',0700);
fails(fn()=>entry_private_path($private.'/www/leak',$private.'/www/site'),'public sibling of nested document root');
$db=entry_db($private);
$owner=hash('sha256','synthetic-owner');$key=str_repeat('a',48);
$first=entry_save($db,$key,$owner,$clean,$now('2027-01-31 23:59:59'));
check($first['created'] && entry_receipt($first['row'])['fee']===80000,'saved receipt fee');
$retry=entry_save($db,$key,$owner,$clean,$now('2027-02-02 00:00:00'));
check(!$retry['created'] && $retry['row']['receipt']===$first['row']['receipt'] && (int)$retry['row']['fee']===80000,'retry after fee switch');
$closedRetry=entry_save($db,$key,$owner,$clean,$now('2027-03-01 00:00:00'));
check(!$closedRetry['created'],'retry after close');
check(entry_find($db,$key,hash('sha256','another-owner'))===null,'no cross-session receipt access');
fails(fn()=>entry_save($db,$key,$owner,array_replace($clean,['team'=>'Changed']),$now('2027-01-31')),'same key changed payload');
fails(fn()=>entry_save($db,str_repeat('b',48),$owner,$clean,$now('2027-03-01')),'closed new entry');
$cfg=['notification_sender'=>'test@example.invalid'];
check(!entry_notify($db,$first['row'],$cfg,fn()=>false),'mail rejection');
check(!entry_notify($db,$first['row'],$cfg,function(){throw new RuntimeException('mail failure');}),'mail exception');
check((int)$db->query('SELECT COUNT(*) FROM entries')->fetchColumn()===1,'data survives failed mail');
check((int)$db->query('SELECT notified FROM entries')->fetchColumn()===0,'failed-mail flag');
check(entry_notify($db,$first['row'],$cfg,function($to,$subject,$body,$headers){
    check($to==='osaka@sakuradragoncup.jp','notification recipient');check(str_contains($body,'Synthetic test team'),'notification body');return true;
}),'accepted mail');
check((int)$db->query('SELECT notified FROM entries')->fetchColumn()===1,'accepted flag');
$second=entry_save($db,str_repeat('c',48),$owner,array_replace($clean,['team'=>'  =SUM(1,2)','contact'=>'+6581234567']),$now('2027-02-01'));
check((int)$second['row']['fee']===100000,'regular save');
file_put_contents($private.'/config.php', '<?php return '.var_export(['enabled'=>false,'storage_dir'=>$private,'notification_sender'=>''],true).';');
$command=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=pdo_sqlite',dirname(__DIR__).'/tools/export-entries.php'];
$proc=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['SDC_ENTRY_CONFIG'=>$private.'/config.php']));
$csv=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);check(proc_close($proc)===0,'CSV command '.$err);
check(str_contains($csv,"'  =SUM") && str_contains($csv,"'+658"),'CSV formula escaping');
check(str_contains($csv,'notification_attempts'),'CSV operational fields');
$proc=proc_open(array_merge($command,['--list']),[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['SDC_ENTRY_CONFIG'=>$private.'/config.php']));
$list=stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0 && str_contains($list,$first['row']['receipt']) && str_contains($list,'notified=1'),'saved-entry listing');
echo "PASS: JST/UTC boundaries, validation, optional contacts, private paths, persistent SQLite receipt, stable duplicate replay, changed-payload conflict, closed-period retry, owner isolation, notification failure/exception/success, CSV formula protection. Synthetic test storage: $private\n";
