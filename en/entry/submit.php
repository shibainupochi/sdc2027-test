<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
require __DIR__ . '/lib.php';
function entry_reply(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET','POST'], true)) { header('Allow: GET, POST'); entry_reply(405, ['message'=>'Method not allowed.']); }
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off','samesite'=>'Lax','path'=>'/']);
    if (!session_start()) throw new RuntimeException('Session unavailable.');
    $_SESSION['entry_csrf'] ??= bin2hex(random_bytes(32));
    $_SESSION['entry_owner'] ??= bin2hex(random_bytes(32));
    $_SESSION['entry_keys'] ??= [];
    $owner = hash('sha256', $_SESSION['entry_owner']);
    $cfg = entry_config($_SERVER['DOCUMENT_ROOT']);
    $now = entry_clock();
    $open = $cfg['enabled'] && entry_terms($now)['in_period'];
    $db = entry_db($cfg['storage_dir']);
    if ($method === 'GET') {
        $key = $_GET['key'] ?? '';
        if (!is_string($key)) entry_reply(400, ['message'=>'Invalid submission key.']);
        if ($key !== '') {
            if (!preg_match('/^[a-f0-9]{48}$/', $key)) entry_reply(400, ['message'=>'Invalid submission key.']);
            $row = entry_find($db, $key, $owner);
            if ($row) entry_reply(200, ['open'=>$open,'saved'=>entry_receipt($row)]);
            if (!isset($_SESSION['entry_keys'][$key])) entry_reply(403, ['message'=>'This browser session has expired. Please contact us if you submitted an application.']);
        } else {
            $key = bin2hex(random_bytes(24));
            // Bounded session state; previously saved requests remain recoverable from the database.
            $_SESSION['entry_keys'] = array_slice($_SESSION['entry_keys'], -19, null, true);
            $_SESSION['entry_keys'][$key] = time();
        }
        entry_reply(200, ['open'=>$open,'token'=>$_SESSION['entry_csrf'],'key'=>$key,
            'message'=>$open ? 'Applications are open. Please check your contact details carefully.' : 'Applications open 1 November 2026 and close 28 February 2027 (Japan Standard Time).']);
    }
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) entry_reply(415, ['message'=>'JSON submission is required.']);
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) entry_reply(413, ['message'=>'Submission is too large.']);
    try { $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { entry_reply(400, ['message'=>'Invalid submission.']); }
    if (!is_array($data)) entry_reply(400, ['message'=>'Invalid submission.']);
    $token = $data['token'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['entry_csrf'], $token)) entry_reply(403, ['message'=>'Please reload the page before submitting.']);
    $key = $data['key'] ?? null;
    if (!is_string($key) || !preg_match('/^[a-f0-9]{48}$/', $key)) entry_reply(422, ['message'=>'Invalid submission key.']);
    $old = entry_find($db, $key, $owner);
    if (!$old && !isset($_SESSION['entry_keys'][$key])) entry_reply(403, ['message'=>'Invalid browser submission key.']);
    $clean = entry_validate($data);
    if (!$old) {
        if (!$open) entry_reply(403, ['message'=>'Applications are not currently open.']);
        if (time() - ($_SESSION['entry_last'] ?? 0) < 30) {
            header('Retry-After: 30'); entry_reply(429, ['message'=>'Please wait a moment before submitting another application.']);
        }
    }
    $result = entry_save($db, $key, $owner, $clean, $now);
    if ($result['created']) $_SESSION['entry_last'] = time();
    session_write_close();
    // Saving is complete before notification; a failed/slow mail transport cannot undo the receipt.
    if ($result['created']) {
        ignore_user_abort(true);
        entry_notify($db, $result['row'], $cfg);
    }
    entry_reply(200, entry_receipt($result['row']));
} catch (InvalidArgumentException $e) { entry_reply(422, ['message'=>$e->getMessage()]); }
catch (LogicException $e) { entry_reply(409, ['message'=>$e->getMessage()]); }
catch (Throwable $e) {
    error_log('SDC entry request failed ('.$e::class.').');
    entry_reply(503, ['open'=>false,'message'=>'Entry submission is unavailable. If you already submitted, keep this page and retry to recover your receipt.']);
}
