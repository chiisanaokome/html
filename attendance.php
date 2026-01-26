<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>出席管理画面 - 学校スマート管理システム</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --main-blue: #007bff; --bg-gray: #f4f7f6; --absent-red: #ff5252; --present-green: #4caf50; }
        body { font-family: "Helvetica Neue", Arial, sans-serif; background-color: var(--bg-gray); margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1100px; margin: auto; background: white; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); overflow: hidden; }

        /* ヘッダーエリア */
        .header { background: #fff; padding: 20px; border-bottom: 2px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .header-title { font-size: 1.4em; font-weight: bold; color: var(--main-blue); }
        
        /* 選択エリア（教室・回数・時限） */
        .selector-group { display: flex; flex-wrap: wrap; gap: 15px; padding: 20px; background: #fafafa; border-bottom: 1px solid #eee; }
        .filter-item { display: flex; flex-direction: column; gap: 5px; }
        .filter-item label { font-size: 0.8em; color: #666; font-weight: bold; }
        .styled-select { padding: 10px; border-radius: 6px; border: 1px solid #ccc; font-size: 1em; background: white; cursor: pointer; font-weight: bold; min-width: 120px; }

        /* 集計バー */
        .summary-bar { display: flex; gap: 30px; padding: 15px 20px; font-size: 1.1em; font-weight: bold; border-bottom: 1px solid #eee; }
        .count-item.present { color: var(--present-green); }
        .count-item.absent { color: var(--absent-red); }

        /* 出席管理グリッド */
        .attendance-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); 
            gap: 15px; padding: 20px; background: #fff;
        }

        /* 学生管理カード */
        .student-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
            transition: 0.2s;
        }
        .name-info { display: flex; flex-direction: column; }
        .u-code { font-size: 0.75em; color: #888; }
        .u-name { font-size: 1.1em; font-weight: bold; }
        
        /* 状態表示ラベル */
        .status-indicator {
            padding: 8px 12px;
            border-radius: 6px;
            font-weight: bold;
            color: white;
            font-size: 0.9em;
            text-align: center;
            min-width: 60px;
        }
        .bg-present { background-color: var(--present-green); }
        .bg-absent { background-color: var(--absent-red); }

        /* フッター */
        .footer { padding: 20px; border-top: 1px solid #eee; display: flex; justify-content: space-between; }
        .btn-update { background: var(--main-blue); color: white; border: none; padding: 10px 25px; border-radius: 6px; cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="header-title"><i class="fas fa-users-cog"></i> リアルタイム出席管理</div>
        <div id="clock" style="font-weight: bold; color: #666;"></div>
    </div>

    <form id="manage-form" class="selector-group">
        <div class="filter-item">
            <label>対象教室</label>
            <select class="styled-select" name="room_id">
                <option value="1">0-502</option>
                <option value="2">0-504</option>
                <option value="3">0-506</option>
            </select>
        </div>
        <div class="filter-item">
            <label>講義回数</label>
            <select class="styled-select" name="lecture_count">
                <script>
                    for(let i=1; i<=15; i++) {
                        document.write(`<option value="${i}">第${i}回 講義</option>`);
                    }
                </script>
            </select>
        </div>
        <div class="filter-item">
            <label>時限</label>
            <select class="styled-select" name="period">
                <option value="1">1時限</option>
                <option value="2">2時限</option>
                <option value="3">3時限</option>
                <option value="4">4時限</option>
            </select>
        </div>
        <div class="filter-item" style="justify-content: flex-end;">
            <button type="button" class="btn-update" onclick="loadStatus()">表示更新</button>
        </div>
    </form>

    <div class="summary-bar">
        <span>本日の出席状況</span>
        <div class="count-item present">出席: <span id="p-count">18</span>名</div>
        <div class="count-item absent">欠席: <span id="a-count">2</span>名</div>
    </div>

    <div class="attendance-grid" id="student-list">
        <div class="student-card">
            <div class="name-info">
                <span class="u-code">S25001</span>
                <span class="u-name">田所 太郎</span>
            </div>
            <div class="status-indicator bg-present">出席</div>
        </div>
        <div class="student-card">
            <div class="name-info">
                <span class="u-code">S25002</span>
                <span class="u-name">小川 次郎</span>
            </div>
            <div class="status-indicator bg-absent">欠席</div>
        </div>
        </div>

    <div class="footer">
        <a href="home.php" style="text-decoration:none; color:var(--main-blue); font-weight:bold;"><i class="fas fa-home"></i> トップへ戻る</a>
        <span style="font-size: 0.85em; color: #999;">※学生がQRをスキャンすると自動的に出席へ切り替わります</span>
    </div>
</div>

<script>
    function updateClock() {
        const now = new Date();
        document.getElementById('clock').innerText = now.toLocaleTimeString();
    }
    setInterval(updateClock, 1000);
    updateClock();

    function loadStatus() {
        // ここでDBへfetchを行い、選択された教室・回数・時限に一致するデータを取得
        console.log("フィルタ条件でデータを再読み込みします");
    }
</script>
</body>
</html>