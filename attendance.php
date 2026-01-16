<?php
// sensor.php
header("Content-Type: text/html; charset=UTF-8");

// -------------------- DB�ڑ� --------------------
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
        echo "DB connection failed: " . pg_last_error();
        exit;
    }
}

// -------------------- POST��M --------------------
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

    $query = "INSERT INTO attendance_logs ('user_id', 'room_id', 'action', 'logged_at', 'period')
              VALUES ($1,$2,$3,$4,$5,NOW())";
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

// -------------------- GET�\�� --------------------
// �ŐV10�����擾���ăe�[�u���\��
$query = "SELECT * FROM attendance_logs ORDER BY user_id DESC LIMIT 10";
$result = pg_query($conn, $query);

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
</style>
<script>
function reloadData(){
    fetch('attendance.php?ajax=1')
        .then(r=>r.text())
        .then(html=>{document.getElementById('attendance_table').innerHTML=html;});
}
setInterval(reloadData, 5000);
</script>
</head>
<body>
<h2 style="text-align:center;">Sensor Monitor</h2>
<div id="attendance_table">
<?php

// url���M�i�����^�C���j
if (isset($_GET['get_qr'])) {
    header("Content-Type: application/json");

    // 1. �����^�C���g�[�N������
    $token = bin2hex(random_bytes(4)); // 8����
    $expiry = time() + 300; // 5���L��

    // 2. �t�@�C���ɕۑ�
    file_put_contents("tokens.txt", "$token,$expiry\n", FILE_APPEND);

    // 3. URL����
    $qr_url = "http://10.100.56.163/html/attendance.php?token=$token";

    echo json_encode(["qr_url" => $qr_url]);
    exit;
}


if (isset($_GET['ajax'])) {
    echo "<table>";
    echo "<tr><th>User_id</th><th>Room_id</th><th>action</th><th>logged_at</th><th>period</th></tr>";
    foreach($rows as $r){
        echo "<tr>";
        echo "<td>".$r['user_id']."</td>";
        if ($r['room_id'] == 1) {
            echo "<td>0-502</td>";
        } else if ($r['room_id'] == 2) {
            echo "<td>0-504</td>";
        } else if ($r['room_id'] == 3) {
            echo "<td>0-506</td>";
        }
        //echo "<td>".$r['room_id']."</td>";
        echo "<td>".$r['action']."</td>";
        echo "<td>".$r['logged_at']."</td>";
        echo "<td>".$r['period']."</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit;
}

echo "<table>";
echo "<tr><th>User_id</th><th>Room_id</th><th>action</th><th>logged_at</th><th>period</th></tr>";
foreach($rows as $r){
    echo "<tr>";
        echo "<td>".$r['user_id']."</td>";
        echo "<td>".$r['room_id']."</td>";
        echo "<td>".$r['action']."</td>";
        echo "<td>".$r['logged_at']."</td>";
        echo "<td>".$r['period']."</td>";
    echo "</tr>";
}
echo "</table>";
?>
</div>
</body>
</html>
