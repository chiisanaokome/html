<?php
// sensor.php
// ==========================================================
//  設定・DB接続
// ==========================================================
header("Content-Type: text/html; charset=UTF-8");

// TODO: 本番環境では環境変数や別ファイルから読み込むことを推奨します
$host   = '127.0.0.1';
$port   = '5432';
$dbname = 'group3';
$user   = 'gthree';
$pass   = 'Gthree';

$conn_string = "host=$host port=$port dbname=$dbname user=$user password=$pass";
$conn = @pg_connect($conn_string); // エラー抑制演算子(@)をつけて手動でハンドリング

if (!$conn) {
    // POSTまたはAJAXリクエストの場合はJSONでエラーを返す
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(["error" => "DB connection failed", "detail" => pg_last_error()]);
        exit;
    } else {
        // 通常アクセスの場合はメッセージを表示して終了
        exit("DB connection failed: " . pg_last_error());
    }
}

// ==========================================================
//  トークン認証 (IoTデバイスからのPOST用)
// ==========================================================
$TOKEN_FILE = __DIR__ . "/tokens.txt";

// POST以外のアクセス、かつトークン確認用パラメータがない場合はスルー（画面表示用）
// POST時のみ厳密にチェックするロジックとするか、要件に合わせて調整してください。
// ここでは元のロジックを尊重しつつ、POST時または明示的なトークンチェック時に確認します。

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['t'])) {
    if (!file_exists($TOKEN_FILE)) {
        http_response_code(500);
        exit("Token file missing");
    }

    list($savedToken, $expiry) = explode(",", trim(file_get_contents($TOKEN_FILE)));

    // 送信されたトークンを取得
    $reqToken = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_GET['t'] ?? '') : $_GET['t'];
    
    // POSTの場合はURLパラメータになければヘッダー等も確認すべきですが、今回はGETパラメータ前提とします
    // ※元のコードではPOST時にトークンチェックがスキップされているように見えましたが、
    //   セキュリティのため本来はPOST時こそチェックが必要です。
    //   今回は元の挙動（POST時はトークンチェックなし）を変えすぎないよう、
    //   GETパラメータ 't' がある場合のみチェックする形を維持します。
    
    if (isset($_GET['t'])) {
        if ($_GET['t'] !== $savedToken) {
            http_response_code(403);
            exit("invalid token");
        }
        if ($expiry < time()) {
            http_response_code(403);
            exit("token expired");
        }
    }
}

// ==========================================================
//  データ受信・登録 (POST)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Content-Type: application/json");
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid JSON"]);
        exit;
    }

    $required = ['room_id', 'temperature', 'humidity', 'co2', 'illuminance'];
    foreach ($required as $key) {
        if (!isset($data[$key])) {
            http_response_code(400);
            echo json_encode(["error" => "$key required"]);
            exit;
        }
    }

    // パラメータ化クエリでSQLインジェクション対策
    $query = "INSERT INTO sensor_logs (room_id, temperature, humidity, co2, illuminance, measured_at)
              VALUES ($1, $2, $3, $4, $5, NOW())";
    
    $result = pg_query_params($conn, $query, [
        $data['room_id'], 
        $data['temperature'], 
        $data['humidity'], 
        $data['co2'], 
        $data['illuminance']
    ]);

    if ($result) {
        echo json_encode(["status" => "ok"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "DB insert failed", "detail" => pg_last_error($conn)]);
    }
    pg_close($conn);
    exit;
}

// ==========================================================
//  共通関数: クエリ構築
// ==========================================================
function buildQuery($conn, $roomId = null) {
    // 基本クエリ
    $sql = "SELECT sensor_logs.*, COALESCE(rooms.name, sensor_logs.room_id::text) AS room_name
            FROM sensor_logs
            LEFT JOIN rooms ON sensor_logs.room_id = rooms.id
            WHERE 1=1";
    
    // 部屋IDによるフィルタリング
    if ($roomId !== null && $roomId !== '') {
        $safeId = pg_escape_string($conn, $roomId);
        $sql .= " AND sensor_logs.room_id = '$safeId'";
    }
    
    $sql .= " ORDER BY measured_at DESC LIMIT 10";
    return $sql;
}

// ==========================================================
//  自動更新用データ返却 (AJAX)
// ==========================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    
    $filterRoomId = isset($_GET['room_id']) ? $_GET['room_id'] : null;
    
    $sql = buildQuery($conn, $filterRoomId);
    $result = pg_query($conn, $sql);
    
    $rows = [];
    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    pg_close($conn);
    
    echo json_encode($rows);
    exit;
}

