<?php
// --- データベース接続設定 ---
$host = '10.100.56.163';
$dbname = 'group3';
$user = 'gthree';
$pass = 'Gthree';

try {
    // 接続オプションにタイムアウトを設定（接続できない場合に備えて）
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass, [
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("データベース接続に失敗しました: " . $e->getMessage());
}

// --- 時限判定ロジック ---
$now = new DateTime();
$currentTime = $now->format('H:i');
$currentPeriod = 0;

$schedule = [
    1 => ['start' => '08:50', 'end' => '10:30'],
    2 => ['start' => '10:35', 'end' => '12:15'],
    3 => ['start' => '13:00', 'end' => '14:40'],
    4 => ['start' => '14:45', 'end' => '16:25'],
];

foreach ($schedule as $p => $time) {
    if ($currentTime >= $time['start'] && $currentTime <= $time['end']) {
        $currentPeriod = $p;
        break;
    }
}

// --- 各教室のデータ取得 ---
$roomIds = [1, 2, 3];
$roomData = [];
$alerts = [];
$roomNames = [1 => '0-502', 2 => '0-504', 3 => '0-506'];

foreach ($roomIds as $id) {
    // 指定されたロジック: IPアドレス(user_id)の重複を消して人数カウント + センサーデータ
    $sql = "SELECT 
                (SELECT COUNT(DISTINCT user_id) 
                 FROM attendance_logs 
                 WHERE room_id = :rid 
                   AND period = :period 
                   AND action = '出席' 
                   AND DATE(logged_at) = CURRENT_DATE
                ) AS present_count,
                s.temperature, s.humidity, s.illuminance, s.co2, s.measured_at
            FROM sensor_logs s
            WHERE s.room_id = :rid
            ORDER BY s.measured_at DESC 
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['rid' => $id, 'period' => $currentPeriod]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($data) {
        $roomData[$id] = $data;
        
        // 警告判定
        if ($data['co2'] > 1000) {
            $alerts[] = "教室 {$roomNames[$id]}: CO2濃度が高いため換気してください。";
        }
        if ($data['present_count'] == 0 && $data['illuminance'] > 100) {
            $alerts[] = "教室 {$roomNames[$id]}: 出席登録0名ですが照明が点灯しています。";
        }
    } else {
        // センサーデータがない場合の初期値
        $roomData[$id] = ['present_count' => 0, 'temperature' => '--', 'humidity' => '--', 'co2' => '--', 'illuminance' => 0];
    }
}

$usedRoomsCount = 0;
foreach ($roomData as $rd) {
    if ($rd['present_count'] > 0 || ($rd['illuminance'] > 100)) $usedRoomsCount++;
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
        body { font-family: "Helvetica Neue", Arial, sans-serif; background-color: #f0f2f5; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1000px; margin: auto; background: white; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); overflow: hidden; }
        .header { background-color: var(--bg-blue); padding: 30px; text-align: center; }
        .main-date { font-size: 2em; font-weight: bold; color: #2c3e50; }
        .summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); border-top: 1px solid #eef; border-bottom: 1px solid #eef; }
        .summary-item { padding: 20px; text-align: center; border-right: 1px solid #eef; }
        .summary-label { font-size: 0.85em; color: #888; font-weight: bold; margin-bottom: 5px; }
        .summary-value { font-size: 1.5em; font-weight: bold; }
        .alert-section { background-color: #fff9e6; margin: 25px; padding: 20px; border-radius: 10px; border-left: 6px solid #f1c40f; }
        .room-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; padding: 25px; }
        .room-card { padding: 20px; border-radius: 12px; border: 1px solid #ddd; transition: 0.3s; }
        .card-red { background-color: var(--card-red); border-color: #ffcdd2; }
        .card-green { background-color: var(--card-green); border-color: #c8e6c9; }
        .sensor-data { display: grid; grid-template-columns: 35px 1fr; row-gap: 12px; align-items: center; margin-top: 15px; }
    </style>
    <meta http-equiv="refresh" content="10">
</head>
<body>

<div class="container">
    <div class="header">
        <div style="font-size: 0.9em; color: #666; margin-bottom: 10px;">見えない環境を可視化する 〜学校スマート管理システム〜</div>
        <div class="main-date"><?php echo $now->format('Y/m/d (H:i)'); ?></div>
        <div style="margin-top: 10px; font-weight: bold; color: #007bff;">
            <?php echo $currentPeriod ? "現在: {$currentPeriod}限目" : "現在は授業時間外です"; ?>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-item">
            <div class="summary-label">使用中教室</div>
            <div class="summary-value"><?php echo $usedRoomsCount; ?> / 3</div>
        </div>
        <div class="summary-item">
            <div class="summary-label">現在の警告</div>
            <div class="summary-value" style="color: #e74c3c;"><?php echo count($alerts); ?> 件</div>
        </div>
        <div class="summary-item" style="border:none;">
            <div class="summary-label">最終取得</div>
            <div class="summary-value" style="color: #7f8c8d;"><?php echo $now->format('H:i:s'); ?></div>
        </div>
    </div>

    <?php if (!empty($alerts)): ?>
    <div class="alert-section">
        <div style="color: #856404; font-weight: bold; margin-bottom: 10px;">
            <i class="fas fa-exclamation-triangle"></i> 警告通知
        </div>
        <ul style="margin: 0; padding-left: 20px; color: #856404;">
            <?php foreach ($alerts as $alert): ?>
                <li><?php echo htmlspecialchars($alert); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="room-grid">
        <?php foreach ($roomIds as $id): 
            $rd = $roomData[$id];
            $isUsed = ($rd['present_count'] > 0 || $rd['illuminance'] > 100);
            $cardClass = $isUsed ? 'card-red' : 'card-green';
        ?>
        <div class="room-card <?php echo $cardClass; ?>">
            <div style="text-align: center; font-weight: bold; margin-bottom: 15px;">
                <?php echo $isUsed ? "■ 使用中" : "□ 未使用"; ?> | <?php echo $roomNames[$id]; ?>
            </div>
            <div class="sensor-data">
                <i class="fas fa-users"></i><span>在室：<?php echo $rd['present_count']; ?>名</span>
                <i class="fas fa-thermometer-half"></i><span><?php echo $rd['temperature']; ?>℃ / <?php echo $rd['humidity']; ?>%</span>
                <i class="fas fa-wind"></i><span>CO2: <?php echo $rd['co2']; ?> ppm</span>
                <i class="fas fa-lightbulb"></i><span>照明: <?php echo $rd['illuminance'] > 100 ? '点灯中' : '消灯'; ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</body>
</html>