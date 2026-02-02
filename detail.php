<?php
// =========================================================
//  SERVER SIDE (PHP)
// =========================================================

// DB接続設定
$host = '10.100.56.163';
$db   = 'group3';       
$user = 'gthree';       
$pass = 'Gthree';       
$port = '5432';
$dsn  = "pgsql:host=$host;port=$port;dbname=$db";

// ---------------------------------------------------------
// モード1: グラフ用データ取得 (?ajax=1)
// ---------------------------------------------------------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        
        $roomId = $_GET['room_id'] ?? 1;
        $date   = $_GET['date'] ?? date('Y-m-d');

        $sql = "SELECT measured_at, temperature, humidity, co2, illuminance
                FROM sensor_logs
                WHERE room_id = :room_id
                AND measured_at >= :start_date 
                AND measured_at <  :end_date
                ORDER BY measured_at ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'room_id' => $roomId,
            'start_date' => $date . ' 00:00:00',
            'end_date'   => date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00'
        ]);
        echo json_encode($stmt->fetchAll());
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------
// モード2: データが存在する日付一覧を取得 (?action=get_dates)
// ---------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_dates') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        
        $roomId = $_GET['room_id'] ?? 1;

        // データが存在する日付だけを抽出（重複なし）
        $sql = "SELECT DISTINCT DATE(measured_at) as available_date 
                FROM sensor_logs 
                WHERE room_id = :room_id
                ORDER BY available_date DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['room_id' => $roomId]);
        
        // 配列をフラットにして返す (例: ["2026-01-30", "2026-01-29"])
        $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($dates);
    } catch (PDOException $e) {
        echo json_encode([]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>環境管理画面 - 教室詳細</title>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/ja.js"></script> <style>
        /* 基本レイアウト */
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; }
        body { font-family: "Helvetica Neue", Arial, sans-serif; background: #eee; display: flex; flex-direction: column; align-items: center; justify-content: flex-start; padding: 10px; box-sizing: border-box; }
        
        .detail-container { 
            width: 98%; max-width: 1400px; height: 95vh; 
            background: white; border: 1px solid #333; padding: 15px; 
            display: flex; flex-direction: column; box-sizing: border-box;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .detail-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #333; padding-bottom: 5px; margin-bottom: 10px; flex-shrink: 0; }
        .header-left { font-weight: bold; font-size: 1.2em; display: flex; align-items: center; gap: 10px; }
        .header-right { font-size: 1em; color: #555; font-weight: bold; display: flex; align-items: center; }
        
        /* カレンダー入力欄のスタイル調整 */
        .flatpickr-input {
            font-size: 1em; padding: 4px; margin-right: 15px;
            border: 1px solid #ccc; border-radius: 4px; cursor: pointer;
            background: #fff; width: 140px; text-align: center;
        }

        .badge { background: red; color: white; padding: 3px 15px; border-radius: 20px; font-size: 0.8em; }
        .top-info-row { display: flex; gap: 20px; margin-bottom: 15px; flex-shrink: 0; }
        .judgment-box { flex: 2; background: #ffff33; border: 3px solid #000; padding: 15px; border-radius: 12px; text-align: center; font-weight: bold; }
        .main-status { font-size: 1.8em; }
        .status-msg { font-size: 1.1em; margin-top: 5px; }
        .risk-area { flex: 1.5; display: flex; justify-content: space-around; align-items: center; border: 1px solid #ccc; border-radius: 12px; padding: 5px; }
        .risk-item { text-align: center; font-weight: bold; font-size: 1em; }
        .status-circle { width: 50px; height: 50px; border-radius: 50%; border: 2px solid #333; margin: 5px auto; display: flex; align-items: center; justify-content: center; font-size: 1.1em; background: #fff; }

        .charts-container { flex-grow: 1; display: flex; flex-direction: column; gap: 10px; min-height: 0; } 
        .chart-card { border: 1px solid #ccc; padding: 10px; display: flex; align-items: center; flex: 1; min-height: 0; background: #fff; }
        .chart-info { width: 120px; text-align: center; border-right: 2px solid #eee; padding-right: 10px; flex-shrink: 0; }
        .chart-title { font-weight: bold; font-size: 1.2em; }
        .chart-value { font-size: 1.3em; font-weight: bold; margin-top: 5px; }
        .chart-wrapper { flex: 1; height: 100%; width: 100%; position: relative; overflow: hidden; }

        .footer-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px; flex-shrink: 0; }
        .nav-btn { background: #dcdcdc; border: 2px solid #999; padding: 15px; text-decoration: none; color: black; font-weight: bold; text-align: center; font-size: 1.1em; border-radius: 6px; }
        .btn-blue { background: #007bff; color: white; border-color: #0056b3; }
    </style>
</head>
<body>

<div class="detail-container">
    <div class="detail-header">
        <div class="header-left">
            教室環境モニター
            <select id="room_select" onchange="initCalendarAndCharts()" style="font-size: 1em; padding: 5px;">
                <option value="1" selected>0-502</option>
                <option value="2">0-504</option>
                <option value="3">0-506</option>
            </select>
            <span id="light_badge" class="badge">--</span>
        </div>
        <div class="header-right">
            <input type="text" id="date_select" placeholder="日付を選択">
            <span>最終更新: <span id="last_update">--:--:--</span></span>
        </div>
    </div>

    <div class="top-info-row">
        <div id="judgment_box" class="judgment-box">
            <div id="main_status" class="main-status">読み込み中...</div>
            <div id="status_msg" class="status-msg">データを確認しています</div>
        </div>
        <div class="risk-area">
            <div class="risk-item">感染症<div id="risk_infection" class="status-circle">--</div></div>
            <div class="risk-item">カビ<div id="risk_mold" class="status-circle">--</div></div>
            <div class="risk-item">ダニ<div id="risk_mite" class="status-circle">--</div></div>
        </div>
    </div>

    <div class="charts-container">
        <div class="chart-card">
            <div class="chart-info"><div class="chart-title" style="color:red;">温度</div><div id="t_val" class="chart-value">--℃</div></div>
            <div class="chart-wrapper"><canvas id="tChart"></canvas></div>
        </div>
        <div class="chart-card">
            <div class="chart-info"><div class="chart-title" style="color:blue;">湿度</div><div id="h_val" class="chart-value">--%</div></div>
            <div class="chart-wrapper"><canvas id="hChart"></canvas></div>
        </div>
        <div class="chart-card">
            <div class="chart-info"><div class="chart-title" style="color:#555;">CO2</div><div id="c_val" class="chart-value">--ppm</div></div>
            <div class="chart-wrapper"><canvas id="cChart"></canvas></div>
        </div>
    </div>

    <div class="footer-btns">
        <a href="home.php" class="nav-btn">トップページへ戻る</a>
        <a href="attendance.php" class="nav-btn btn-blue">出席管理画面を開く</a>
    </div>
</div>

<script>
    let charts = {};
    let datePicker = null; // カレンダーのインスタンス

    // グラフ初期化
    function initChart(id, color) {
        const ctx = document.getElementById(id).getContext('2d');
        charts[id] = new Chart(ctx, {
            type: 'line',
            data: { datasets: [{ data: [], borderColor: color, pointRadius: 2, borderWidth: 2, fill: false, tension: 0.1 }] },
            options: {
                responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: {
                    y: { ticks: { font: { size: 11, weight: 'bold' } }, grid: { color: '#f0f0f0' } },
                    x: { type: 'time', time: { unit: 'hour', displayFormats: { hour: 'HH:mm' }, tooltipFormat: 'HH:mm' }, ticks: { font: { size: 10 } }, grid: { display: false }, min: undefined, max: undefined }
                }
            }
        });
    }

    // カレンダーとグラフの初期化・更新
    async function initCalendarAndCharts() {
        const roomId = document.getElementById('room_select').value;
        
        try {
            // 1. その部屋でデータが存在する日付一覧を取得
            const res = await fetch(`?action=get_dates&room_id=${roomId}`);
            const availableDates = await res.json();

            // 最新の日付（あれば）
            const latestDate = availableDates.length > 0 ? availableDates[0] : new Date().toISOString().split('T')[0];

            // 2. カレンダー(Flatpickr)を設定
            if (datePicker) datePicker.destroy(); // 既存があれば破棄

            datePicker = flatpickr("#date_select", {
                locale: "ja",             // 日本語化
                defaultDate: latestDate,  // 初期値は最新のデータ日
                enable: availableDates,   // リストにある日だけ有効化
                disableMobile: true,      // モバイルでもこのUIを強制
                onChange: function(selectedDates, dateStr, instance) {
                    updateAll(dateStr);   // 日付変更時にグラフ更新
                }
            });

            // 3. グラフも更新
            updateAll(latestDate);

        } catch (e) {
            console.error("Calendar Init Error:", e);
        }
    }

    // データ更新処理
    async function updateAll(dateVal) {
        const roomId = document.getElementById('room_select').value;
        
        // 時間軸設定
        const startTime = new Date(`${dateVal}T00:00:00`).getTime();
        const endTime   = new Date(`${dateVal}T23:59:59`).getTime();

        ['tChart', 'hChart', 'cChart'].forEach(id => {
            charts[id].options.scales.x.min = startTime;
            charts[id].options.scales.x.max = endTime;
            charts[id].update('none');
        });

        try {
            const res = await fetch(`?ajax=1&room_id=${roomId}&date=${dateVal}`);
            const data = await res.json();
            
            if (!data || data.length === 0 || data.error) {
                ['tChart','hChart','cChart'].forEach(id => updateChartData(id, []));
                resetDisplay();
                return;
            }

            const tData = data.map(r => ({ x: r.measured_at, y: r.temperature }));
            const hData = data.map(r => ({ x: r.measured_at, y: r.humidity }));
            const cData = data.map(r => ({ x: r.measured_at, y: r.co2 }));

            updateChartData('tChart', tData);
            updateChartData('hChart', hData);
            updateChartData('cChart', cData);

            const latest = data[data.length - 1];
            document.getElementById('t_val').innerText = `${parseFloat(latest.temperature).toFixed(1)}℃`;
            document.getElementById('h_val').innerText = `${Math.round(latest.humidity)}%`;
            document.getElementById('c_val').innerText = `${latest.co2}`;
            document.getElementById('last_update').innerText = latest.measured_at.split(' ')[1].substring(0,5);

            const isOn = parseInt(latest.illuminance) > 100;
            const badge = document.getElementById('light_badge');
            badge.innerText = isOn ? "点灯中" : "消灯";
            badge.style.background = isOn ? "red" : "#777";

            updateLogic(latest);

        } catch (e) { console.error(e); resetDisplay(); }
    }

    function updateChartData(id, dataset) {
        charts[id].data.datasets[0].data = dataset;
        charts[id].update();
    }

    function resetDisplay() {
        document.getElementById('t_val').innerText = "--℃";
        document.getElementById('h_val').innerText = "--%";
        document.getElementById('c_val').innerText = "--";
        document.getElementById('main_status').innerText = "データなし";
        document.getElementById('status_msg').innerText = "データがありません";
        document.getElementById('judgment_box').style.background = "#eee";
        document.getElementById('light_badge').style.background = "#777";
        document.getElementById('light_badge').innerText = "--";
        ['risk_infection', 'risk_mold', 'risk_mite'].forEach(id => {
            const el = document.getElementById(id);
            el.innerText = "--"; el.style.background = "white";
        });
    }

    function updateLogic(latest) {
        const hum = parseInt(latest.humidity);
        const co2 = parseInt(latest.co2);
        const infRisk = document.getElementById('risk_infection');
        const box = document.getElementById('judgment_box');
        const mainSt = document.getElementById('main_status');
        const msg = document.getElementById('status_msg');

        infRisk.innerText = "--"; infRisk.style.background = "white";

        if (hum <= 40) {
            infRisk.innerText = "高"; infRisk.style.background = "#ff4444";
            box.style.background = "#ffff33"; mainSt.innerText = "状態：乾燥"; msg.innerText = "湿度が低いです。加湿器を稼働させてください。";
        } else if (co2 > 1000) {
            infRisk.innerText = "中"; infRisk.style.background = "#ffff00";
            box.style.background = "#ffbbbb"; mainSt.innerText = "状態：要換気"; msg.innerText = "CO2濃度が基準を超えています。";
        } else {
            infRisk.innerText = "低"; infRisk.style.background = "#00ff00";
            box.style.background = "#ccffcc"; mainSt.innerText = "状態：良好"; msg.innerText = "快適な環境が保たれています。";
        }
    }

    // 起動時の処理
    window.onload = function() {
        initChart('tChart', 'red');
        initChart('hChart', 'blue');
        initChart('cChart', 'gray');
        
        // カレンダーとデータの初期化を一括で行う
        initCalendarAndCharts();

        // 5秒ごとに最新データ更新（日付は今の選択状態を維持）
        setInterval(() => {
            if (datePicker && datePicker.selectedDates.length > 0) {
                // カレンダーで選択中の日付形式を取得 (YYYY-MM-DD)
                const currentStr = datePicker.formatDate(datePicker.selectedDates[0], "Y-m-d");
                updateAll(currentStr);
            }
        }, 5000);
    };
</script>
</body>
</html>