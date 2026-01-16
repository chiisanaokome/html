<?php
// sensor.php
header("Content-Type: text/html; charset=UTF-8");

// -------------------- DB接続 --------------------
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

// -------------------- POST：センサデータ受信 --------------------
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

// -------------------- GET表示 --------------------
// 最新10件を取得してテーブル表示
$query = "SELECT * FROM sensor_logs ORDER BY measured_at DESC LIMIT 10";
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
    fetch('sensor.php?ajax=1')
        .then(r=>r.text())
        .then(html=>{document.getElementById('sensor_table').innerHTML=html;});
}
setInterval(reloadData, 5000);
</script>
</head>
<body>
<h2 style="text-align:center;">Sensor Monitor</h2>
<div id="sensor_table">
<?php
if (isset($_GET['ajax'])) {
    echo "<table>";
    echo "<tr><th>Time</th><th>Room</th><th>Temp (℃)</th><th>Humidity (%)</th><th>CO2 (ppm)</th><th>Illuminance (lx)</th></tr>";
    foreach($rows as $r){
        echo "<tr>";
        echo "<td>".$r['measured_at']."</td>";
        echo "<td>".$r['room_id']."</td>";
        echo "<td>".$r['temperature']."</td>";
        echo "<td>".$r['humidity']."</td>";
        echo "<td>".$r['co2']."</td>";
        echo "<td>".$r['illuminance']."</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit;
}

echo "<table>";
echo "<tr><th>Time</th><th>Room</th><th>Temp (℃)</th><th>Humidity (%)</th><th>CO2 (ppm)</th><th>Illuminance (lx)</th></tr>";
foreach($rows as $r){
    echo "<tr>";
    echo "<td>".$r['measured_at']."</td>";
    echo "<td>".$r['room_id']."</td>";
    echo "<td>".$r['temperature']."</td>";
    echo "<td>".$r['humidity']."</td>";
    echo "<td>".$r['co2']."</td>";
    echo "<td>".$r['illuminance']."</td>";
    echo "</tr>";
}
echo "</table>";
?>
</div>
</body>
</html>
