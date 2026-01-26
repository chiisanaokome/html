<?php
//qr_create.php
header("Content-Type: application/json; charset=UTF-8");

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

// 期限切れ or 無ければ新規生成
if ($token === null) {
    $token  = bin2hex(random_bytes(2)); // 4文字
    $expiry = $now + 300;               // 5分

    file_put_contents(
        $TOKEN_FILE,
        "$token,$expiry\n",
        LOCK_EX
    );
}

// URL生成
//$qr_url = "http://10.100.56.163/s.php?t=$token";
$qr_data = "t=$token";

// JSON返却
echo json_encode([
    "status"  => "ok",
    "qr_data"  => $qr_data,
    "expires" => $expiry
]);
