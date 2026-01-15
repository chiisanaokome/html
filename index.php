<?php
// PHP部分は、sensor.php のDB接続設定などを再利用できるようにします
// このファイル自体はHTML構造がメインです
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>学校スマート管理システム</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-blue: #e3f2fd;
            --card-red: #ffebee;
            --card-green: #e8f5e9;
            --header-blue: #bbdefb;
        }
        body { font-family: "Helvetica Neue", Arial, "Hiragino Kaku Gothic ProN", "Hiragino Sans", Meiryo, sans-serif; background-color: #f8f9fa; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1000px; margin: auto; background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); overflow: hidden; }
        
        /* ヘッダー */
        .header { background-color: var(--bg-blue); padding: 20px; text-align: center; position: relative; }
        .top-btn { position: absolute; left: 20px; top: 20px; background: white; border: 1px solid #ddd; padding: 8px 15px; border-radius: 20px; text-decoration: none; color: #333; font-size: 0.9em; }
        .sub-title { font-size: 0.8em; color: #666; margin-bottom: 5px; }
        .main-date { font-size: 1.8em; font-weight: bold; margin: 10px 0; }

        /* サマリーエリア */
        .summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); border-top: 1px solid #eee; border-bottom: 1px solid #eee; }
        .summary-item { padding: 15px; text-align: center; border-right: 1px solid #eee; }
        .summary-label { font-size: 0.8em; color: #888; margin-bottom: 5px; }
        .summary-value { font-size: 1.4em; font-weight: bold; }

        /* 警告エリア */
        .alert-section { background-color: #fff9e6; margin: 20px; padding: 15px; border-radius: 8px; border-left: 5px solid #ffcc00; }
        .alert-title { color: #d4a017; font-weight: bold; margin-bottom: 10px; display: flex; align-items: center; }
        .alert-list { margin: 0; padding-left: 20px; font-size: 0.9em; }

        /* カードエリア */
        .room-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; padding: 20px; }
        .room-card { border-radius: 8px; padding: 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .room-status { text-align: center; font-size: 0.9em; margin-bottom: 10px; font-weight: bold; }
        .room-name { border-radius: 4px; padding: 2px 8px; }
        
        .card-red { background-color: var(--card-red); }
        .card-green { background-color: var(--card-green); }

        .sensor-data { display: grid; grid-template-columns: 30px 1fr; row-gap: 8px; align-items: center; font-size: 0.95em; }
        .sensor-data i { color: #666; text-align: center; }

        /* ボタン */
        .footer-btns { display: flex; justify-content: center; gap: 20px; padding-bottom: 30px; }
        .btn { padding: 12px 25px; border-radius: 6px; border: none; color: white; cursor: pointer; font-weight: bold; text-decoration: none; }
        .btn-gray { background-color: #777; }
        .btn-blue { background-color: #2196f3; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <a href="#" class="top-btn"><i class="fas fa-home"></i> トップページ</a>
        <div class="sub-title">見えない環境を可視化する 〜学校スマート管理システム〜（グループ3）</div>
        <div class="main-date" id="current-clock">0000/00/00(月) 00:00</div>
    </div>

    <div class="summary-grid">
        <div class="summary-item">
            <div class="summary-label">使用中教室</div>
            <div class="summary-value" id="used-rooms-count">0 / 3</div>
        </div>
        <div class="summary-item">
            <div class="summary-label">警告発生</div>
            <div class="summary-value" id="alert-count" style="color:red;">0 件</div>
        </div>
        <div class="summary-item" style="border-right:none;">
            <div class="summary-label">平均CO2</div>
            <div class="summary-value" id="avg-co2">--- ppm</div>
        </div>
    </div>

    <div class="alert-section" id="alert-box">
        <div class="alert-title"><i class="fas fa-exclamation-triangle"></i> &nbsp;現在の警告</div>
        <ul class="alert-list" id="alert-list">
            <li>現在、警告はありません。</li>
        </ul>
    </div>

    <div class="room-grid" id="room-container">
        <div class="room-card card-red" id="room-card-1">
            <div class="room-status">□ 使用中 | <span class="room-name" style="background:rgba(255,255,255,0.5)">教室 0-502</span></div>
            <div class="sensor-data">
                <i class="fas fa-users"></i> <span id="r1-people">在室：-- 名</span>
                <i class="fas fa-thermometer-half"></i> <span id="r1-temp">-- ℃ / -- %</span>
                <i class="fas fa-wind"></i> <span id="r1-co2">CO2：-- ppm</span>
                <i class="fas fa-lightbulb"></i> <span id="r1-light">照明：--</span>
            </div>
        </div>

        <div class="room-card card-green" id="room-card-2">
            <div class="room-status">□ 未使用 | <span class="room-name" style="background:rgba(255,255,255,0.5)">教室 0-504</span></div>
            <div class="sensor-data">
                <i class="fas fa-users"></i> <span id="r2-people">在室：0 名</span>
                <i class="fas fa-thermometer-half"></i> <span id="r2-temp">-- ℃ / -- %</span>
                <i class="fas fa-wind"></i> <span id="r2-co2">CO2：-- ppm</span>
                <i class="fas fa-lightbulb"></i> <span id="r2-light">照明：--</span>
            </div>
        </div>

        <div class="room-card card-red" id="room-card-3">
            <div class="room-status">□ 使用中 | <span class="room-name" style="background:rgba(255,255,255,0.5)">教室 0-506</span></div>
            <div class="sensor-data">
                <i class="fas fa-users"></i> <span id="r3-people">在室：-- 名</span>
                <i class="fas fa-thermometer-half"></i> <span id="r3-temp">-- ℃ / -- %</span>
                <i class="fas fa-wind"></i> <span id="r3-co2">CO2：-- ppm</span>
                <i class="fas fa-lightbulb"></i> <span id="r3-light">照明：--</span>
            </div>
        </div>
    </div>

    <div class="footer-btns">
        <a href="sensor.php" class="btn btn-gray">環境管理画面へ</a>
        <a href="#" class="btn btn-blue">出席管理画面へ</a>
    </div>
</div>

<script>
function updateTime() {
    const now = new Date();
    const days = ['日','月','火','水','木','金','土'];
    const timeStr = `${now.getFullYear()}/${(now.getMonth()+1).toString().padStart(2,'0')}/${now.getDate().toString().padStart(2,'0')}(${days[now.getDay()]}) ${now.getHours().toString().padStart(2,'0')}:${now.getMinutes().toString().padStart(2,'0')}`;
    document.getElementById('current-clock').innerText = timeStr;
}

async function fetchLatestData() {
    // 教室ID 1, 2, 3 のデータをそれぞれ取得
    const roomIds = [1, 2, 3];
    let alerts = [];
    let totalCo2 = 0;
    let usedRooms = 0;

    for (let id of roomIds) {
        try {
            const response = await fetch(`sensor.php?ajax=1&room_id=${id}`);
            const data = await response.json();
            
            if (data && data.length > 0) {
                const latest = data[0];
                const co2 = parseInt(latest.co2);
                const temp = latest.temperature;
                const hum = latest.humidity;
                const lux = parseInt(latest.illuminance);
                const name = latest.room_name;

                // 表示更新
                document.getElementById(`r${id}-temp`).innerText = `${temp}℃ / ${hum}%`;
                document.getElementById(`r${id}-co2`).innerText = `CO2：${co2} ppm`;
                document.getElementById(`r${id}-light`).innerText = `照明：${lux > 100 ? '点灯中' : '消灯'}`;

                // 在室判定・集計（照度が一定以上なら使用中とする例）
                if (lux > 100) {
                    usedRooms++;
                    document.getElementById(`room-card-${id}`).className = 'room-card card-red';
                } else {
                    document.getElementById(`room-card-${id}`).className = 'room-card card-green';
                }

                // 警告ロジック
                if (co2 > 1000) {
                    alerts.push(`教室 ${name} : CO2濃度が高いため換気をしてください`);
                }
                if (lux > 100 && id === 2) { // 0-504が点灯中なら警告とする画像例の再現
                    alerts.push(`教室 ${name} : 在室0名で電源が点灯しています`);
                }

                totalCo2 += co2;
            }
        } catch (e) { console.error("Fetch error for room " + id, e); }
    }

    // サマリー更新
    document.getElementById('used-rooms-count').innerText = `${usedRooms} / 3`;
    document.getElementById('avg-co2').innerText = `${Math.round(totalCo2/3)} ppm`;
    document.getElementById('alert-count').innerText = `${alerts.length} 件`;

    // 警告リスト更新
    const alertListEl = document.getElementById('alert-list');
    if (alerts.length > 0) {
        alertListEl.innerHTML = alerts.map(a => `<li>${a}</li>`).join('');
    } else {
        alertListEl.innerHTML = '<li>現在、警告はありません。</li>';
    }
}

setInterval(updateTime, 1000);
setInterval(fetchLatestData, 5000); // 5秒ごとに最新データを取得
updateTime();
fetchLatestData();
</script>
</body>
</html>