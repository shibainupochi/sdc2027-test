<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
require __DIR__.'/lib.php';
function contact_reply(int $code,array $data): never {
    http_response_code($code); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit;
}
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method,['GET','POST'],true)) { header('Allow: GET, POST'); contact_reply(405,['message'=>'Method not allowed.']); }
    ini_set('session.use_strict_mode','1');
    session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off','samesite'=>'Lax','path'=>'/']);
    if (!session_start()) throw new RuntimeException('Session unavailable.');
    $_SESSION['contact_csrf'] ??= bin2hex(random_bytes(32));
    $_SESSION['contact_owner'] ??= bin2hex(random_bytes(32));
    $_SESSION['contact_keys'] ??= [];
    $owner = hash('sha256',$_SESSION['contact_owner']);
    $cfg = entry_config($_SERVER['DOCUMENT_ROOT']);
    // Contact availability is independent of entry dates and entry enabled=false.
    $open = ($cfg['contact_enabled'] ?? true) === true;
    $sender = $cfg['notification_sender'] ?? '';
    if (!is_string($sender) || !filter_var($sender,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$sender)) throw new RuntimeException('Notification sender missing.');
    $db = contact_db($cfg['storage_dir']);
    if ($method === 'GET') {
        $key = bin2hex(random_bytes(24));
        $_SESSION['contact_keys'] = array_slice($_SESSION['contact_keys'],-19,null,true);
        $_SESSION['contact_keys'][$key] = time();
        contact_reply(200,['open'=>$open,'token'=>$_SESSION['contact_csrf'],'key'=>$key,'message'=>$open ? '' : 'Contact submission is paused. / お問い合わせ受付を一時停止しています。']);
    }
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''),'application/json')) contact_reply(415,['message'=>'JSON required.']);
    $raw = file_get_contents('php://input',false,null,0,65537);
    if ($raw === false || strlen($raw) > 65536) contact_reply(413,['message'=>'Message too large. / 入力内容が長すぎます。']);
    try { $data = json_decode($raw,true,32,JSON_THROW_ON_ERROR); } catch (JsonException $e) { contact_reply(400,['message'=>'Invalid submission. / 送信データが無効です。']); }
    if (!is_array($data)) contact_reply(400,['message'=>'Invalid submission.']);
    if (!is_string($data['token'] ?? null) || !hash_equals($_SESSION['contact_csrf'],$data['token'])) contact_reply(403,['message'=>'Reload before submitting. / ページを再読み込みしてください。']);
    $key = $data['key'] ?? null;
    if (!is_string($key) || !preg_match('/^[a-f0-9]{48}$/',$key)) contact_reply(422,['message'=>'Invalid submission key.']);
    $old = contact_find($db,$key,$owner);
    if (!$old && !isset($_SESSION['contact_keys'][$key])) contact_reply(403,['message'=>'Reload before submitting. / ページを再読み込みしてください。']);
    if (!$old && !$open) contact_reply(403,['message'=>'Contact submission is paused. / 受付を一時停止しています。']);
    $clean = contact_validate($data);
    $ip = hash('sha256',$_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $result = contact_save($db,$key,$owner,$ip,$clean,time());
    session_write_close();
    if ($result['created']) { ignore_user_abort(true); contact_notify($db,$result['row'],$cfg); }
    contact_reply(200,['reference'=>$result['row']['reference']]);
} catch (InvalidArgumentException $e) { contact_reply(422,['message'=>$e->getMessage()]); }
catch (OverflowException $e) { header('Retry-After: 60'); contact_reply(429,['message'=>$e->getMessage()]); }
catch (LogicException $e) { contact_reply(409,['message'=>$e->getMessage()]); }
catch (Throwable $e) { error_log('SDC contact failed ('.$e::class.').'); contact_reply(503,['open'=>false,'message'=>'Unable to confirm receipt. Keep this page and retry. / 受付を確認できませんでした。この画面を閉じずに再送してください。']); }
