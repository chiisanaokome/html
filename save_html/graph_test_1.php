<?php
// ==================================================
// ★設定エリア (ここを変更してください)
// ==================================================
// 1. 自動更新機能を使うか (true: 使う, false: 使わない)
$enable_auto_reload = true; 

// 2. 緑色のバーを表示するか (true: 表示, false: 非表示)
// ※ 自動更新がOFFの場合は、バーも自動的に消えます
$show_progress_bar  = false; 
// ==================================================


// --------------------------------------------------
// 1. DB接続設定
// --------------------------------------------------
$host = '10.100.56.163'; 
$port = '5432';
$dbname = 'group3';
$user = 'gthree';
$pass = 'Gthree';

$dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";

$target_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$target_room = isset($_GET['room']) ? $_GET['room'] : 1;

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --------------------------------------------------
    // 2. データ取得
    // --------------------------------------------------
    $sql = "SELECT measured_at, temperature, humidity, co2, illuminance 
            FROM sensor_logs 
            WHERE room_id = :target_room 
            AND CAST(measured_at AS DATE) = :target_date
            ORDER BY measured_at ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':target_room', $target_room, PDO::PARAM_INT);
    $stmt->bindValue(':target_date', $target_date, PDO::PARAM_STR);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --------------------------------------------------
    // 3. データ整形
    // --------------------------------------------------
    $labels = []; 
    $data_temp = [];
    $data_hum = [];
    $data_co2 = [];
    $data_illu = [];

    $last_update_text = "--:--:--";

    if (!empty($results)) {
        $last_row = end($results); 
        $last_update_text = date('H:i:s', strtotime($last_row['measured_at']));

        foreach ($results as $row) {
            $labels[] = date('H:i:s', strtotime($row['measured_at'])); 
            $data_temp[] = $row['temperature'];
            $data_hum[]  = $row['humidity'];
            $data_co2[]  = $row['co2'];
            $data_illu[] = $row['illuminance'];
        }
    }

} catch (PDOException $e) {
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
    <style>
        body { font-family: sans-serif; text-align: center; }
        .controls { 
            margin: 20px; 
            padding: 15px; 
            background-color: #f0f0f0; 
            border-radius: 8px; 
            display: inline-block;
            text-align: left;
        }
        select, input { padding: 5px 10px; font-size: 16px; margin: 0 5px; }
        label { font-weight: bold; margin-left: 15px; }
        label:first-child { margin-left: 0; }
        
        .last-update {
            margin-left: 10px;
            font-size: 0.9em;
            color: #555;
            font-weight: bold;
        }
        
        /* バーのスタイル（PHPの設定によって表示・非表示制御） */
        #progress-bar {
            width: 0%;
            height: 4px;
            background-color: #4caf50;
            position: fixed;
            top: 0;
            left: 0;
            transition: width 1s linear;
            /* バーの設定がOFFならCSSでも消しておく */
            display: <?php echo ($enable_auto_reload && $show_progress_bar) ? 'block' : 'none'; ?>;
        }
    </style>
