<?php
// sensor.php
// -------------------- 設定・DB接続 --------------------
header("Content-Type: text/html; charset=UTF-8");

$host = '127.0.0.1';
$port = '5432';
$dbname = 'group3';
$user = 'gthree';
$pass = 'Gthree';

$conn_string = "host=$host port=$port dbname=$dbname user=$user password=$pass";
$conn = pg_connect($conn_string);

// DB接続失敗時の処理
if (!$conn) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(["error"=>"DB connection failed","detail"=>pg_last_error()]);
        exit;
    } else {
        echo "DB connection failed: " . pg_last_error();
        exit;
    }
}

// -------------------- データ受信 (POST) --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Content-Type: application/json");

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    $required = ['room_id','temperature','humidity','co2','illuminance'];
    foreach($required as $key) {
        if(!isset($data[$key])) {
            http_response_code(400);
            echo json_encode(["error"=>"$key required"]);
            exit;
        }
    }

    $query = "INSERT INTO sensor_logs (room_id, temperature, humidity, co2, illuminance, measured_at)
              VALUES ($1,$2,$3,$4,$5,NOW())";
    $result = pg_query_params($conn, $query, [
        $data['room_id'],
        $data['temperature'],
        $data['humidity'],
        $data['co2'],
        $data['illuminance']
    ]);

    if($result){
        echo json_encode(["status"=>"ok"]);
    } else {
        http_response_code(500);
        echo json_encode(["error"=>"DB insert failed","detail"=>pg_last_error($conn)]);
    }

    pg_close($conn);
    exit;
}

// 共通SQL
$sql = "SELECT sensor_logs.*, COALESCE(rooms.name, sensor_logs.room_id::text) AS room_name
        FROM sensor_logs
        LEFT JOIN rooms ON sensor_logs.room_id = rooms.id
        ORDER BY measured_at DESC LIMIT 10";

// -------------------- 自動更新用データ返却 (AJAX) --------------------
// ★ここが超重要です。HTMLを出力する前に、ajaxリクエストならJSONを返して終了(exit)します。
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    
    $result = pg_query($conn, $sql);
    $rows = [];
    if($result){
        while($row = pg_fetch_assoc($result)){
            $rows[] = $row;
        }
    }
    pg_close($conn);
    
    echo json_encode($rows);
    exit; // ここでスクリプトを強制終了させ、下のHTMLが出ないようにします
}

// -------------------- 初回表示用データ取得 --------------------
$result = pg_query($conn, $sql);
$rows = [];
if($result){
    while($row = pg_fetch_assoc($result)){
        $rows[] = $row;
    }
}
pg_close($conn);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Sensor Monitor</title>
<style>
    body { font-family: sans-serif; }
    table { border-collapse: collapse; width: 80%; margin: auto; }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: center; }
    th { background-color: #eee; }
    /* 時計のデザイン */
    #clock {
        font-size: 0.6em;
        margin-left: 20px;
        font-weight: normal;
        color: #555;
    }
</style>
<script>
// 時計更新関数
function updateClock() {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, '0');
    const d = String(now.getDate()).padStart(2, '0');
    const H = String(now.getHours()).padStart(2, '0');
    const M = String(now.getMinutes()).padStart(2, '0');
    const S = String(now.getSeconds()).padStart(2, '0');
    
    const timeString = `${y}/${m}/${d} ${H}:${M}:${S}`;
    const clockEl = document.getElementById('clock');
    if(clockEl) {
        clockEl.innerText = timeString;
    }
}

// データ更新関数
function reloadData(){
    fetch('sensor.php?ajax=1')
        .then(r => {
            // ここでエラーが出る場合、PHP側でHTMLが混ざっている可能性があります
            if (!r.ok) throw new Error("Network response was not ok");
            return r.json();
        })
        .then(rows => {
            function esc(s){ 
                if (s === null || s === undefined) return '';
                return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); 
            }
            
            let html = '<table>';
            html += '<tr><th>Time</th><th>Room</th><th>Temp (&deg;C)</th><th>Humidity (%)</th><th>CO2 (ppm)</th><th>Illuminance (lx)</th></tr>';
            for(let r of rows){
                html += '<tr>';
                html += '<td>'+esc(r.measured_at)+'</td>';
                html += '<td>'+esc(r.room_name)+'</td>';
                html += '<td>'+esc(r.temperature)+'</td>';
                html += '<td>'+esc(r.humidity)+'</td>';
                html += '<td>'+esc(r.co2)+'</td>';
                html += '<td>'+esc(r.illuminance)+'</td>';
                html += '</tr>';
            }
            html += '</table>';
            document.getElementById('sensor_table').innerHTML = html;
        })
        .catch(err => { console.error("Update failed:", err); });
}

// 読み込み完了後にタイマーを開始
window.onload = function() {
    updateClock();
    setInterval(updateClock, 1000); // 時計は1秒ごと
    setInterval(reloadData, 5000);  // データは5秒ごと
};
</script>
</head>
<body>

<h2 style="text-align:center;">
    Sensor Monitor
    <span id="clock">Loading...</span>
</h2>

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