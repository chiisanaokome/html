<?php
// sensor.php
// -------------------- �ݒ�EDB�ڑ� --------------------
header("Content-Type: text/html; charset=UTF-8");

$host = '127.0.0.1';
$port = '5432';
$dbname = 'group3';
$user = 'gthree';
$pass = 'Gthree';

$conn_string = "host=$host port=$port dbname=$dbname user=$user password=$pass";
$conn = pg_connect($conn_string);

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

// -------------------- �g�[�N���F�� --------------------
$TOKEN_FILE = __DIR__ . "/tokens.txt";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	if (!isset($_GET['t'])) {
	    http_response_code(403);
	    exit("token required");
	}

	list($savedToken, $expiry) = explode(",", trim(file_get_contents($TOKEN_FILE)));

	if ($_GET['t'] !== $savedToken) {
	    http_response_code(403);
	    exit("invalid token");
	}

	if ($expiry < time()) {
	    http_response_code(403);
	    exit("token expired");
	}
}


// -------------------- �f�[�^��M (POST) --------------------
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
        $data['room_id'], $data['temperature'], $data['humidity'], $data['co2'], $data['illuminance']
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

// -------------------- �N�G���\�z�֐� --------------------
function buildQuery($conn, $roomId = null) {
    $sql = "SELECT sensor_logs.*, COALESCE(rooms.name, sensor_logs.room_id::text) AS room_name
            FROM sensor_logs
            LEFT JOIN rooms ON sensor_logs.room_id = rooms.id
            WHERE 1=1";
    
    if ($roomId !== null && $roomId !== '') {
        $safeId = pg_escape_string($conn, $roomId);
        $sql .= " AND sensor_logs.room_id = '$safeId'";
    }
    
    $sql .= " ORDER BY measured_at DESC LIMIT 10";
    return $sql;
}

// -------------------- �����X�V�p�f�[�^�ԋp (AJAX) --------------------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    
    $filterRoomId = isset($_GET['room_id']) ? $_GET['room_id'] : null;
    
    $sql = buildQuery($conn, $filterRoomId);
    $result = pg_query($conn, $sql);
    
    $rows = [];
    if($result){
        while($row = pg_fetch_assoc($result)){
            $rows[] = $row;
        }
    }
    pg_close($conn);
    
    echo json_encode($rows);
    exit;
}

// -------------------- ����\���p�f�[�^�擾 --------------------
$sql = buildQuery($conn, null);
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
<a href="debug.html">debug画面へ</a> <br>
<title>Sensor Monitor</title>
<style>
    body { font-family: sans-serif; }
    
    /* �\�͒����񂹂ɖ߂� */
    table { 
        border-collapse: collapse; 
        width: 80%; 
        margin: auto; 
    }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: center; }
    th { background-color: #eee; }
    
    #clock { font-size: 0.6em; margin-left: 20px; font-weight: normal; color: #555; }
    
    /* ������ �R���g���[���G���A�̃X�^�C�� ������ */
    /* ����\�Ɠ���80%�ɂ��Ē����ɒu���A���g(text-align)�����񂹂ɂ��� */
    .controls { 
        width: 80%; 
        margin: 10px auto; 
        text-align: left; 
    }
    select { padding: 5px; font-size: 16px; }
</style>
<script>
function updateClock() {
    const now = new Date();
    const timeString = now.toLocaleString('ja-JP');
    const clockEl = document.getElementById('clock');
    if(clockEl) clockEl.innerText = timeString;
}

function reloadData(){
    const selectEl = document.getElementById('room_select');
    const roomId = selectEl ? selectEl.value : '';

    fetch('sensor.php?ajax=1&room_id=' + encodeURIComponent(roomId))
        .then(r => {
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
            
            if(rows.length === 0) {
                html += '<tr><td colspan="6">No data found</td></tr>';
            } else {
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
            }
            html += '</table>';
            document.getElementById('sensor_table').innerHTML = html;
        })
        .catch(err => { console.error("Update failed:", err); });
}

window.onload = function() {
    updateClock();
    setInterval(updateClock, 1000);
    setInterval(reloadData, 5000);
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
        <option value="">���ׂ�</option>
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