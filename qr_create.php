<?php
//qr_create.php
header("Content-Type: application/json; charset=UTF-8");

date_default_timezone_set('Asia/Tokyo');

// GETのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "ng", "error" => "GET only"]);
    exit;
}

$TOKEN_FILE = __DIR__ . "/tokens.txt";
$now = time();

$token  = null;
$expiry = 0;

// 既存トークンがあるか確認
if (file_exists($TOKEN_FILE)) {
    $content = trim(file_get_contents($TOKEN_FILE));
    if ($content !== '') {
        list($savedToken, $savedExpiry) = explode(",", $content);

        // まだ有効なら再利用
        if ($savedExpiry > $now) {
            $token  = $savedToken;
            $expiry = (int)$savedExpiry;
        }
    }
}

// ----- 時限(period)の判定-----
$currentTime = date('H:i');
$period = 0; // デフォルトは授業時間外 

if ($currentTime >= '08:50' && $currentTime <= '10:30') {
    $period = 1; // 1時限 
} elseif ($currentTime >= '10:35' && $currentTime <= '12:15') {
    $period = 2; // 2時限 
} elseif ($currentTime >= '13:00' && $currentTime <= '14:40') {
    $period = 3; // 3時限 
} elseif ($currentTime >= '14:45' && $currentTime <= '16:25') {
    $period = 4; // 4時限 
}
// ------------------------------------

// 期限切れ or 無ければ新規生成
if ($token === null) {
    $token  = bin2hex(random_bytes(2)); // 4文字
    $expiry = $now + 30;               // 30秒

    file_put_contents(
        $TOKEN_FILE,
        "$token,$expiry\n",
        LOCK_EX
    );
}
// URL生成
//$qr_url = "http://10.100.56.163/s.php?t=$token";
$qr_data = "t=$token&period=$period";

// JSON返却
echo json_encode([
    "status"  => "ok",
    "qr_data"  => $qr_data,
    "expires" => $expiry
    
]);
