<?php
/*
    name : graph page ver 1.0.0
*/
?>

<?php
// ==================================================
// ★設定エリア
// ==================================================
$enable_auto_reload = true;  // 自動更新を使うか
$show_progress_bar  = false;  // 緑のバーを表示するか
// ==================================================

// 1. DB接続設定
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

    // 2. データ取得
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

    // 3. データ整形
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
            margin: 20px; padding: 15px; 
            background-color: #f0f0f0; border-radius: 8px; 
            display: inline-block; text-align: left;
        }
        select, input { padding: 5px 10px; font-size: 16px; margin: 0 5px; }
        label { font-weight: bold; margin-left: 15px; }
        label:first-child { margin-left: 0; }
        
        .last-update { margin-left: 10px; font-size: 0.9em; color: #555; font-weight: bold; }
        
        #progress-bar {
            width: 0%; height: 4px; background-color: #4caf50;
            position: fixed; top: 0; left: 0; transition: width 1s linear;
            display: <?php echo ($enable_auto_reload && $show_progress_bar) ? 'block' : 'none'; ?>;
        }

        /* ▼▼▼ 追加したCSS ▼▼▼ */
        /* 4分割表示用のグリッドレイアウト */
        #multi-view-container {
            display: none; /* 初期状態は非表示 */
            grid-template-columns: 1fr 1fr; /* 横2列 */
            gap: 20px;
            width: 90%;
            margin: auto;
        }
        .chart-box {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
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
            <option value="all">すべて</option>
            <option value="humidity">湿度</option>
            <option value="co2">二酸化炭素</option>
            <option value="illuminance">光度</option>
        </select>

        <span class="last-update">(最終更新: <?php echo $last_update_text; ?>)</span>
    </div>

    <div style="width: 80%; margin: auto;">
        <?php if (empty($results)): ?>
            <p style="color: red; margin-top: 50px;">※ 選択された部屋・日付のデータはありません。</p>
        <?php else: ?>
            
            <div id="single-view-container">
                <canvas id="myChart"></canvas>
            </div>

            <div id="multi-view-container">
                <div class="chart-box"><canvas id="chartTemp"></canvas></div>
                <div class="chart-box"><canvas id="chartHum"></canvas></div>
                <div class="chart-box"><canvas id="chartCo2"></canvas></div>
                <div class="chart-box"><canvas id="chartIllu"></canvas></div>
            </div>

        <?php endif; ?>
    </div>

    <script>
        const labels = <?php echo json_encode($labels); ?>;
        
        // 全データを定義
        const allData = {
            temperature: { data: <?php echo json_encode($data_temp); ?>, label: '温度 (℃)', color: 'rgb(255, 99, 132)' },
            humidity:    { data: <?php echo json_encode($data_hum); ?>,  label: '湿度 (%)',  color: 'rgb(54, 162, 235)' },
            co2:         { data: <?php echo json_encode($data_co2); ?>,  label: 'CO2 (ppm)', color: 'rgb(75, 192, 192)' },
            illuminance: { data: <?php echo json_encode($data_illu); ?>, label: '光度 (lx)', color: 'rgb(255, 205, 86)' }
        };

        if (labels.length > 0) {
            // 単体表示用チャートインスタンス
            let singleChart = null;
            // 4分割表示用チャートインスタンス管理用
            let multiCharts = {}; 

            // 共通オプション作成関数
            function createOptions(titleText) {
                return {
                    responsive: true,
                    scales: {
                        x: {
                            ticks: { maxTicksLimit: 10, autoSkip: true }
                        },
                        y: {
                            title: {
                                display: true,
                                text: titleText,
                                rotation: 0,
                                align: 'end',
                                font: { weight: 'bold' },
                                padding: { top: 0, bottom: 0, y: 10 }
                            },
                            beginAtZero: false
                        }
                    },
                    plugins: {
                        legend: { display: false }, // 小さいグラフは見出しがあるので凡例を消す
                        title: { display: true, text: titleText } // グラフ上部にタイトル
                    }
                };
            }

            // 初期化処理
            const savedSensor = localStorage.getItem('selectedSensor') || 'temperature';
            document.getElementById('sensorSelect').value = savedSensor;
            
            // 初回表示実行
            updateView(savedSensor);

            // 切り替え処理 (グローバル関数)
            window.changeSensor = function() {
                const selectedKey = document.getElementById('sensorSelect').value;
                localStorage.setItem('selectedSensor', selectedKey);
                updateView(selectedKey);
            }

            // 表示更新ロジック
            function updateView(key) {
                const singleContainer = document.getElementById('single-view-container');
                const multiContainer  = document.getElementById('multi-view-container');

                if (key === 'all') {
                    // --- すべて表示モード ---
                    singleContainer.style.display = 'none';
                    multiContainer.style.display = 'grid'; // グリッド表示にする

                    // 4つのグラフがまだ作られていなければ作成する
                    if (Object.keys(multiCharts).length === 0) {
                        createMultiChart('chartTemp', 'temperature');
                        createMultiChart('chartHum',  'humidity');
                        createMultiChart('chartCo2',  'co2');
                        createMultiChart('chartIllu', 'illuminance');
                    }

                } else {
                    // --- 単体表示モード ---
                    multiContainer.style.display = 'none';
                    singleContainer.style.display = 'block';

                    const target = allData[key];

                    // 単体グラフが存在しなければ作成、あれば更新
                    if (!singleChart) {
                        const ctx = document.getElementById('myChart').getContext('2d');
                        singleChart = new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: labels,
                                datasets: [{
                                    label: target.label,
                                    data: target.data,
                                    borderColor: target.color,
                                    backgroundColor: target.color,
                                    tension: 0.1,
                                    fill: false,
                                    pointRadius: 2
                                }]
                            },
                            options: {
                                responsive: true,
                                scales: {
                                    x: { title: { display: true, text: '測定時間' }, ticks: { maxTicksLimit: 20 } },
                                    y: {
                                        title: { display: true, text: target.label, rotation: 0, align: 'end', font: { weight: 'bold' } },
                                        beginAtZero: false
                                    }
                                }
                            }
                        });
                    } else {
                        // データ更新
                        singleChart.data.datasets[0].data = target.data;
                        singleChart.data.datasets[0].label = target.label;
                        singleChart.data.datasets[0].borderColor = target.color;
                        singleChart.data.datasets[0].backgroundColor = target.color;
                        singleChart.options.scales.y.title.text = target.label;
                        singleChart.update();
                    }
                }
            }

            // 小さいグラフを作成するヘルパー関数
            function createMultiChart(canvasId, dataKey) {
                const ctx = document.getElementById(canvasId).getContext('2d');
                const target = allData[dataKey];
                
                multiCharts[dataKey] = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: target.data,
                            borderColor: target.color,
                            backgroundColor: target.color,
                            tension: 0.1,
                            pointRadius: 1, // 点をさらに小さく
                            borderWidth: 1.5 // 線を少し細く
                        }]
                    },
                    options: createOptions(target.label)
                });
            }

            // 自動更新ロジック
            const enableAutoReload = <?php echo json_encode($enable_auto_reload); ?>;
            if (enableAutoReload) {
                let timeLeft = 0;
                const updateInterval = 10;
                setInterval(() => {
                    timeLeft++;
                    const progressBar = document.getElementById('progress-bar');
                    if (progressBar && progressBar.style.display !== 'none') {
                        progressBar.style.width = (timeLeft / updateInterval) * 100 + '%';
                    }
                    if (timeLeft >= updateInterval) window.location.reload();
                }, 1000);
            }
        }
    </script>
</body>
</html>