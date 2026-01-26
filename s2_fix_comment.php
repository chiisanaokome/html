<?php
// ====================================================================
// sensor.php
// センサーデータの登録(POST)、表示(GET)、自動更新(AJAX)を行うプログラム
// ====================================================================

// --------------------------------------------------------------------
// 1. 設定・データベース接続
// --------------------------------------------------------------------

// ブラウザに「このファイルはHTMLで、文字コードはUTF-8です」と伝える
header("Content-Type: text/html; charset=UTF-8");

// データベース接続情報 (PostgreSQL)
$host = '127.0.0.1'; // サーバーのIPアドレス (ローカルホスト)
$port = '5432';      // PostgreSQLの標準ポート
$dbname = 'group3';  // データベース名
$user = 'gthree';    // ユーザー名
$pass = 'Gthree';    // パスワード

// 接続文字列を作成して接続を試みる
$conn_string = "host=$host port=$port dbname=$dbname user=$user password=$pass";
$conn = pg_connect($conn_string);

// 接続に失敗した場合の処理
if (!$conn) {
    // センサーからのPOST通信や、JavaScriptからのAJAX通信の場合は、JSON形式でエラーを返す
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        http_response_code(500); // 500: Internal Server Error
        echo json_encode(["error" => "DB connection failed", "detail" => pg_last_error()]);
        exit; // プログラム終了
    } else {
        // 人間がブラウザで見ている場合は、画面にメッセージを表示
        echo "DB connection failed: " . pg_last_error();
        exit;
    }
}

// --------------------------------------------------------------------
// 2. トークン認証 (セキュリティ対策)
// --------------------------------------------------------------------
// 初回アクセス時など、不正なアクセスを防ぐための簡易的な認証処理です。

$TOKEN_FILE = __DIR__ . "/tokens.txt"; // トークンが保存されているファイルのパス

// 認証が必要かどうかの判定
// 条件: 「GETリクエスト」かつ「AJAX通信ではない(=画面の初期表示)」場合
$needToken = ($_SERVER['REQUEST_METHOD'] === 'GET') && (!isset($_GET['ajax']));

if ($needToken) {
    // URLパラメータにトークン(?t=xxx)がない場合は拒否
    if (!isset($_GET['t'])) {
        http_response_code(403); // 403: Forbidden
        exit("token required (URLにトークンが必要です)");
    }

    // ファイルから正しいトークンと有効期限を読み込む
    // 想定ファイル形式: "トークン文字列,有効期限のタイムスタンプ"
    list($savedToken, $expiry) = explode(",", trim(file_get_contents($TOKEN_FILE)));

    // トークンが一致するか確認
    if ($_GET['t'] !== $savedToken) {
        http_response_code(403);
        exit("invalid token (トークンが正しくありません)");
    }

    // 有効期限が切れていないか確認 (現在の時刻と比較)
    if ($expiry < time()) {
        http_response_code(403);
        exit("token expired (トークンの有効期限切れです)");
    }
}


// --------------------------------------------------------------------
// 3. データ登録処理 (POSTリクエスト)
// --------------------------------------------------------------------
// センサー機器からデータが送られてきた場合の処理です。

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Content-Type: application/json");
    
    // 送られてきた生データ(JSON)を読み込む
    $input = file_get_contents('php://input');
    // JSONをPHPの連想配列に変換する
    $data = json_decode($input, true);

    // 必須項目がすべて揃っているかチェック
    $required = ['room_id', 'temperature', 'humidity', 'co2', 'illuminance'];
    foreach ($required as $key) {
        if (!isset($data[$key])) {
            http_response_code(400); // 400: Bad Request
            echo json_encode(["error" => "$key required"]); // 足りない項目を通知
            exit;
        }
    }

    // データベースへの保存 (SQLインジェクション対策のためプレースホルダ $1, $2... を使用)
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
        echo json_encode(["status" => "ok"]); // 成功
    } else {
        http_response_code(500);
        echo json_encode(["error" => "DB insert failed", "detail" => pg_last_error($conn)]); // 失敗
    }
    pg_close($conn);
    exit; // POST処理が終わったらここで終了
}

// --------------------------------------------------------------------
// 4. 共通関数: クエリ作成
// --------------------------------------------------------------------
// 初期表示とAJAX更新で同じSQLを使うため、関数化して共通化しています。

function buildQuery($conn, $roomId = null) {
    // 基本のSQL: sensor_logsテーブルとroomsテーブルを結合して部屋名も取得
    $sql = "SELECT sensor_logs.*, COALESCE(rooms.name, sensor_logs.room_id::text) AS room_name
            FROM sensor_logs
            LEFT JOIN rooms ON sensor_logs.room_id = rooms.id
            WHERE 1=1"; // WHERE句を動的に追加しやすくするためのダミー条件
    
    // 部屋IDで絞り込みがある場合
    if ($roomId !== null && $roomId !== '') {
        // SQLインジェクション対策: 特殊文字をエスケープ処理
        $safeId = pg_escape_string($conn, $roomId);
        $sql .= " AND sensor_logs.room_id = '$safeId'";
    }
    
    // 測定日時の新しい順に並べ替え、最新10件を取得
    $sql .= " ORDER BY measured_at DESC LIMIT 10";
    return $sql;
}

