<?php
// attendance.php
// コンテンツタイプと文字コードを最初に設定
header("Content-Type: text/html; charset=UTF-8");

// -------------------- DB接続設定 --------------------
$host = '127.0.0.1';
$port = '5432';
$dbname = 'group3';
$user = 'gthree';
$pass = 'Gthree';

$conn_string = "host=$host port=$port dbname=$dbname user=$user password=$pass";
$conn = pg_connect($conn_string);

if (!$conn) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(["error"=>"DB connection failed","detail"=>pg_last_error()]);
        exit;
    } else {
        exit("DB connection failed: " . pg_last_error());
    }
}

// -------------------- POST受信処理 (データ登録) --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Content-Type: application/json");

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    $required = ['user_id','room_id','action','logged_at','period'];
    foreach($required as $key) {
        if(!isset($data[$key])) {
            http_response_code(400);
            echo json_encode(["error"=>"$key required"]);
            exit;
        }
    }

    // SQL作成
    $query = "INSERT INTO attendance_logs (user_id, room_id, action, logged_at, period) 
              VALUES ($1, $2, $3, $4, $5)";
              
    $result = pg_query_params($conn, $query, [
        $data['user_id'],
        $data['room_id'],
        $data['action'],
        $data['logged_at'],
        $data['period']
    ]);

    file_put_contents("log.txt", print_r($data, true), FILE_APPEND);

    if($result){
        echo json_encode(["status"=>"ok"]);
    } else {
        http_response_code(500);
        echo json_encode(["error"=>"DB insert failed","detail"=>pg_last_error($conn)]);
    }

    pg_close($conn);
    exit;
}

// -------------------- QRコード取得 (API処理) --------------------
if (isset($_GET['get_qr'])) {
    ob_clean();
    header("Content-Type: application/json");

    $token = bin2hex(random_bytes(4));
    $expiry = time() + 300; 

    file_put_contents("tokens.txt", "$token,$expiry\n", FILE_APPEND);

    // IPアドレス等は環境に合わせてください
    $qr_url = "http://10.100.56.163/html/attendance.php?token=$token";

    echo json_encode(["qr_url" => $qr_url]);
    exit;
}

// -------------------- データ取得 (共通処理) --------------------
$query = "SELECT * FROM attendance_logs ORDER BY user_id DESC LIMIT 10";
$result = pg_query($conn, $query);

$rows = [];
if($result){
    while($row = pg_fetch_assoc($result)){
        $rows[] = $row;
    }
}

// -------------------- 部屋名マッピング設定 --------------------
$room_map = [
    1 => '0-502',
    2 => '0-504',
    3 => '0-506'
];

// -------------------- Ajaxリクエスト処理 --------------------
if (isset($_GET['ajax'])) {
    header("Content-Type: text/html; charset=UTF-8");
    renderTable($rows, $room_map);
    pg_close($conn);
    exit; 
}

pg_close($conn);

// -------------------- テーブル描画関数 --------------------
function renderTable($rows, $room_map) {
    echo "<table>";
    echo "<tr><th>User_id</th><th>Room Name</th><th>action</th><th>logged_at</th><th>period</th></tr>";
    
    foreach($rows as $r){
        $room_id = $r['room_id'];
        $room_name = isset($room_map[$room_id]) ? $room_map[$room_id] : $room_id;

        echo "<tr>";
        echo "<td>".htmlspecialchars($r['user_id'])."</td>";
        echo "<td>".htmlspecialchars($room_name)."</td>";
        echo "<td>".htmlspecialchars($r['action'])."</td>";
        echo "<td>".htmlspecialchars($r['logged_at'])."</td>";
        echo "<td>".htmlspecialchars($r['period'])."</td>";
        echo "</tr>";
    }
    echo "</table>";
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Attendance Logs</title>
<style>
    body { font-family: sans-serif; }
    table { border-collapse: collapse; width: 80%; margin: auto; }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: center; }
    th { background-color: #eee; }
</style>
<script>
function reloadData(){
    // ファイル名が attendance.php であることを確認してください
    fetch('attendance.php?ajax=1')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.text();
        })
        .then(html => {
            document.getElementById('attendance_table').innerHTML = html;
        })
        .catch(error => {
            console.error('Fetch error:', error);
        });
}

// 5秒ごとに更新
setInterval(reloadData, 5000);
</script>
</head>
<body>
<h2 style="text-align:center;">Attendance Logs</h2>

<div id="attendance_table">
<?php
    // 初回読み込み時のテーブル表示
    renderTable($rows, $room_map);
?>
</div>

</body>
</html>