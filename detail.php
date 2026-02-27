<?php
// SERVER SIDE (PHP) - 既存のロジックを維持
$host = '10.100.56.163';
$db   = 'group3';       
$user = 'gthree';       
$pass = 'Gthree';       
$port = '5432';
$dsn  = "pgsql:host=$host;port=$port;dbname=$db";

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $roomId = $_GET['room_id'] ?? 1;
        $date   = $_GET['date'] ?? date('Y-m-d');
        $sql = "SELECT measured_at, temperature, humidity, co2, illuminance FROM sensor_logs WHERE room_id = :room_id AND measured_at >= :start_date AND measured_at < :end_date ORDER BY measured_at ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['room_id' => $roomId, 'start_date' => $date . ' 00:00:00', 'end_date' => date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00']);
        echo json_encode($stmt->fetchAll());
    } catch (PDOException $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_dates') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $roomId = $_GET['room_id'] ?? 1;
        $sql = "SELECT DISTINCT DATE(measured_at) as available_date FROM sensor_logs WHERE room_id = :room_id ORDER BY available_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['room_id' => $roomId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) { echo json_encode([]); }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>環境管理詳細 - 学校スマート管理システム</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/ja.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --bg-blue: #eef7ff;
            --main-text: #333;
            --card-bg: #ffffff;
            --btn-blue: #007bff;
            --btn-gray: #546e7a;
            --eva-red: #ff0000;
            --status-green: #d4edda;
            --status-yellow: #fff3cd;
            --status-red: #f8d7da;
        }

        body { 
            font-family: "Hiragino Kaku Gothic ProN", "Meiryo", sans-serif; 
            background: var(--bg-blue); color: var(--main-text);
            margin: 0; padding: 20px;
            display: flex; flex-direction: column; align-items: center;
            transition: all 0.5s ease;
            overflow-x: hidden;
        }

        /* --- エマージェンシー演出：背景 --- */
        body.emergency-mode {
            background-color: #000 !important;
            background-image: 
                radial-gradient(circle, rgba(74, 0, 0, 0.6) 0%, #000 80%),
                url('https://www.transparenttextures.com/patterns/hexellence.png') !important;
            color: var(--eva-red) !important;
        }

        .eva-banner {
            display: none; position: fixed; width: 200%; height: 35px;
            background: var(--eva-red); color: black; font-weight: 900;
            line-height: 35px; white-space: nowrap; font-size: 1.2em;
            z-index: 9999; transform: rotate(-1.5deg);
            box-shadow: 0 0 15px rgba(255, 0, 0, 0.7); pointer-events: none;
            overflow: hidden;
        }
        @keyframes scrollText { 0% { transform: translateX(0); } 100% { transform: translateX(-50%); } }
        .eva-banner div { display: inline-block; animation: scrollText 8s linear infinite; }

        body.emergency-mode .eva-banner { display: block; }
        body.emergency-mode .card {
            background: rgba(20, 0, 0, 0.85) !important;
            border: 2px solid var(--eva-red) !important;
            box-shadow: 0 0 30px var(--eva-red) !important;
        }

        /* --- レイアウト --- */
        .container { width: 100%; max-width: 1200px; z-index: 10; position: relative; }
        .page-title { font-size: 0.9em; color: #666; text-align: center; }
        .current-time { font-size: 2.2em; font-weight: bold; text-align: center; margin-bottom: 25px; }
        .card { background: var(--card-bg); border-radius: 15px; padding: 25px; margin-bottom: 25px; transition: all 0.5s; }
        .header-controls { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0f0f0; padding-bottom: 20px; }
        .top-status-grid { display: grid; grid-template-columns: 1.2fr 2fr; gap: 20px; margin-top: 20px; }
        .judgment-box { border-radius: 12px; padding: 20px; text-align: center; min-height: 140px; display: flex; flex-direction: column; justify-content: center; }
        .judgment-box.ok { background: var(--status-green); color: #155724; }
        .judgment-box.warn { background: var(--status-yellow); color: #856404; }
        .judgment-box.alert { background: var(--status-red); color: #721c24; }
        
        .risk-area { display: grid; grid-template-columns: repeat(4, 1fr); background: #fdfdfd; border: 1px solid #f2f2f2; border-radius: 12px; padding: 15px; }
        .risk-item { text-align: center; font-size: 0.85em; font-weight: bold; }
        #mite_label { cursor: pointer; user-select: none; }
        .circle { width: 55px; height: 55px; border-radius: 50%; background: white; border: 2px solid #eee; margin: 12px auto; display: flex; align-items: center; justify-content: center; font-size: 1.1em; transition: background 0.3s; }
        
        .charts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 15px; margin-top: 25px; }
        .chart-card { background: #fff; border: 1px solid #f5f5f5; border-radius: 12px; padding: 15px; }
        .footer-btns { display: flex; gap: 20px; justify-content: center; margin-top: 5px; }
        .btn { width: 260px; padding: 15px; text-align: center; text-decoration: none; border-radius: 10px; font-weight: bold; color: white; display: flex; align-items: center; justify-content: center; gap: 12px; }
        .btn-gray { background: var(--btn-gray); }
        .btn-blue { background: var(--btn-blue); }
    </style>
</head>
<body>

<audio id="evaBgm" src="evaBgm.mp3" loop></audio>

<div class="eva-banner" style="top: 0; left: -10%;"><div>EMERGENCY EMERGENCY EMERGENCY EMERGENCY EMERGENCY EMERGENCY EMERGENCY EMERGENCY&nbsp;</div></div>
<div class="eva-banner" style="bottom: 0; left: -10%;"><div>EMERGENCY EMERGENCY EMERGENCY EMERGENCY EMERGENCY EMERGENCY EMERGENCY EMERGENCY&nbsp;</div></div>

<div class="container">
    <div class="page-title">見えない環境を可視化する 学校スマート管理システム（グループ3）</div>
    <div class="current-time" id="clock">--/--/-- (--) --:--</div>

    <div class="card">
        <div class="header-controls">
            <div><i class="fas fa-door-open"></i> <select id="room_select" onchange="initCalendarAndCharts()"><option value="1">教室 0-502</option><option value="2">教室 0-504</option><option value="3">教室 0-506</option></select></div>
            <div><i class="fas fa-calendar-alt"></i> <input type="text" id="date_select"></div>
            <div style="font-size: 0.85em; color: #999;">最終更新: <span id="last_update">--:--</span></div>
        </div>

        <div class="top-status-grid">
            <div id="judgment_box" class="judgment-box ok">
                <div id="main_status" style="font-size: 1.7em; font-weight: bold;">読込中...</div>
                <div id="status_msg" style="margin-top: 8px; font-size: 0.95em;"></div>
            </div>
            <div class="risk-area">
                <div class="risk-item">感染リスク<div id="risk_infection" class="circle">--</div></div>
                <div class="risk-item">カビ発生<div id="risk_mold" class="circle">--</div></div>
                <div class="risk-item" id="mite_label" onclick="handleSecretClick()">ダニ発生<div id="risk_mite" class="circle">--</div></div>
                <div class="risk-item">照明状態<div id="light_badge" class="circle" style="font-size: 0.85em;">--</div></div>
            </div>
        </div>

        <div class="charts-grid">
            <div class="chart-card"><div>温度 <span id="t_val" style="font-weight:bold;">--</span></div><div style="height: 150px;"><canvas id="tChart"></canvas></div></div>
            <div class="chart-card"><div>湿度 <span id="h_val" style="font-weight:bold;">--</span></div><div style="height: 150px;"><canvas id="hChart"></canvas></div></div>
            <div class="chart-card"><div>CO2 <span id="c_val" style="font-weight:bold;">--</span></div><div style="height: 150px;"><canvas id="cChart"></canvas></div></div>
        </div>
    </div>

    <div class="footer-btns">
        <a href="home.php" class="btn btn-gray"><i class="fas fa-home"></i> トップページへ</a>
        <a href="attendance.php" class="btn btn-blue"><i class="fas fa-user-check"></i> 出席管理画面へ</a>
    </div>
</div>

<script>
    let charts = {};
    let datePicker = null;
    let clickCount = 0;
    let isEmergency = false;
    let lastData = null;

    // BGMコントロール
    function toggleBgm(play) {
        const audio = document.getElementById('evaBgm');
        if (play) {
            audio.currentTime = 0;
            audio.play().catch(e => console.log("再生制限回避のためクリックが必要です"));
        } else {
            audio.pause();
        }
    }

    function handleSecretClick() {
        clickCount++;
        if (clickCount >= 5) {
            isEmergency = !isEmergency;
            clickCount = 0;
            document.body.classList.toggle('emergency-mode', isEmergency);
            
            toggleBgm(isEmergency); // BGM再生・停止

            if (lastData) updateLogic(lastData);
            Object.values(charts).forEach(c => {
                c.options.scales.y.ticks.color = isEmergency ? '#ff0000' : '#666';
                c.update();
            });
        }
    }

    function initChart(id, color) {
        const ctx = document.getElementById(id).getContext('2d');
        charts[id] = new Chart(ctx, {
            type: 'line',
            data: { datasets: [{ data: [], borderColor: color, pointRadius: 0, borderWidth: 2, fill: true, backgroundColor: color + '15', tension: 0.3 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { 
                    y: { ticks: { font: { size: 10 } } },
                    x: { type: 'time', time: { unit: 'hour', displayFormats: { hour: 'HH:mm' } }, ticks: { font: { size: 9 } } }
                }
            }
        });
    }

    async function initCalendarAndCharts() {
        const roomId = document.getElementById('room_select').value;
        const res = await fetch(`?action=get_dates&room_id=${roomId}`);
        const availableDates = await res.json();
        const latestDate = availableDates[0] || new Date().toISOString().split('T')[0];
        if (datePicker) datePicker.destroy();
        datePicker = flatpickr("#date_select", { locale: "ja", defaultDate: latestDate, enable: availableDates, onChange: (sd, dateStr) => updateAll(dateStr) });
        updateAll(latestDate);
    }

    async function updateAll(dateVal) {
        const roomId = document.getElementById('room_select').value;
        const res = await fetch(`?ajax=1&room_id=${roomId}&date=${dateVal}`);
        const data = await res.json();
        if (!data.length) return;
        lastData = data[data.length - 1];
        updateChartData('tChart', data.map(r => ({ x: r.measured_at, y: r.temperature })));
        updateChartData('hChart', data.map(r => ({ x: r.measured_at, y: r.humidity })));
        updateChartData('cChart', data.map(r => ({ x: r.measured_at, y: r.co2 })));
        updateLogic(lastData);
    }

    function updateChartData(id, dataset) {
        charts[id].data.datasets[0].data = dataset;
        charts[id].update();
    }

    function setRiskUI(elementId, level) {
        const el = document.getElementById(elementId);
        el.innerText = level;
        if (isEmergency) {
            el.style.background = "#000";
            el.style.color = "#ff0000";
            return;
        }
        
        if (level === "高") {
            el.style.background = "var(--status-red)";
            el.style.color = "#721c24";
        } else if (level === "中") {
            el.style.background = "var(--status-yellow)";
            el.style.color = "#856404";
        } else {
            el.style.background = "var(--status-green)";
            el.style.color = "#155724";
        }
    }

    function updateLogic(latest) {
        const hum = parseInt(latest.humidity);
        const co2 = parseInt(latest.co2);
        const box = document.getElementById('judgment_box');
        const st = document.getElementById('main_status');
        const msg = document.getElementById('status_msg');

        // 数値表示更新
        document.getElementById('t_val').innerText = `${parseFloat(latest.temperature).toFixed(1)}℃`;
        document.getElementById('h_val').innerText = `${Math.round(latest.humidity)}%`;
        document.getElementById('c_val').innerText = `${latest.co2}ppm`;
        document.getElementById('last_update').innerText = latest.measured_at.split(' ')[1].substring(0,5);

        // 照明
        const isOn = parseInt(latest.illuminance) > 500;
        const lb = document.getElementById('light_badge');
        lb.innerText = isOn ? "点灯" : "消灯";
        lb.style.background = isOn ? "var(--status-yellow)" : "#eee";

        // --- リスク判定ロジック（通常時のラベルを先に決定） ---
        let infLevel = (co2 >= 1000) ? "高" : (co2 >= 700 ? "中" : "低");
        let moldLevel = (hum >= 70) ? "高" : (hum >= 60 ? "中" : "低");
        let miteLevel = (hum >= 70) ? "高" : (hum >= 60 ? "中" : "低");

        if (isEmergency) {
            // エヴァモードの表示設定
            box.className = "judgment-box alert";
            st.innerText = "第1種警戒態勢";
            msg.innerText = "パターン青！使徒を確認。ただちに換気システムを最大出力で稼働せよ。";

            // 通常時のリスク（高・中・低）をエヴァ風漢字に変換してセット
            const evaDict = {
                "infection": { "高": "極", "中": "戒", "低": "微" },
                "mold":      { "高": "蔓", "中": "殖", "低": "無" },
                "mite":      { "高": "襲", "中": "潜", "低": "未" }
            };

            setRiskUI('risk_infection', evaDict.infection[infLevel]);
            setRiskUI('risk_mold',      evaDict.mold[moldLevel]);
            setRiskUI('risk_mite',      evaDict.mite[miteLevel]);
        } else {
            // 通常モードの表示設定
	    // 通常モードの表示設定（複合警告対応）
		// 通常モードの表示設定（複合警告：乾燥＋カビ対応）
		const isCo2High = co2 > 1000;
		const isDry = hum < 40;
		const isHumHigh = hum >= 70;   // カビ注意ライン

		if (isCo2High && isDry) {
		    box.className = "judgment-box alert";
		    st.innerText = "複合警告";
		    msg.innerText = "CO2濃度が高く、湿度も低下しています。換気と加湿を同時に行ってください。";

		} else if (isCo2High && isHumHigh) {
		    box.className = "judgment-box alert";
		    st.innerText = "複合警告";
		    msg.innerText = "CO2濃度が高く、湿度も高めです。換気と除湿を行い、カビ発生を防いでください。";

		} else if (isCo2High) {
		    box.className = "judgment-box alert";
		    st.innerText = "要換気";
		    msg.innerText = "CO2濃度が高めです。換気が必要です。";

		} else if (isDry) {
		    box.className = "judgment-box warn";
		    st.innerText = "乾燥注意";
		    msg.innerText = "湿度が低めです。加湿を検討してください。";

		} else if (isHumHigh) {
		    box.className = "judgment-box warn";
		    st.innerText = "カビ注意";
		    msg.innerText = "湿度が高めです。除湿や換気を行ってください。";

		} else {
		    box.className = "judgment-box ok";
		    st.innerText = "良好";
		    msg.innerText = "良好な室内環境です。";
		}


            setRiskUI('risk_infection', infLevel);
            setRiskUI('risk_mold', moldLevel);
            setRiskUI('risk_mite', miteLevel);
        }
    }

    window.onload = () => {
        initChart('tChart', '#e74c3c');
        initChart('hChart', '#3498db');
        initChart('cChart', '#546e7a');
        initCalendarAndCharts();
        setInterval(() => {
            const now = new Date();
            const d = ['日','月','火','水','木','金','土'];
            document.getElementById('clock').innerText = `${now.getFullYear()}/${now.getMonth()+1}/${now.getDate()}(${d[now.getDay()]}) ${now.getHours()}:${now.getMinutes().toString().padStart(2,'0')}`;
        }, 1000);
    };
</script>
</body>
</html>