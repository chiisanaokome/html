<?php
header("Content-Type: application/json; charset=UTF-8");

// GETのみ許可
if($_SERVER['REQUEST_METHOD'] !== 'GET'){
    http_response_code(405);
    echo json_encode(["status"=>"ng","error"=>"GET only"]);
    exit;
}

// ワンタイムトークン生成
$token  = bin2hex(random_bytes(2)); // 4文字
$expiry = time() + 300;              // 5分

// 保存
file_put_contents("tokens.txt", "$token,$expiry\n", FILE_APPEND);

// URL生成
$qr_url = "http://10.100.56.163/html/s.php?token=$token";

// JSON返却
echo json_encode([
    "status"  => "ok",
    "qr_url"  => $qr_url,
    "expires" => $expiry
]);
