<?php
// --------------------------------------------------
// 1. データベース接続設定 (PostgreSQL版)
// --------------------------------------------------
$host = '10.100.56.163'; 
$port = '5432'; // PostgreSQLのデフォルトポート
$dbname = 'group3';
$user = 'gthree';
$pass = 'Gthree';

// ★ここが重要：mysql: ではなく pgsql: になります
$dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --------------------------------------------------
    // 2. データ取得 (SQLクエリ)
    // --------------------------------------------------
    // PostgreSQLではカラム名の扱いが厳密ですが、小文字なら基本そのままで大丈夫です
    $sql = "SELECT measured_at, temperature 
            FROM sensor_logs 
            WHERE room_id = 1 
            ORDER BY measured_at ASC 
            LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --------------------------------------------------
    // 3. Chart.js用にデータを配列に整形
    // --------------------------------------------------
    $labels = []; 
    $data_points = [];

    foreach ($results as $row) {
        // strtotimeで時間を読み込んで、'H:i:s' (時:分:秒) の形式に変換します
        $labels[] = date('H:i:s', strtotime($row['measured_at'])); 
        
        $data_points[] = $row['temperature'];
    }

} catch (PDOException $e) {
    // エラーメッセージを表示
    echo "接続エラー: " . $e->getMessage();
    exit;
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>センサーロググラフ</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

    <div style="width: 80%; margin: auto;">
        <h2>Room 1 温度推移</h2>
        <canvas id="myChart"></canvas>
    </div>

    <script>
        // PHPの配列をJavaScriptの配列として受け取る
        // json_encodeを使うことで、安全にJSの変数に変換されます
        const timeLabels = <?php echo json_encode($labels); ?>;
        const sensorData = <?php echo json_encode($data_points); ?>;

        const ctx = document.getElementById('myChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: timeLabels, // 横軸（PHPから取得した時間）
                datasets: [{
                    label: '温度 (℃)', // 縦軸のラベル
                    data: sensorData, // 縦軸（PHPから取得した温度）
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    tension: 0.1,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: '測定時間'
                        }
                    },
                    y: {
                        title: {
                            display: true,
                            text: '温度 (Temperature)'
                        },
                        beginAtZero: false // 温度なので0から始まらなくて良い
                    }
                }
            }
        });
    </script>
</body>
</html>