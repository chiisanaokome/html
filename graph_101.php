<?php
/*
    name : graph page ver 1.1.0
    description : Added latest value display below charts
*/

// ==================================================
// ★設定エリア
// ==================================================
$enable_auto_reload = true;  // 自動更新を使うか
$show_progress_bar  = false; // 緑のバーを表示するか
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

        /* ▼▼▼ グリッドレイアウト ▼▼▼ */
        #multi-view-container {
            display: none; 
            grid-template-columns: 1fr 1fr; 
            gap: 20px;
            width: 90%;
            margin: auto;
        }
        .chart-box {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            /* 下部にマージンを追加 */
            margin-bottom: 20px;
        }

        /* ▼▼▼ 追加: 数値表示エリアのデザイン ▼▼▼ */
        .value-display {
            margin-top: 10px;       /* グラフとの間隔 */
            padding-top: 5px;
            border-top: 1px solid #eee; /* 区切り線 */
            text-align: center;
            font-size: 24px;        /* 文字サイズ大 */
            font-weight: bold;
        }
        .unit {
            font-size: 14px;
            color: #888;
            font-weight: normal;
            margin-left: 5px;
        }
        /* 各項目の色定義 */
        .text-temp { color: rgb(255, 99, 132); }
        .text-hum  { color: rgb(54, 162, 235); }
        .text-co2  { color: rgb(75, 192, 192); }
        .text-illu { color: rgb(255, 205, 86); }
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
                <div class="chart-box">
                    <canvas id="myChart"></canvas>
                    <div id="single-value-display" class="value-display">
                        <span id="single-val">--</span><span id="single-unit" class="unit"></span>
                    </div>
                </div>
            </div>

            <div id="multi-view-container">
                <div class="chart-box">
                    <canvas id="chartTemp"></canvas>
                    <div class="value-display text-temp">
                        <span id="val-temp">--</span><span class="unit">℃</span>
                    </div>
                </div>
                <div class="chart-box">
                    <canvas id="chartHum"></canvas>
                    <div class="value-display text-hum">
                        <span id="val-hum">--</span><span class="unit">%</span>
                    </div>
                </div>
                <div class="chart-box">
                    <canvas id="chartCo2"></canvas>
                    <div class="value-display text-co2">
                        <span id="val-co2">--</span><span class="unit">ppm</span>
                    </div>
                </div>
                <div class="chart-box">
                    <canvas id="chartIllu"></canvas>
                    <div class="value-display text-illu">
                        <span id="val-illu">--</span><span class="unit">lx</span>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <script>
        const labels = <?php echo json_encode($labels); ?>;
        
        // 全データを定義（ここに単位や色クラス情報も持たせると便利です）
        const allData = {
            temperature: { data: <?php echo json_encode($data_temp); ?>, label: '温度 (℃)', color: 'rgb(255, 99, 132)', unit: '℃', class: 'text-temp' },
            humidity:    { data: <?php echo json_encode($data_hum); ?>,  label: '湿度 (%)',  color: 'rgb(54, 162, 235)', unit: '%',  class: 'text-hum' },
            co2:         { data: <?php echo json_encode($data_co2); ?>,  label: 'CO2 (ppm)', color: 'rgb(75, 192, 192)', unit: 'ppm', class: 'text-co2' },
            illuminance: { data: <?php echo json_encode($data_illu); ?>, label: '光度 (lx)', color: 'rgb(255, 205, 86)', unit: 'lx',  class: 'text-illu' }
        };

        if (labels.length > 0) {
            let singleChart = null;
            let multiCharts = {}; 

            // 共通オプション作成関数
            function createOptions(titleText) {
                return {
                    responsive: true,
                    scales: {
                        x: { ticks: { maxTicksLimit: 10, autoSkip: true } },
                        y: {
                            title: {
                                display: true, text: titleText,
                                rotation: 0, align: 'end', font: { weight: 'bold' },
                                padding: { top: 0, bottom: 0, y: 10 }
                            },
                            beginAtZero: false
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        title: { display: true, text: titleText }
                    }
                };
            }

            // 初期化処理
            const savedSensor = localStorage.getItem('selectedSensor') || 'temperature';
            document.getElementById('sensorSelect').value = savedSensor;
            updateView(savedSensor);

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
                    multiContainer.style.display = 'grid';

                    // 4つのグラフ作成 & 数値更新
                    if (Object.keys(multiCharts).length === 0) {
                        createMultiChart('chartTemp', 'temperature', 'val-temp');
                        createMultiChart('chartHum',  'humidity',    'val-hum');
                        createMultiChart('chartCo2',  'co2',         'val-co2');
                        createMultiChart('chartIllu', 'illuminance', 'val-illu');
                    } else {
                        // 既にグラフがある場合でも、最新値だけは更新しておく（リロード直後用）
                        updateValueText('temperature', 'val-temp');
                        updateValueText('humidity',    'val-hum');
                        updateValueText('co2',         'val-co2');
                        updateValueText('illuminance', 'val-illu');
                    }

                } else {
                    // --- 単体表示モード ---
                    multiContainer.style.display = 'none';
                    singleContainer.style.display = 'block';

                    const target = allData[key];

                    // グラフ更新
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
                                    tension: 0.1, fill: false, pointRadius: 2
                                }]
                            },
                            options: createOptions(target.label)
                        });
                    } else {
                        singleChart.data.datasets[0].data = target.data;
                        singleChart.data.datasets[0].label = target.label;
                        singleChart.data.datasets[0].borderColor = target.color;
                        singleChart.data.datasets[0].backgroundColor = target.color;
                        singleChart.options.scales.y.title.text = target.label;
                        singleChart.options.plugins.title.text = target.label;
                        singleChart.update();
                    }

                    // ★追加: 単体表示の数値を更新
                    const lastVal = target.data[target.data.length - 1];
                    const valBox = document.getElementById('single-value-display');
                    
                    document.getElementById('single-val').innerText = lastVal;
                    document.getElementById('single-unit').innerText = target.unit;
                    
                    // 色クラスをリセットして適用
                    valBox.className = 'value-display ' + target.class;
                }
            }

            // 数値テキストを更新するだけの関数
            function updateValueText(dataKey, elementId) {
                const target = allData[dataKey];
                const lastVal = target.data[target.data.length - 1];
                const el = document.getElementById(elementId);
                if(el) el.innerText = lastVal;
            }

            // 小さいグラフを作成するヘルパー関数
            function createMultiChart(canvasId, dataKey, valElementId) {
                const ctx = document.getElementById(canvasId).getContext('2d');
                const target = allData[dataKey];
                
                // グラフ作成
                multiCharts[dataKey] = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: target.data,
                            borderColor: target.color,
                            backgroundColor: target.color,
                            tension: 0.1, pointRadius: 1, borderWidth: 1.5
                        }]
                    },
                    options: createOptions(target.label)
                });

                // ★数値の更新
                updateValueText(dataKey, valElementId);
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