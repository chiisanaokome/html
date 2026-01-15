<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>環境管理画面 - 教室詳細</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: "Meiryo", sans-serif; background: #fdfdfd; padding: 20px; }
        .detail-container { max-width: 950px; margin: auto; background: white; border: 2px solid #555; padding: 25px; border-radius: 4px; }
        .detail-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .charts-container { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 30px; }
        .chart-card { border: 1px solid #aaa; padding: 10px; text-align: center; }
        .judgment-box { background: #ffff33; border: 3px solid #000; padding: 20px; border-radius: 15px; text-align: center; font-weight: bold; }
        .risk-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; width: 300px; font-weight: bold; }
        .status-circle { width: 45px; height: 45px; border-radius: 50%; border: 2px solid #333; display: flex; align-items: center; justify-content: center; }
        .nav-btn { display: block; background: #dcdcdc; border: 1px solid #777; padding: 10px; text-decoration: none; color: black; font-weight: bold; text-align: center; margin-top: 10px; box-shadow: 2px 2px 0 #999; }
        .light-status { padding: 2px 12px; border-radius: 15px; font-size: 0.8em; margin-left: 10px; color: white; }
    </style>
</head>
<body>

<div class="detail-container">
    <div class="detail-header">
        <div style="font-weight: bold; font-size: 1.2em;">
            環境画面 教室 
            <select id="room-selector" style="font-size: 0.9em;">
                <option value="1">0-502</option>
                <option value="2">0-504</option>
                <option value="3">0-506</option>
            </select>
            <span id="light-badge" class="light-status" style="background:gray;">判定中</span>
        </div>
        <div style="font-weight: bold;">
            <span id="date-now">----/--/--</span> &nbsp; 最終更新 <span id="update-time">--:--:--</span>
        </div>
    </div>

    <div class="charts-container">
        <div class="chart-card"><div style="color:red; font-weight:bold;">温度</div><canvas id="tChart"></canvas><div id="info-t">--℃</div></div>
        <div class="chart-card"><div style="color:blue; font-weight:bold;">湿度</div><canvas id="hChart"></canvas><div id="info-h">--%</div></div>
        <div class="chart-card"><div style="font-weight:bold;">CO2</div><canvas id="cChart"></canvas><div id="info-c">--ppm</div></div>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
        <div>
            <div class="risk-row">感染症リスク ： <div id="risk-1" class="status-circle">--</div></div>
            <div class="risk-row">カビの繁殖リスク： <div id="risk-2" class="status-circle">--</div></div>
            <div class="risk-row">ダニの繁殖リスク： <div id="risk-3" class="status-circle">--</div></div>
        </div>
        <div style="width: 40%;">
            <div class="judgment-box">
                <div id="judge-main" style="font-size:1.5em; margin-bottom: 10px;">状態：判定中</div>
                <div id="judge-sub">データを取得しています...</div>
            </div>
            <a href="index.php" class="nav-btn">トップページへ</a>
            <a href="#" class="nav-btn">出席管理画面へ</a>
        </div>
    </div>
</div>

<script>
    let charts = {};

    // グラフを初期化する関数
    function initCharts() {
        // すでにグラフがある場合は破棄して作り直す
        if (charts.t) charts.t.destroy();
        if (charts.h) charts.h.destroy();
        if (charts.c) charts.c.destroy();

        const config = (color) => ({
            type: 'line',
            data: { labels: [], datasets: [{ data: [], borderColor: color, fill: false, tension: 0.1 }] },
            options: { 
                animation: false,
                plugins: { legend: { display: false } }, 
                scales: { x: { display: true }, y: { beginAtZero: false } } 
            }
        });

        charts.t = new Chart(document.getElementById('tChart'), config('red'));
        charts.h = new Chart(document.getElementById('hChart'), config('blue'));
        charts.c = new Chart(document.getElementById('cChart'), config('gray'));
    }

    async function updateDetail() {
        const roomId = document.getElementById('room-selector').value;
        
        try {
            // ajax=1で、その教室の最新10件程度のデータを取得（sensor.php側が対応している前提）
            const res = await fetch(`sensor.php?ajax=1&room_id=${roomId}&limit=10`);
            const data = await res.json();

            if (data && data.length > 0) {
                // データを時間昇順（古い順）に並び替えてグラフに表示
                const displayData = data.reverse(); 
                const latest = displayData[displayData.length - 1];

                // ヘッダーとステータスの更新
                const dt = latest.measured_at.split(' ');
                document.getElementById('date-now').innerText = dt[0];
                document.getElementById('update-time').innerText = dt[1];
                
                const isLit = parseInt(latest.illuminance) > 100;
                const badge = document.getElementById('light-badge');
                badge.innerText = isLit ? '点灯' : '消灯';
                badge.style.background = isLit ? 'red' : 'green';

                // 数値表示の更新
                document.getElementById('info-t').innerText = `状態：良好 ${latest.temperature}℃`;
                document.getElementById('info-h').innerText = `状態：普通 ${latest.humidity}%`;
                document.getElementById('info-c').innerText = `状態：普通 ${latest.co2}ppm`;

                // グラフデータの更新
                const labels = displayData.map(d => d.measured_at.split(' ')[1].substring(0, 5));
                
                charts.t.data.labels = labels;
                charts.t.data.datasets[0].data = displayData.map(d => d.temperature);
                
                charts.h.data.labels = labels;
                charts.h.data.datasets[0].data = displayData.map(d => d.humidity);
                
                charts.c.data.labels = labels;
                charts.c.data.datasets[0].data = displayData.map(d => d.co2);

                charts.t.update();
                charts.h.update();
                charts.c.update();

                // 判定ロジック
                if(parseInt(latest.co2) > 1000) {
                    document.getElementById('judge-main').innerText = "状態：注意";
                    document.getElementById('judge-sub').innerText = "換気してください";
                    document.getElementById('risk-1').style.background = "#ffff00";
                    document.getElementById('risk-1').innerText = "中";
                } else {
                    document.getElementById('judge-main').innerText = "状態：良好";
                    document.getElementById('judge-sub').innerText = "快適な環境です";
                    document.getElementById('risk-1').style.background = "#00ff00";
                    document.getElementById('risk-1').innerText = "低";
                }
            }
        } catch (e) { console.error("データ取得エラー:", e); }
    }

    // 教室が切り替わったらグラフをリセットして即座に更新
    document.getElementById('room-selector').addEventListener('change', () => {
        initCharts();
        updateDetail();
    });

    // 初期化実行
    initCharts();
    updateDetail();
    setInterval(updateDetail, 5000); // 5秒ごとに最新化
</script>
</body>
</html>