// ==========================================================
//  初期表示用データ取得 (HTML)
// ==========================================================
$sql = buildQuery($conn, null);
$result = pg_query($conn, $sql);
$rows = [];
if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        $rows[] = $row;
    }
}
pg_close($conn);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sensor Monitor</title>
<style>
    body { font-family: sans-serif; background-color: #f9f9f9; color: #333; }
    
    /* リンクのスタイル */
    .debug-link { display: inline-block; margin: 10px; font-size: 0.9em; }

    /* テーブルデザイン */
    table { 
        border-collapse: collapse; 
        width: 80%; 
        margin: 20px auto; 
        background-color: #fff;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    th, td { 
        border: 1px solid #ddd; 
        padding: 10px; 
        text-align: center; 
    }
    th { 
        background-color: #adadad; 
        color: white; 
    }
    tr:nth-child(even) { background-color: #f2f2f2; }
    tr:hover { background-color: #ddd; }

    /* タイトルと時計 */
    h2 { text-align: center; margin-top: 30px; }
    #clock { 
        font-size: 0.6em; 
        margin-left: 20px; 
        font-weight: normal; 
        color: #666; 
    }

    /* コントロールエリア */
    .controls { 
        width: 80%; 
        margin: 0 auto; 
        text-align: left; /* 要素を左寄せ */
        padding: 10px 0;
    }
    select { 
        padding: 5px 10px; 
        font-size: 16px; 
        border-radius: 4px; 
        border: 1px solid #ccc; 
    }
</style>
<script>
/**
 * 時計の更新
 */
function updateClock() {
    const now = new Date();
    const timeString = now.toLocaleString('ja-JP');
    const clockEl = document.getElementById('clock');
    if(clockEl) clockEl.innerText = timeString;
}

/**
 * XSS対策用のエスケープ関数
 */
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * データの非同期読み込み
 */
function reloadData(){
    const selectEl = document.getElementById('room_select');
    const roomId = selectEl ? selectEl.value : '';

    fetch('sensor.php?ajax=1&room_id=' + encodeURIComponent(roomId))
        .then(response => {
            if (!response.ok) throw new Error("Network response was not ok");
            return response.json();
        })
        .then(data => {
            let html = '<table>';
            html += '<tr><th>Time</th><th>Room</th><th>Temp (&deg;C)</th><th>Humidity (%)</th><th>CO2 (ppm)</th><th>Illuminance (lx)</th></tr>';
            
            if(data.length === 0) {
                html += '<tr><td colspan="6">No data found</td></tr>';
            } else {
                data.forEach(row => {
                    html += '<tr>';
                    html += `<td>${escapeHtml(row.measured_at)}</td>`;
                    html += `<td>${escapeHtml(row.room_name)}</td>`;
                    html += `<td>${escapeHtml(row.temperature)}</td>`;
                    html += `<td>${escapeHtml(row.humidity)}</td>`;
                    html += `<td>${escapeHtml(row.co2)}</td>`;
                    html += `<td>${escapeHtml(row.illuminance)}</td>`;
                    html += '</tr>';
                });
            }
            html += '</table>';
            document.getElementById('sensor_table').innerHTML = html;
        })
        .catch(err => { 
            console.error("Update failed:", err); 
        });
}

window.onload = function() {
    updateClock();
    setInterval(updateClock, 1000);
    setInterval(reloadData, 5000);
};
</script>
</head>
<body>

<a href="debug.html" class="debug-link">debug画面へ</a>

<h2>
    Sensor Monitor
    <span id="clock">Loading...</span>
</h2>

<div class="controls">
    <label for="room_select">Room Selector: </label>
    <select id="room_select" onchange="reloadData()">
        <option value="">すべて</option>
        <option value="1">0-502</option>
        <option value="2">0-504</option>
        <option value="3">0-506</option>
    </select>
</div>

<div id="sensor_table">
    <table>
    <tr><th>Time</th><th>Room</th><th>Temp (&deg;C)</th><th>Humidity (%)</th><th>CO2 (ppm)</th><th>Illuminance (lx)</th></tr>
    <?php if (empty($rows)): ?>
        <tr><td colspan="6">No data found</td></tr>
    <?php else: ?>
        <?php foreach($rows as $r): ?>
        <tr>
            <td><?php echo htmlspecialchars($r['measured_at'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['room_name'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['temperature'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['humidity'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['co2'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['illuminance'], ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </table>
</div>

</body>
</html>