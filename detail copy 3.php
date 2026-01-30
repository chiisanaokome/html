<?php
// =========================================================
//  SERVER SIDE (PHP) - DB接続とデータ取得処理
// =========================================================

// URLに "?ajax=1" がついていたら、HTMLではなくJSONデータを返して終了する
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    // --- DB接続設定 (group3 / sensor_logs) ---
    $host = '10.100.56.163';
    $db   = 'group3';       
    $user = 'gthree';       
    $pass = 'Gthree';       
    $port = '5432';
    // ----------------------------------------

    $dsn = "pgsql:host=$host;port=$port;dbname=$db";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        $roomId = $_GET['room_id'] ?? 1;
        $date   = $_GET['date'] ?? date('Y-m-d');

        // SQLクエリ: テーブル名 sensor_logs
        $sql = "SELECT measured_at, temperature, humidity, co2, illuminance
                FROM sensor_logs
                WHERE room_id = :room_id
                AND measured_at >= :start_date 
                AND measured_at <  :end_date
                ORDER BY measured_at ASC";

        $stmt = $pdo->prepare($sql);
        
        // 日付範囲の設定 (その日の00:00:00 〜 翌日の00:00:00)
        $stmt->execute([
            'room_id' => $roomId,
            'start_date' => $date . ' 00:00:00',
            'end_date'   => date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00'
        ]);

        $data = $stmt->fetchAll();
        echo json_encode($data);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Connection Failed: ' . $e->getMessage()]);
    }

    exit; // PHP終了
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>環境管理画面 - 教室詳細（24時間軸・レスポンシブ版）</title>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    
    <style>
        /* 画面全体を有効活用する設定 */
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; }
        body { font-family: "Helvetica Neue", Arial, sans-serif; background: #eee; display: flex; flex-direction: column; align-items: center; justify-content: flex-start; padding: 10px; box-sizing: border-box; }
        
        .detail-container { 
            width: 98%; 
            max-width: 1400px; 
            height: 95vh; 
            background: white; 
            border: 1px solid #333; 
            padding: 15px; 
            display: flex; 
            flex-direction: column; 
            box-sizing: border-box;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        /* ヘッダー */
        .detail-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #333; padding-bottom: 5px; margin-bottom: 10px; flex-shrink: 0; }
        .header-left { font-weight: bold; font-size: 1.2em; display: flex; align-items: center; gap: 10px; }
        
        .header-right { font-size: 1em; color: #555; font-weight: bold; display: flex; align-items: center; }
        
        #date_select {
            font-size: 1em;
            padding: 4px;
            margin-right: 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
            cursor: pointer;
        }

        .badge { background: red; color: white; padding: 3px 15px; border-radius: 20px; font-size: 0.8em; }

        /* 判定・リスクエリア */
        .top-info-row { display: flex; gap: 20px; margin-bottom: 15px; flex-shrink: 0; }
        .judgment-box { flex: 2; background: #ffff33; border: 3px solid #000; padding: 15px; border-radius: 12px; text-align: center; font-weight: bold; }
        .main-status { font-size: 1.8em; }
        .status-msg { font-size: 1.1em; margin-top: 5px; }

        .risk-area { flex: 1.5; display: flex; justify-content: space-around; align-items: center; border: 1px solid #ccc; border-radius: 12px; padding: 5px; }
        .risk-item { text-align: center; font-weight: bold; font-size: 1em; }
        .status-circle { width: 50px; height: 50px; border-radius: 50%; border: 2px solid #333; margin: 5px auto; display: flex; align-items: center; justify-content: center; font-size: 1.1em; background: #fff; }

        /* グラフエリア */
        .charts-container { 
            flex-grow: 1; 
            display: flex; 
            flex-direction: column; 
            gap: 10px; 
            min-height: 0; 
        } 
        
        .chart-card { 
            border: 1px solid #ccc; 
            padding: 10px; 
            display: flex; 
            align-items: center; 
            flex: 1; 
            min-height: 0; 
            background: #fff; 
        }
        
        .chart-info { width: 120px; text-align: center; border-right: 2px solid #eee; padding-right: 10px; flex-shrink: 0; }
        .chart-title { font-weight: bold; font-size: 1.2em; }
        .chart-value { font-size: 1.3em; font-weight: bold; margin-top: 5px; }
        
        /* グラフのラッパー */
        .chart-wrapper { 
            flex: 1; 
            height: 100%; 
            width: 100%;       
            position: relative; 
            overflow: hidden;  
        }

        /* フッターボタン */
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
            <select id="room_select" onchange="updateAll()" style="font-size: 1em; padding: 5px;">
                <option value="1" selected>0-502</option>
                <option value="2">0-504</option>
                <option value="3">0-506</option>
            </select>
            <span id="light_badge" class="badge">--</span>
        </div>
        <div class="header-right">
            <input type="date" id="date_select" onchange="updateAll()">
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
        <a href="#" class="nav-btn btn-blue">出席管理画面を開く</a>
    </div>
</div>

<script>
    let charts = {};

    function initChart(id, color) {
        const ctx = document.getElementById(id).getContext('2d');
        charts[id] = new Chart(ctx, {
            type: 'line',
            data: { 
                // labelsは削除（時間軸モードでは不要）
                datasets: [{ 
                    data: [], 
                    borderColor: color, 
                    pointRadius: 2, 
                    borderWidth: 2, 
                    fill: false, 
                    tension: 0.1 
                }] 
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { 
                        ticks: { font: { size: 11, weight: 'bold' } }, 
                        grid: { color: '#f0f0f0' } 
                    },
                    x: { 
                        // ★ここが24時間軸のポイント
                        type: 'time', 
                        time: {
                            unit: 'hour',
                            displayFormats: { hour: 'HH:mm' },
                            tooltipFormat: 'HH:mm'
                        },
                        ticks: { font: { size: 10 } },
                        grid: { display: false },
                        min: undefined, // 後でセット
                        max: undefined  // 後でセット
                    }
                }
            }
        });
    }

    async function updateAll() {
        const roomId = document.getElementById('room_select').value;
        const dateVal = document.getElementById('date_select').value;

        // ★選択した日の 00:00:00 〜 23:59:59 を計算してグラフの枠を固定する
        const startTime = new Date(`${dateVal}T00:00:00`).getTime();
        const endTime   = new Date(`${dateVal}T23:59:59`).getTime();

        ['tChart', 'hChart', 'cChart'].forEach(id => {
            charts[id].options.scales.x.min = startTime;
            charts[id].options.scales.x.max = endTime;
            charts[id].update('none'); // 枠だけ先に更新
        });

        try {
            // 自分自身にリクエスト (?ajax=1)
            const res = await fetch(`?ajax=1&room_id=${roomId}&date=${dateVal}`);
            const data = await res.json();
            
            if (!data || data.length === 0 || data.error) {
                console.log("No data");
                ['tChart','hChart','cChart'].forEach(id => updateChartData(id, []));
                resetDisplay();
                return;
            }

            // ★データを { x: 時間, y: 値 } の形に変換する
            const tData = data.map(r => ({ x: r.measured_at, y: r.temperature }));
            const hData = data.map(r => ({ x: r.measured_at, y: r.humidity }));
            const cData = data.map(r => ({ x: r.measured_at, y: r.co2 }));

            updateChartData('tChart', tData);
            updateChartData('hChart', hData);
            updateChartData('cChart', cData);

            // 最新データ（SQLでASC順なので、配列の最後が最新）
            const latest = data[data.length - 1]; 
            
            document.getElementById('t_val').innerText = `${parseFloat(latest.temperature).toFixed(1)}℃`;
            document.getElementById('h_val').innerText = `${Math.round(latest.humidity)}%`;
            document.getElementById('c_val').innerText = `${latest.co2}`;
            
            // 秒を削って表示
            document.getElementById('last_update').innerText = latest.measured_at.split(' ')[1].substring(0,5);

            const isOn = parseInt(latest.illuminance) > 100;
            const badge = document.getElementById('light_badge');
            badge.innerText = isOn ? "点灯中" : "消灯";
            badge.style.background = isOn ? "red" : "#777";

            updateLogic(latest);

        } catch (e) { 
            console.error(e); 
            resetDisplay();
        }
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

    window.onload = function() {
        document.getElementById('date_select').valueAsDate = new Date();
        initChart('tChart', 'red');
        initChart('hChart', 'blue');
        initChart('cChart', 'gray');
        
        updateAll();
        setInterval(updateAll, 5000);
    };
</script>
</body>
</html>