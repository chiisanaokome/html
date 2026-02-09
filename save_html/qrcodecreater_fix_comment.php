<?php
// qr_create.php

// JSON形式で返却することを宣言
header("Content-Type: application/json; charset=UTF-8");

// GETリクエストのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "ng", "error" => "GET only"]);
    exit;
}

// トークン保存用ファイルの設定
$TOKEN_FILE = __DIR__ . "/tokens.txt";
$now = time();

$token  = null;
$expiry = 0;

// 1. 既存の有効なトークンがあるか確認
if (file_exists($TOKEN_FILE)) {
    $content = trim(file_get_contents($TOKEN_FILE));
    
    if ($content !== '') {
        // 保存されたファイルからトークンと期限を取り出す
        list($savedToken, $savedExpiry) = explode(",", $content);

        // まだ期限が切れていなければ、既存のトークンを再利用する
        if ($savedExpiry > $now) {
            $token  = $savedToken;
            $expiry = (int)$savedExpiry;
        }
    }
}

// 2. トークンがない（または期限切れ）の場合は新規生成
if ($token === null) {
    // ランダムな2バイトを16進数化（結果的に4文字の文字列になる）
    $token  = bin2hex(random_bytes(2));
    
    // 有効期限を現在時刻 + 300秒（5分）に設定
    $expiry = $now + 300;

    // ファイルへ保存（排他ロック LOCK_EX を使用して競合を防ぐ）
    file_put_contents(
        $TOKEN_FILE,
        "$token,$expiry\n",
        LOCK_EX
    );
}

// 3. QRコードの飛び先URLを生成
// ※IPアドレス部分は環境に合わせて変更が必要かもしれません
$qr_url = "http://10.100.56.163/s.php?t=$token";

// 4. 結果をJSONで返却
echo json_encode([
    "status"  => "ok",
    "qr_url"  => $qr_url,
    "expires" => $expiry
]);