</head>
<body>
    <div id="progress-bar"></div>

    <div class="controls">
        <form action="" method="GET" style="display: inline;">
            <label for="roomSelect">部屋:</label>
            <select id="roomSelect" name="room" onchange="this.form.submit()">
                <option value="1" <?php if($target_room == 1) echo 'selected'; ?>>0-502</option>
                <option value="2" <?php if($target_room == 2) echo 'selected'; ?>>0-504</option>
                <option value="3" <?php if($target_room == 3) echo 'selected'; ?>>0-506</option>
            </select>

            <label for="datePicker">日付:</label>
            <input type="date" id="datePicker" name="date" 
                   value="<?php echo htmlspecialchars($target_date); ?>" 
                   onchange="this.form.submit()">
        </form>

        <span style="border-left: 1px solid #999; margin: 0 15px;"></span>

        <label for="sensorSelect">項目:</label>
        <select id="sensorSelect" onchange="changeSensor()">
            <option value="temperature">温度</option>
            <option value="humidity">湿度</option>
            <option value="co2">二酸化炭素</option>
            <option value="illuminance">光度</option>
        </select>

        <span class="last-update">
            (最終更新: <?php echo $last_update_text; ?>)
        </span>
    </div>

    <div style="width: 80%; margin: auto;">
        <?php if (empty($results)): ?>
            <p style="color: red; margin-top: 50px;">
                ※ 選択された部屋・日付のデータはありません。
            </p>
        <?php else: ?>
            <canvas id="myChart"></canvas>
        <?php endif; ?>
    </div>

    <script>
        const labels = <?php echo json_encode($labels); ?>;
        
        const allData = {
            temperature: { data: <?php echo json_encode($data_temp); ?>, label: '温度 (℃)', color: 'rgb(255, 99, 132)' },
            humidity:    { data: <?php echo json_encode($data_hum); ?>,  label: '湿度 (%)',  color: 'rgb(54, 162, 235)' },
            co2:         { data: <?php echo json_encode($data_co2); ?>,  label: 'CO2 (ppm)', color: 'rgb(75, 192, 192)' },
            illuminance: { data: <?php echo json_encode($data_illu); ?>, label: '光度 (lx)', color: 'rgb(255, 205, 86)' }
        };

        if (labels.length > 0) {
            // localStorageから選択項目を復元
            const savedSensor = localStorage.getItem('selectedSensor') || 'temperature';
            document.getElementById('sensorSelect').value = savedSensor;
            
            const initialData = allData[savedSensor];

            const chartOptions = {
                responsive: true,
                scales: {
                    x: {
                        title: { display: true, text: '測定時間' },
                        ticks: { maxTicksLimit: 20, autoSkip: true }
                    },
                    y: {
                        title: {
                            display: true,
                            text: initialData.label,
                            rotation: 0,
                            align: 'end',
                            font: { weight: 'bold' },
                            padding: { top: 0, bottom: 0, y: 10 }
                        },
                        beginAtZero: false
                    }
                }
            };

            const ctx = document.getElementById('myChart').getContext('2d');
            
            let myChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: initialData.label,
                        data: initialData.data,
                        borderColor: initialData.color,
                        backgroundColor: initialData.color,
                        tension: 0.1,
                        fill: false,
                        pointRadius: 2
                    }]
                },
                options: chartOptions
            });

            window.changeSensor = function() {
                const selectedKey = document.getElementById('sensorSelect').value;
                localStorage.setItem('selectedSensor', selectedKey);

                const target = allData[selectedKey];

                myChart.data.datasets[0].data = target.data;
                myChart.data.datasets[0].label = target.label;
                myChart.data.datasets[0].borderColor = target.color;
                myChart.data.datasets[0].backgroundColor = target.color;
                myChart.options.scales.y.title.text = target.label;
                myChart.update();
            }

            // ------------------------------------------------
            // 3. 自動更新ロジック (PHPの設定値を反映)
            // ------------------------------------------------
            // PHPの変数をJavaScriptに渡す
            const enableAutoReload = <?php echo json_encode($enable_auto_reload); ?>;

            if (enableAutoReload) {
                let timeLeft = 0;
                const updateInterval = 10; // 秒

                setInterval(() => {
                    timeLeft++;
                    
                    // バーの要素を取得
                    const progressBar = document.getElementById('progress-bar');
                    
                    // バーが存在する（表示設定がON）なら長さを変える
                    if (progressBar && progressBar.style.display !== 'none') {
                        const percentage = (timeLeft / updateInterval) * 100;
                        progressBar.style.width = percentage + '%';
                    }

                    if (timeLeft >= updateInterval) {
                        window.location.reload(); 
                    }
                }, 1000);
            }
        }
    </script>
</body>
</html>