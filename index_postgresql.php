<?php
// データベース接続設定（PostgreSQL）
$host = '10.100.56.163';
$dbname = 'group3';
$username = 'gthree';
$password = 'Gthree';
$port = '5432'; // PostgreSQLのデフォルトポート

// AJAX APIリクエストの処理
if (isset($_GET['api']) && $_GET['api'] === 'attendance') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        // PostgreSQL用の接続文字列
        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
        $period = isset($_GET['period']) ? (int)$_GET['period'] : 0;
        $target_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        
        if ($room_id <= 0 || $period <= 0) {
            echo json_encode(['count' => 0, 'error' => 'Invalid parameters']);
            exit;
        }
        
        // 出席している学生数を取得（PostgreSQL用のクエリ）
        // logged_at::date でタイムスタンプから日付を取得
        // TRIM(action) でスペースを削除
        $sql = "SELECT COUNT(DISTINCT user_id) as count 
                FROM attendance_logs 
                WHERE room_id = :room_id 
                AND period = :period 
                AND logged_at::date = :target_date::date 
                AND TRIM(action) = '出席'";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':room_id', $room_id, PDO::PARAM_INT);
        $stmt->bindParam(':period', $period, PDO::PARAM_INT);
        $stmt->bindParam(':target_date', $target_date, PDO::PARAM_STR);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'count' => (int)$result['count'],
            'room_id' => $room_id,
            'period' => $period,
            'date' => $target_date
        ]);
        
    } catch (PDOException $e) {
        echo json_encode([
            'count' => 0,
            'error' => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>学校スマート管理システム</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --bg-blue: #e3f2fd; --card-red: #ffebee; --card-green: #e8f5e9; }
        body { font-family: "Helvetica Neue", Arial, "Hiragino Kaku Gothic ProN", "Hiragino Sans", Meiryo, sans-serif; background-color: #f0f2f5; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1000px; margin: auto; background: white; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); overflow: hidden; }
        .header { background-color: var(--bg-blue); padding: 30px 20px; text-align: center; position: relative; }
        .top-btn { position: absolute; left: 20px; top: 20px; background: white; border: 1px solid #ddd; padding: 8px 18px; border-radius: 20px; text-decoration: none; color: #333; font-size: 0.9em; display: flex; align-items: center; gap: 5px; }
        .sub-title { font-size: 0.85em; color: #666; margin-bottom: 8px; }
        .main-date { font-size: 2em; font-weight: bold; margin: 0; color: #2c3e50; }

        .summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); border-top: 1px solid #eef; border-bottom: 1px solid #eef; }
        .summary-item { padding: 20px; text-align: center; border-right: 1px solid #eef; }
        .summary-label { font-size: 0.85em; color: #888; margin-bottom: 8px; font-weight: bold; }
        .summary-value { font-size: 1.5em; font-weight: bold; color: #34495e; }

        .alert-section { background-color: #fff9e6; margin: 25px; padding: 20px; border-radius: 10px; border-left: 6px solid #f1c40f; }
        .alert-title { color: #856404; font-weight: bold; margin-bottom: 12px; display: flex; align-items: center; gap: 10px; }
        .alert-list { margin: 0; padding-left: 25px; font-size: 0.95em; line-height: 1.6; color: #856404; }

        .room-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; padding: 0 25px 25px; }
        .room-card { border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .room-status { text-align: center; font-size: 0.9em; margin-bottom: 15px; font-weight: bold; }
        .card-red { background-color: var(--card-red); border: 1px solid #ffcdd2; }
        .card-green { background-color: var(--card-green); border: 1px solid #c8e6c9; }
        .sensor-data { display: grid; grid-template-columns: 35px 1fr; row-gap: 12px; align-items: center; }

        .footer-btns { display: flex; justify-content: center; gap: 25px; padding: 20px 0 40px; }
        .btn { padding: 14px 30px; border-radius: 8px; border: none; color: white; cursor: pointer; font-weight: bold; text-decoration: none; display: flex; align-items: center; gap: 8px; }
        .btn-gray { background-color: #607d8b; }
        .btn-blue { background-color: #007bff; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <a href="#" class="top-btn"><i class="fas fa-home"></i> トップページ</a>
        <div class="sub-title">見えない環境を可視化する 〜学校スマート管理システム〜（グループ3）</div>
        <div class="main-date" id="current-clock">----/--/--(--) --:--</div>
    </div>

    <div class="summary-grid">
        <div class="summary-item">
            <div class="summary-label">使用中教室</div>
            <div class="summary-value" id="used-rooms-count">0 / 3</div>
        </div>
        <div class="summary-item">
            <div class="summary-label">警告発生</div>
            <div class="summary-value" id="alert-count" style="color:#e74c3c;">0 件</div>
        </div>
        <div class="summary-item" style="border-right:none;">
            <div class="summary-label">最終更新</div>
            <div id="last-update-hms" class="summary-value" style="color: #7f8c8d;">--時--分--秒</div>
        </div>
    </div>

    <div class="alert-section" id="alert-box">
        <div class="alert-title"><i class="fas fa-exclamation-triangle"></i> 現在の警告</div>
        <ul class="alert-list" id="alert-list"><li>現在、警告はありません。</li></ul>
    </div>

    <div class="room-grid" id="room-container">
        <div class="room-card card-green" id="card-1"><div class="room-status"><span id="st-1">□ 未使用</span> | 0-502</div><div class="sensor-data"><i class="fas fa-users"></i><span id="p-1">在室：--名</span><i class="fas fa-thermometer-half"></i><span id="t-1">--℃/--%</span><i class="fas fa-wind"></i><span id="c-1">CO2:--ppm</span><i class="fas fa-lightbulb"></i><span id="l-1">照明:--</span></div></div>
        <div class="room-card card-green" id="card-2"><div class="room-status"><span id="st-2">□ 未使用</span> | 0-504</div><div class="sensor-data"><i class="fas fa-users"></i><span id="p-2">在室：--名</span><i class="fas fa-thermometer-half"></i><span id="t-2">--℃/--%</span><i class="fas fa-wind"></i><span id="c-2">CO2:--ppm</span><i class="fas fa-lightbulb"></i><span id="l-2">照明:--</span></div></div>
        <div class="room-card card-green" id="card-3"><div class="room-status"><span id="st-3">□ 未使用</span> | 0-506</div><div class="sensor-data"><i class="fas fa-users"></i><span id="p-3">在室：--名</span><i class="fas fa-thermometer-half"></i><span id="t-3">--℃/--%</span><i class="fas fa-wind"></i><span id="c-3">CO2:--ppm</span><i class="fas fa-lightbulb"></i><span id="l-3">照明:--</span></div></div>
    </div>

    <div class="footer-btns">
        <a href="detail.php" class="btn btn-gray"><i class="fas fa-chart-line"></i> 環境管理画面へ</a>
        <a href="attendance.php" class="btn btn-blue"><i class="fas fa-user-check"></i> 出席管理画面へ</a>
    </div>
</div>

<script>
function updateClock() {
    const now = new Date();
    const days = ['日','月','火','水','木','金','土'];
    document.getElementById('current-clock').innerText = `${now.getFullYear()}/${(now.getMonth()+1)}/${now.getDate()}(${days[now.getDay()]}) ${now.getHours()}:${now.getMinutes().toString().padStart(2,'0')}`;
}

// 現在の時限を判定する関数
function getCurrentPeriod() {
    const now = new Date();
    const hours = now.getHours();
    const minutes = now.getMinutes();
    const time = hours * 60 + minutes; // 分単位に変換

    // 8:50~10:30 → 1限
    if (time >= 530 && time < 630) return 1;
    // 10:35~12:15 → 2限
    if (time >= 635 && time < 735) return 2;
    // 13:00~14:40 → 3限
    if (time >= 780 && time < 880) return 3;
    // 14:45~16:25 → 4限
    if (time >= 885 && time < 985) return 4;
    
    // 授業時間外
    return null;
}

// 出席人数を取得する関数
async function fetchAttendanceCount(roomId, period) {
    if (!period) return 0;
    
    try {
        const today = new Date().toISOString().split('T')[0]; // YYYY-MM-DD形式
        const res = await fetch(`?api=attendance&room_id=${roomId}&period=${period}&date=${today}`);
        const data = await res.json();
        return data.count || 0;
    } catch (e) {
        console.error('出席データ取得エラー:', e);
        return 0;
    }
}

async function fetchLatestData() {
    const roomIds = [1, 2, 3];
    let alerts = [];
    let usedRooms = 0;
    let newestTime = null;
    const currentPeriod = getCurrentPeriod();

    for (let id of roomIds) {
        try {
            const res = await fetch(`sensor.php?ajax=1&room_id=${id}`);
            const data = await res.json();
            if (data && data.length > 0) {
                const r = data[0];
                if (!newestTime || r.measured_at > newestTime) newestTime = r.measured_at;

                const lux = parseInt(r.illuminance);
                const co2 = parseInt(r.co2);
                const isUsed = lux > 100;
                if (isUsed) usedRooms++;

                // 在室人数を取得
                let attendanceCount = 0;
                if (currentPeriod) {
                    attendanceCount = await fetchAttendanceCount(id, currentPeriod);
                }

                // 各種データを表示
                document.getElementById(`p-${id}`).innerText = `在室：${attendanceCount}名`;
                document.getElementById(`t-${id}`).innerText = `${r.temperature}℃ / ${r.humidity}%`;
                document.getElementById(`c-${id}`).innerText = `CO2: ${co2} ppm`;
                document.getElementById(`card-${id}`).className = isUsed ? 'room-card card-red' : 'room-card card-green';
                document.getElementById(`st-${id}`).innerText = isUsed ? '■ 使用中' : '□ 未使用';
                document.getElementById(`l-${id}`).innerText = isUsed ? '照明: 点灯中' : '照明: 消灯';

                // 警告チェック
                if (co2 > 1000) {
                    alerts.push(`教室 ${r.room_name} : CO2濃度が高いため換気をしてください`);
                }
                if (id === 2 && isUsed && attendanceCount === 0) {
                    alerts.push(`教室 ${r.room_name} : 在室0名で電源が点灯しています`);
                }
            }
        } catch (e) { 
            console.error('センサーデータ取得エラー:', e); 
        }
    }

    document.getElementById('used-rooms-count').innerText = `${usedRooms} / 3`;
    document.getElementById('alert-count').innerText = `${alerts.length} 件`;

    if (newestTime) {
        const timeParts = newestTime.split(' ')[1].split(':');
        document.getElementById('last-update-hms').innerText = `${parseInt(timeParts[0])}時${parseInt(timeParts[1])}分${parseInt(timeParts[2])}秒`;
    }

    const alertListEl = document.getElementById('alert-list');
    alertListEl.innerHTML = alerts.length > 0 ? alerts.map(a => `<li>${a}</li>`).join('') : '<li>現在、警告はありません。</li>';
}

updateClock();
setInterval(updateClock, 1000);
fetchLatestData();
setInterval(fetchLatestData, 5000);
</script>
</body>
</html>
