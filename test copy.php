<?php
// sensor_logs を取得して JSON を返すサンプル
// 設定: ホストと DB 名は既知 (10.100.56.163 / group3)
// 実行前に PGUSER と PGPASSWORD 環境変数、または下の $user/$pass を設定してください。

header('Content-Type: application/json; charset=utf-8');

$host = '10.100.56.163';
$port = 5432;
$db   = 'group3';
$user = getenv('gthree') ?: 'your_pg_user';
$pass = getenv('Gthree') ?: 'your_pg_password';

$dsn = "pgsql:host={$host};port={$port};dbname={$db}";

try {
	$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
	http_response_code(500);
	echo json_encode(['error' => 'DB connection failed', 'message' => $e->getMessage()]);
	exit;
}

// クエリパラメータ: room_id (省略可), limit (省略時100)
$room_id = isset($_GET['room_id']) ? intval($_GET['room_id']) : null;
$limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 100;

$sql = "SELECT room_id, temprature, humidty, co2, illuminance, measured_at FROM sensor_logs";
$conds = [];
$params = [];
if ($room_id !== null) {
	$conds[] = "room_id = :room_id";
	$params[':room_id'] = $room_id;
}
if (!empty($conds)) {
	$sql .= ' WHERE ' . implode(' AND ', $conds);
}
$sql .= ' ORDER BY measured_at DESC LIMIT :limit';

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
	$stmt->bindValue($k, $v, PDO::PARAM_INT);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
try {
	$stmt->execute();
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	echo json_encode($rows);
} catch (PDOException $e) {
	http_response_code(500);
	echo json_encode(['error' => 'Query failed', 'message' => $e->getMessage()]);
}