// --------------------------------------------------------------------
// 5. データ取得API (AJAXリクエスト)
// --------------------------------------------------------------------
// JavaScriptの fetch() から呼ばれ、最新データをJSONで返します。

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    
    // URLパラメータから room_id を取得 (なければ null)
    $filterRoomId = isset($_GET['room_id']) ? $_GET['room_id'] : null;
    
    // クエリを作成して実行
    $sql = buildQuery($conn, $filterRoomId);
    $result = pg_query($conn, $sql);
    
    // 結果を配列に格納
    $rows = [];
    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    pg_close($conn);
    
    // 配列をJSON形式にして出力
    echo json_encode($rows);
    exit; // AJAX処理が終わったらここで終了
}

// --------------------------------------------------------------------
// 6. 画面初期表示用のデータ取得
// --------------------------------------------------------------------
// 初めて画面を開いたときに表示するデータを取得します。

$sql = buildQuery($conn, null); // 最初は全部屋のデータを取得
$result = pg_query($conn, $sql);
$rows = [];
if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        $rows[] = $row;
    }
}
pg_close($conn); // DB接続を閉じる
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<a href="debug.html">debug画面へ</a> <br>
<title>Sensor Monitor</title>
<style>
    /* ページの基本フォント設定 */
    body { font-family: sans-serif; }
    
    /* データ表示用テーブルのスタイル */
    table { 
        border-collapse: collapse; /* 枠線を重ねる */
        width: 80%; 
        margin: auto; /* 画面中央に配置 */
    }
    th, td { 
        border: 1px solid #ccc; /* 灰色の枠線 */
        padding: 8px;           /* 内側の余白 */
        text-align: center;     /* 文字を中央揃え */
    }
    th { background-color: #eee; } /* ヘッダーの背景色 */
    
    /* 時計のスタイル */
    #clock { font-size: 0.6em; margin-left: 20px; font-weight: normal; color: #555; }
    
    /* 部屋選択プルダウンのエリア設定 */
    .controls { 
        width: 80%; 
        margin: 10px auto; 
        text-align: left; /* プルダウン自体は左寄せ */
    }
    select { padding: 5px; font-size: 16px; }
</style>
<script>
// --------------------------------------------------
// 時計を更新する関数
// --------------------------------------------------
function updateClock() {
    const now = new Date();
    const timeString = now.toLocaleString('ja-JP'); // 日本形式の日時
    const clockEl = document.getElementById('clock');
    if(clockEl) clockEl.innerText = timeString;
}

// --------------------------------------------------
// データを非同期(AJAX)で更新する関数
// --------------------------------------------------
function reloadData(){
    // 選択されている部屋IDを取得
    const selectEl = document.getElementById('room_select');
    const roomId = selectEl ? selectEl.value : '';

    // サーバーに問い合わせ (sensor.php?ajax=1&room_id=...)
    fetch('sensor.php?ajax=1&room_id=' + encodeURIComponent(roomId))
        .then(r => {
            if (!r.ok) throw new Error("Network response was not ok");
            return r.json(); // レスポンスをJSONとして解析
        })
        .then(rows => {
            // XSS対策: 記号を安全な文字に変換する関数
            function esc(s){ 
                if (s === null || s === undefined) return '';
                return String(s)
                    .replace(/&/g,'&amp;')
                    .replace(/</g,'&lt;')
                    .replace(/>/g,'&gt;'); 
            }
            
            // テーブルのHTMLを再構築
            let html = '<table>';
            html += '<tr><th>Time</th><th>Room</th><th>Temp (&deg;C)</th><th>Humidity (%)</th><th>CO2 (ppm)</th><th>Illuminance (lx)</th></tr>';
            
            if(rows.length === 0) {
                html += '<tr><td colspan="6">No data found</td></tr>';
            } else {
                for(let r of rows){
                    html += '<tr>';
                    // esc()を通して安全にしてから表示
                    html += '<td>'+esc(r.measured_at)+'</td>';
                    html += '<td>'+esc(r.room_name)+'</td>';
                    html += '<td>'+esc(r.temperature)+'</td>';
                    html += '<td>'+esc(r.humidity)+'</td>';
                    html += '<td>'+esc(r.co2)+'</td>';
                    html += '<td>'+esc(r.illuminance)+'</td>';
                    html += '</tr>';
                }
            }
            html += '</table>';
            
            // 画面のテーブル部分を書き換え
            document.getElementById('sensor_table').innerHTML = html;
        })
        .catch(err => { console.error("Update failed:", err); });
}

// ページ読み込み完了時に実行される処理
window.onload = function() {
    updateClock();
    setInterval(updateClock, 1000); // 1秒ごとに時計更新
    setInterval(reloadData, 5000);  // 5秒ごとにデータ更新
};
</script>
</head>
<body>

<h2 style="text-align:center;">
    Sensor Monitor
    <span id="clock">Loading...</span>
</h2>

<div class="controls">
    <label for="room_select">Room Selecter: </label>
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
<?php foreach($rows as $r): ?>
<tr>
    <td><?php echo htmlspecialchars($r['measured_at']); ?></td>
    <td><?php echo htmlspecialchars($r['room_name']); ?></td>
    <td><?php echo htmlspecialchars($r['temperature']); ?></td>
    <td><?php echo htmlspecialchars($r['humidity']); ?></td>
    <td><?php echo htmlspecialchars($r['co2']); ?></td>
    <td><?php echo htmlspecialchars($r['illuminance']); ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

</body>
</html>